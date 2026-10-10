<?php

namespace App\Actions\Productions;

use App\Models\ProductionPlan;
use App\Models\ProductionScene;
use App\Services\Script\NarrationTiming;
use Illuminate\Support\Facades\DB;

/**
 * 選んだ台本の候補を場面に入れる。ナレーション・字幕・画面の文字を書き換え、長さをナレーションに合わせる。
 *
 * グラフの数値は書き換えない（数値は人が入れる）。
 */
class ApplyScriptCandidate
{
    /**
     * @throws ScriptNotAllowedException
     */
    public function handle(ProductionPlan $plan, int $index): void
    {
        if (! $plan->isEditable()) {
            throw new ScriptNotAllowedException('台本を入れられるのは候補の版だけです。');
        }

        $candidate = $plan->script_candidates[$index] ?? throw new ScriptNotAllowedException('選んだ台本の候補が見つかりません。');
        $scripts = collect($candidate['scenes'] ?? [])->keyBy('key');

        DB::transaction(function () use ($plan, $index, $scripts) {
            foreach ($plan->videoScenes as $scene) {
                $script = $scripts->get($scene->scene_key);

                if ($script === null) {
                    continue;
                }

                $narration = $script['narration'] !== '' ? $script['narration'] : null;

                $scene->update([
                    'narration' => $narration,
                    'caption' => $script['caption'] !== '' ? $script['caption'] : null,
                    'content' => $this->content($scene, $script),
                    'duration_seconds' => NarrationTiming::seconds($narration, (float) $scene->duration_seconds),
                ]);
            }

            $plan->update(['script_selected' => $index]);
        });
    }

    /**
     * 場面の種類ごとに、画面の文字を候補で置き換える。候補が空の項目は今の内容のまま。
     *
     * @param  array<string, mixed>  $script
     * @return array<string, mixed>|null
     */
    private function content(ProductionScene $scene, array $script): ?array
    {
        $content = $scene->content ?? [];

        switch ($scene->scene_type) {
            case 'remotion.title':
                $title = $content['title'] ?? [];
                $title['heading'] = $script['heading'] !== '' ? $script['heading'] : ($title['heading'] ?? '');
                $title['subheading'] = $script['subheading'] !== '' ? $script['subheading'] : null;
                $content['title'] = $title;
                break;

            case 'remotion.text':
                $text = $content['text'] ?? [];
                $text['heading'] = $script['heading'] !== '' ? $script['heading'] : ($text['heading'] ?? null);
                $text['items'] = $script['items'] !== [] ? $script['items'] : ($text['items'] ?? []);
                $content['text'] = $text;
                break;

            case 'remotion.diagram':
                // 箱の数と並び（id・矢印）は変えず、名前だけを順に置き換える。
                $nodes = $content['diagram']['nodes'] ?? [];
                foreach ($nodes as $i => $node) {
                    if (($script['labels'][$i] ?? '') !== '') {
                        $nodes[$i]['label'] = $script['labels'][$i];
                    }
                }
                $content['diagram']['nodes'] = $nodes;
                break;
        }

        return $content;
    }
}
