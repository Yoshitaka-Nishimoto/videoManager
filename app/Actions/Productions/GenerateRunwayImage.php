<?php

namespace App\Actions\Productions;

use App\Models\AiUsageLog;
use App\Models\ProductionAsset;
use App\Models\RunwayTask;
use App\Models\RunwayTaskInput;
use App\Models\RunwayTaskOutput;
use App\Models\User;
use App\Models\VideoProduction;
use App\Services\AiUsage\AiUsageRecorder;
use App\Services\Runway\PersonImagePrompt;
use App\Services\Runway\RunwayClient;
use App\Services\Runway\RunwayException;
use finfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * 参照画像（人物のサンプル画像）と指示文から Runway で画像を作り、制作の素材として登録する。
 *
 * 依頼・入力・出力は runway_tasks / runway_task_inputs / runway_task_outputs に記録する（public_docs/runway_tables.md）。
 * 生成物は runway/{依頼ID}/ に保存し、素材フォルダーにも複製して production_assets に登録する
 * （素材を削除しても、生成の記録のファイルは残す）。
 */
class GenerateRunwayImage
{
    public const MODELS = ['gen4_image', 'gen4_image_turbo'];

    /** Runway が受け付ける比率。 */
    public const RATIOS = [
        '1024:1024', '1080:1080', '1168:880', '1360:768', '1440:1080', '1080:1440', '1808:768', '1920:1080',
        '1080:1920', '2112:912', '1280:720', '720:1280', '720:720', '960:720', '720:960', '1680:720',
    ];

    /** 720p の比率（gen4_image はこれらが 5 クレジット、ほかは 8 クレジット）。 */
    private const RATIOS_720P = ['1280:720', '720:1280', '720:720', '960:720', '720:960', '1680:720'];

    /** 制作の比率（VideoProduction::RATIOS）に対応する、画像の比率（1080p）。 */
    private const RATIO_FOR_PRODUCTION = ['1280:720' => '1920:1080', '720:1280' => '1080:1920', '960:960' => '1080:1080'];

    /** 1 クレジットの米ドル。 */
    private const DOLLARS_PER_CREDIT = 0.01;

    /** Data URI で渡せる大きさ（base64 にすると約 4/3 倍になるため、Runway の上限 5MB から逆算）。 */
    private const MAX_REFERENCE_BYTES = 3_700_000;

    private const REFERENCE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(
        private readonly RunwayClient $runway,
        private readonly AiUsageRecorder $usage,
    ) {}

    public static function defaultRatioFor(VideoProduction $production): string
    {
        return self::RATIO_FOR_PRODUCTION[$production->ratio] ?? '1920:1080';
    }

    public static function estimatedCredits(string $model, string $ratio): int
    {
        if ($model === 'gen4_image_turbo') {
            return 2;
        }

        return in_array($ratio, self::RATIOS_720P, true) ? 5 : 8;
    }

    /**
     * @param  string  $referencePath  参照画像のパス（ProductionAsset::DISK の中）
     * @param  array<string, mixed>  $options  記録用の指定（写り方・背景・絵柄など）
     * @param  (callable(string): void)|null  $onProgress  進み具合の知らせ（コマンドの表示用）
     * @return Collection<int, ProductionAsset>
     */
    public function handle(
        VideoProduction $production,
        string $referencePath,
        string $prompt,
        string $ratio,
        string $model = 'gen4_image',
        ?int $seed = null,
        ?string $title = null,
        array $options = [],
        ?User $user = null,
        ?callable $onProgress = null,
    ): Collection {
        $onProgress ??= fn (string $message) => null;
        $this->validate($prompt, $ratio, $model);
        $dataUri = $this->referenceDataUri($referencePath);

        $referenceAsset = ProductionAsset::query()
            ->where('video_production_id', $production->id)
            ->where('storage_path', $referencePath)
            ->first();

        $payload = array_filter([
            'model' => $model,
            'promptText' => $prompt,
            'ratio' => $ratio,
            'referenceImages' => [['uri' => $dataUri, 'tag' => PersonImagePrompt::TAG]],
            'seed' => $seed,
        ], fn ($value) => $value !== null);

        $task = RunwayTask::query()->create([
            'task_type' => RunwayTask::TYPE_IMAGE,
            'model' => $model,
            'prompt_text' => $prompt,
            'ratio' => $ratio,
            'seed' => $seed,
            'options' => [...$options, 'video_production_id' => $production->id],
            // 画像の中身（base64）は記録せず、どのファイルを渡したかだけを残す。
            'request_payload' => [...$payload, 'referenceImages' => [['uri' => "(data URI: {$referencePath})", 'tag' => PersonImagePrompt::TAG]]],
            'status' => RunwayTask::STATUS_QUEUED,
            'progress' => 0,
            'estimated_cost' => self::estimatedCredits($model, $ratio) * self::DOLLARS_PER_CREDIT,
            'requested_by' => $user?->id,
        ]);

        $task->inputs()->create([
            'role' => RunwayTaskInput::ROLE_REFERENCE_IMAGE,
            'position' => 0,
            'media_type' => RunwayTaskOutput::MEDIA_IMAGE,
            'storage_path' => $referencePath,
            'production_asset_id' => $referenceAsset?->id,
        ]);

        $result = null;

        try {
            $runwayTaskId = $this->runway->textToImage($payload);
            $task->update([
                'runway_task_id' => $runwayTaskId,
                'runway_status' => 'PENDING',
                'status' => RunwayTask::STATUS_RUNNING,
                'submitted_at' => now(),
            ]);
            $onProgress("Runway に依頼しました（タスクID：{$runwayTaskId}）。");

            $result = $this->waitForCompletion($task, $onProgress);
            $assets = $this->storeOutputs($task, $production, $result['output'] ?? [], $title, $user);

            $task->update(['status' => RunwayTask::STATUS_COMPLETED, 'progress' => 100, 'completed_at' => now()]);
            $this->recordUsage($task, $result, succeeded: true);

            return $assets;
        } catch (Throwable $e) {
            $task->update([
                'status' => RunwayTask::STATUS_FAILED,
                'failure_code' => $task->failure_code ?? 'APP.'.class_basename($e),
                'failure_message' => $task->failure_message ?? $e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->recordUsage($task, $result, succeeded: false, error: $e->getMessage());

            throw $e instanceof RunwayException ? $e : new RunwayException($e->getMessage(), previous: $e);
        }
    }

    private function validate(string $prompt, string $ratio, string $model): void
    {
        if (! in_array($model, self::MODELS, true)) {
            throw new InvalidArgumentException("モデル「{$model}」は使えません（".implode(' / ', self::MODELS).'）。');
        }

        if (! in_array($ratio, self::RATIOS, true)) {
            throw new InvalidArgumentException("比率「{$ratio}」は使えません（".implode(' / ', self::RATIOS).'）。');
        }

        if (trim($prompt) === '' || mb_strlen($prompt) > PersonImagePrompt::MAX_LENGTH) {
            throw new InvalidArgumentException('指示文は 1〜'.PersonImagePrompt::MAX_LENGTH.' 文字にしてください。');
        }
    }

    private function referenceDataUri(string $path): string
    {
        $disk = Storage::disk(ProductionAsset::DISK);

        if (! $disk->exists($path)) {
            throw new InvalidArgumentException("参照画像が見つかりません：{$path}");
        }

        $bytes = $disk->get($path);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! in_array($mime, self::REFERENCE_MIME_TYPES, true)) {
            throw new InvalidArgumentException("参照画像は JPEG・PNG・WebP にしてください（{$mime}）。");
        }

        if (strlen($bytes) > self::MAX_REFERENCE_BYTES) {
            throw new InvalidArgumentException('参照画像が大きすぎます（'.round(strlen($bytes) / 1_000_000, 1).'MB）。3.7MB 以下に縮小してください。');
        }

        return "data:{$mime};base64,".base64_encode($bytes);
    }

    /**
     * 終わるまで状態を確かめる。成功したらタスクの内容を返し、失敗・取り消し・時間切れなら例外を投げる。
     *
     * @param  callable(string): void  $onProgress
     * @return array<string, mixed>
     */
    private function waitForCompletion(RunwayTask $task, callable $onProgress): array
    {
        $deadline = now()->addSeconds((int) config('services.runway.wait_seconds'));

        while (true) {
            Sleep::for((int) config('services.runway.poll_seconds'))->seconds();

            $result = $this->runway->task($task->runway_task_id);
            $status = (string) ($result['status'] ?? '');
            $progress = isset($result['progress']) ? (int) round((float) $result['progress'] * 100) : $task->progress;

            $task->update(['runway_status' => $status, 'progress' => min(99, $progress)]);
            $onProgress("状態：{$status}".($status === 'RUNNING' ? "（{$progress}%）" : ''));

            if ($status === 'SUCCEEDED') {
                return $result;
            }

            if ($status === 'FAILED' || $status === 'CANCELLED') {
                $task->update([
                    'failure_code' => $result['failureCode'] ?? $status,
                    'failure_message' => $result['failure'] ?? null,
                ]);

                throw new RunwayException('Runway での生成が失敗しました：'.($result['failure'] ?? $status).(isset($result['failureCode']) ? "（{$result['failureCode']}）" : ''));
            }

            if (now()->greaterThan($deadline)) {
                $task->update(['failure_code' => 'APP.TIMEOUT']);

                throw new RunwayException("時間内に生成が終わりませんでした（Runway のタスクID：{$task->runway_task_id}）。");
            }
        }
    }

    /**
     * @param  array<int, string>  $urls
     * @return Collection<int, ProductionAsset>
     */
    private function storeOutputs(RunwayTask $task, VideoProduction $production, array $urls, ?string $title, ?User $user): Collection
    {
        if ($urls === []) {
            throw new RunwayException('Runway から生成物が返りませんでした。');
        }

        $disk = Storage::disk(ProductionAsset::DISK);
        $assets = collect();

        foreach (array_values($urls) as $position => $url) {
            $bytes = $this->runway->download($url);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $extension = self::EXTENSIONS[$mime] ?? 'png';
            $size = @getimagesizefromstring($bytes) ?: [null, null];

            $outputPath = "runway/{$task->id}/{$position}.{$extension}";
            $disk->put($outputPath, $bytes);

            $task->outputs()->create([
                'position' => $position,
                'media_type' => RunwayTaskOutput::MEDIA_IMAGE,
                'storage_path' => $outputPath,
                'mime_type' => $mime,
                'size_bytes' => strlen($bytes),
                'width' => $size[0],
                'height' => $size[1],
            ]);

            $assetPath = ProductionAsset::directoryFor($production).'/'.Str::ulid().'.'.$extension;
            $disk->put($assetPath, $bytes);

            $assets->push($production->assets()->create([
                'kind' => ProductionAsset::KIND_IMAGE,
                'title' => ($title ?: "Runway 生成 #{$task->id}").(count($urls) > 1 ? ' ('.($position + 1).')' : ''),
                'storage_path' => $assetPath,
                'original_name' => "runway-{$task->id}-{$position}.{$extension}",
                'mime_type' => $mime,
                'uploaded_by' => $user?->id,
            ]));
        }

        return $assets;
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function recordUsage(RunwayTask $task, ?array $result, bool $succeeded, ?string $error = null): void
    {
        // 終わったタスクは実際のクレジット（cost）が返る。送る前に失敗したときは費用がかからない。
        $credits = $result['cost']['credits'] ?? null;
        $cost = $credits !== null
            ? $credits * self::DOLLARS_PER_CREDIT
            : ($task->runway_task_id !== null ? (float) $task->estimated_cost : 0.0);

        $this->usage->within(AiUsageLog::FEATURE_GENERATION, $task, fn () => $this->usage->record(
            provider: 'runway',
            model: $task->model,
            inputTokens: 0,
            outputTokens: 0,
            succeeded: $succeeded,
            errorMessage: $error,
            estimatedCost: $cost,
        ));
    }
}
