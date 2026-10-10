<?php

namespace App\Actions\Productions;

use App\Models\ProductionPlan;
use App\Services\Script\NarrationTiming;
use Illuminate\Support\Facades\DB;

/**
 * 人が直したナレーションと字幕を場面に保存し、場面の長さをナレーションに合わせる。
 */
class SaveSceneScripts
{
    /**
     * @param  array<int|string, array{narration?: ?string, caption?: ?string}>  $scripts  場面ID => 文
     *
     * @throws ScriptNotAllowedException
     */
    public function handle(ProductionPlan $plan, array $scripts): void
    {
        if (! $plan->isEditable()) {
            throw new ScriptNotAllowedException('台本を直せるのは候補の版だけです。確認済みの版を直すときは、新しい版を作ります。');
        }

        DB::transaction(function () use ($plan, $scripts) {
            // この制作案の場面だけを対象にする（他の制作案の場面IDが混じっても書き換えない）。
            foreach ($plan->videoScenes as $scene) {
                if (! array_key_exists($scene->id, $scripts)) {
                    continue;
                }

                $narration = trim((string) ($scripts[$scene->id]['narration'] ?? '')) ?: null;
                $caption = trim((string) ($scripts[$scene->id]['caption'] ?? '')) ?: null;

                $scene->update([
                    'narration' => $narration,
                    'caption' => $caption,
                    'duration_seconds' => NarrationTiming::seconds($narration, (float) $scene->duration_seconds),
                ]);
            }
        });
    }
}
