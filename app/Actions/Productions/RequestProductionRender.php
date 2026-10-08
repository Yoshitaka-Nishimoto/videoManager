<?php

namespace App\Actions\Productions;

use App\Jobs\RenderProduction;
use App\Models\ProductionPlan;
use App\Models\ProductionRender;
use App\Models\ProductionScene;
use App\Models\User;
use App\Services\Remotion\RemotionInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Remotion の書き出しを依頼する。production_renders に行を作り、書き出しジョブをキューに入れる。
 *
 * 書き出しをやり直すときも新しい行を作る（同じ入力なら同じ結果になるため、前の記録は残す）。
 */
class RequestProductionRender
{
    public function __construct(private readonly RemotionInput $input) {}

    /**
     * @throws RenderNotAllowedException
     */
    public function handle(ProductionPlan $plan, string $kind, User $user, ?ProductionScene $scene = null): ProductionRender
    {
        $settings = config("remotion.kinds.{$kind}") ?? throw new RenderNotAllowedException("書き出しの種類 [{$kind}] はありません。");

        $input = $kind === ProductionRender::KIND_STILL
            ? $this->inputForStill($plan, $scene)
            : $this->inputForVideo($plan, $kind);

        $durationInFrames = $this->input->durationInFrames($input);

        $render = DB::transaction(fn () => $plan->renders()->create([
            'video_production_id' => $plan->video_production_id,
            'production_scene_id' => $scene?->id,
            'kind' => $kind,
            'composition_id' => ProductionRender::compositionFor($kind),
            'input_props' => $input,
            'code_version' => $this->codeVersion(),
            'codec' => $settings['codec'] ?? null,
            'image_format' => $settings['image_format'] ?? null,
            'width' => $input['settings']['width'],
            'height' => $input['settings']['height'],
            'fps' => $input['settings']['fps'],
            'duration_in_frames' => $durationInFrames,
            // 静止画は場面の中ほどのフレームにする。
            'frame' => $kind === ProductionRender::KIND_STILL ? intdiv($durationInFrames, 2) : null,
            'scale' => $settings['scale'] ?? 1,
            'crf' => $settings['crf'] ?? null,
            'status' => ProductionRender::STATUS_QUEUED,
            'requested_by' => $user->id,
        ]));

        RenderProduction::dispatch($render)->afterCommit();

        return $render;
    }

    /**
     * @return array<string, mixed>
     */
    private function inputForStill(ProductionPlan $plan, ?ProductionScene $scene): array
    {
        if ($scene === null || $scene->production_plan_id !== $plan->id) {
            throw new RenderNotAllowedException('静止画を書き出す場面を、この制作案の中から指定してください。');
        }

        if ($scene->track !== ProductionScene::TRACK_VIDEO || $scene->isRunway()) {
            throw new RenderNotAllowedException('静止画を書き出せるのは、Remotion で作る再生する場面だけです。');
        }

        return $this->input->forScene($scene);
    }

    /**
     * @return array<string, mixed>
     */
    private function inputForVideo(ProductionPlan $plan, string $kind): array
    {
        if ($kind === ProductionRender::KIND_FINAL && $plan->status !== ProductionPlan::STATUS_CONFIRMED) {
            throw new RenderNotAllowedException('完成版を書き出せるのは、確認済みの制作案だけです。');
        }

        $scenes = $plan->videoScenes;

        if ($scenes->isEmpty()) {
            throw new RenderNotAllowedException('再生する場面がありません。');
        }

        // Runway の生成物を使う場面は、生成物を選んで ready になるまで書き出せない。
        $notReady = $scenes->filter(fn (ProductionScene $scene) => $scene->isRunway() && $scene->status !== ProductionScene::STATUS_READY);

        if ($notReady->isNotEmpty()) {
            throw new RenderNotAllowedException('Runway の生成物が選ばれていない場面があります：'.$notReady->pluck('scene_key')->implode('、'));
        }

        return $this->input->forPlan($plan);
    }

    /**
     * Remotion のコードの版（Git のコミット ID）。取れなければ null。
     * remotion/ に未コミットの変更があるときは、コミット ID だけでは作り直せないため末尾に -dirty を付ける。
     */
    private function codeVersion(): ?string
    {
        $head = Process::path(base_path())->run(['git', 'rev-parse', 'HEAD']);

        if (! $head->successful()) {
            return null;
        }

        $changes = Process::path(base_path())->run(['git', 'status', '--porcelain', '--', 'remotion']);

        return trim($head->output()).(trim($changes->output()) !== '' ? '-dirty' : '');
    }
}
