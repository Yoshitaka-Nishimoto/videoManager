<?php

namespace App\Services\Remotion;

use App\Models\ProductionPlan;
use App\Models\ProductionScene;

/**
 * 制作案と場面から、Remotion に渡す入力（input props）を作る。形は remotion/src/types.ts と同じ。
 *
 * 長さは秒のまま渡し、フレーム数への変換は Remotion 側（calculateMetadata）で行う。
 */
class RemotionInput
{
    /**
     * Production コンポジション用：再生する場面を順に並べる。
     *
     * @return array{settings: array<string, mixed>, scenes: list<array<string, mixed>>}
     */
    public function forPlan(ProductionPlan $plan): array
    {
        return [
            'settings' => $this->settings($plan),
            'scenes' => $plan->videoScenes->map(fn (ProductionScene $scene) => $this->scene($scene))->values()->all(),
        ];
    }

    /**
     * Scene コンポジション用：場面 1 つ。
     *
     * @return array{settings: array<string, mixed>, scene: array<string, mixed>}
     */
    public function forScene(ProductionScene $scene): array
    {
        return [
            'settings' => $this->settings($scene->plan),
            'scene' => $this->scene($scene),
        ];
    }

    /**
     * 書き出しの長さ（フレーム数）。Remotion 側の framesFor() と同じ計算にする。
     *
     * @param  array<string, mixed>  $input
     */
    public function durationInFrames(array $input): int
    {
        $fps = $input['settings']['fps'];
        $scenes = $input['scenes'] ?? [$input['scene']];

        return max(1, array_sum(array_map(fn (array $scene) => $this->frames($scene['duration_seconds'], $fps), $scenes)));
    }

    /**
     * 場面 1 つのフレーム数。最低 1 フレーム。
     */
    public function frames(float $seconds, int $fps): int
    {
        return max(1, (int) round($seconds * $fps));
    }

    /**
     * 幅・高さは制作の比率（video_productions.ratio）から決める。短い辺を設定の短い辺（1080）に合わせる。
     *
     * @return array<string, mixed>
     */
    private function settings(ProductionPlan $plan): array
    {
        [$ratioWidth, $ratioHeight] = array_map('intval', explode(':', $plan->production->ratio));
        $shortSide = min(config('remotion.width'), config('remotion.height'));
        $scale = $shortSide / min($ratioWidth, $ratioHeight);

        return [
            'width' => (int) round($ratioWidth * $scale),
            'height' => (int) round($ratioHeight * $scale),
            'fps' => (int) config('remotion.fps'),
            'style' => $plan->settings['style'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scene(ProductionScene $scene): array
    {
        return [
            'key' => $scene->scene_key,
            'scene_type' => $scene->scene_type,
            'title' => $scene->title,
            'duration_seconds' => (float) $scene->duration_seconds,
            'narration' => $scene->narration,
            'caption' => $scene->caption,
            'transition' => $scene->transition,
            'content' => $scene->content,
        ];
    }
}
