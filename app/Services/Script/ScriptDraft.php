<?php

namespace App\Services\Script;

/**
 * 台本の候補（通常 3 案）。production_plans.script_candidates に保存する。
 *
 * 1 案の形：
 *   [
 *     'label' => '案の名前', 'summary' => '案の概要',
 *     'scenes' => [
 *       ['key' => 's1', 'narration' => 'ナレーション', 'caption' => '字幕',
 *        'heading' => '見出し', 'subheading' => '副題', 'items' => ['箇条書き'], 'labels' => ['図の箱の名前']],
 *     ],
 *   ]
 */
final readonly class ScriptDraft
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>|null  $raw  AI の応答そのまま（調査用）
     */
    public function __construct(
        public array $candidates,
        public string $model,
        public ?string $prompt = null,
        public ?array $raw = null,
    ) {}
}
