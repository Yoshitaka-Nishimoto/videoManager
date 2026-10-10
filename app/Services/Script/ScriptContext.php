<?php

namespace App\Services\Script;

use App\Models\ProductionPlan;
use App\Models\ProductionScene;
use App\Services\Productions\ProductionTemplate;

/**
 * 台本の候補作りに渡す材料（制作の意図と場面の並び）と、AI の出力を決まった形に整える処理。
 */
final class ScriptContext
{
    /** 箇条書きの項目の上限（scene_content.md）。 */
    private const MAX_ITEMS = 6;

    /**
     * @return array<string, mixed>
     */
    public static function for(ProductionPlan $plan): array
    {
        $production = $plan->production;
        $settings = $plan->settings ?? [];

        return [
            'title' => $production->title,
            'genre' => $production->genreLabel(),
            'audience' => ProductionTemplate::AUDIENCES[$settings['audience'] ?? ''] ?? null,
            'tone' => $settings['style']['tone'] ?? null,
            'target_seconds' => $production->target_duration_seconds,
            'brief' => $production->brief,
            'decision' => [
                'title' => $production->decisionNode?->title,
                'description' => $production->decisionNode?->description,
            ],
            'scenes' => $plan->videoScenes->map(fn (ProductionScene $scene) => [
                'key' => $scene->scene_key,
                'scene_type' => $scene->scene_type,
                'title' => $scene->title,
                'seconds' => (float) $scene->duration_seconds,
                'narration_characters' => NarrationTiming::characterBudget((float) $scene->duration_seconds),
                'diagram_boxes' => $scene->scene_type === 'remotion.diagram' ? count($scene->content['diagram']['nodes'] ?? []) : null,
            ])->values()->all(),
        ];
    }

    /**
     * AI の出力を、制作案の場面の並びに合わせて整える。知らない場面のキーは捨て、足りない場面は空で埋める。
     *
     * @param  list<mixed>  $candidates
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $candidates, ProductionPlan $plan): array
    {
        $keys = $plan->videoScenes->pluck('scene_key')->all();

        return collect($candidates)
            ->filter(fn ($candidate) => is_array($candidate))
            ->values()
            ->map(function (array $candidate, int $index) use ($keys) {
                $scenes = collect($candidate['scenes'] ?? [])->filter(fn ($scene) => is_array($scene))->keyBy(fn (array $scene) => (string) ($scene['key'] ?? ''));

                return [
                    'label' => mb_substr(trim((string) ($candidate['label'] ?? '')), 0, 40) ?: '案'.($index + 1),
                    'summary' => trim((string) ($candidate['summary'] ?? '')),
                    'scenes' => array_map(fn (string $key) => self::scene($key, $scenes->get($key, [])), $keys),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    private static function scene(string $key, array $scene): array
    {
        $text = fn (string $field, int $limit) => mb_substr(trim((string) ($scene[$field] ?? '')), 0, $limit);
        $list = fn (string $field, int $limit, int $max) => collect($scene[$field] ?? [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => mb_substr(trim($value), 0, $limit))
            ->take($max)
            ->values()
            ->all();

        return [
            'key' => $key,
            'narration' => trim((string) ($scene['narration'] ?? '')),
            'caption' => trim((string) ($scene['caption'] ?? '')),
            'heading' => $text('heading', 30),
            'subheading' => $text('subheading', 40),
            'items' => $list('items', 40, self::MAX_ITEMS),
            'labels' => $list('labels', 15, 8),
        ];
    }
}
