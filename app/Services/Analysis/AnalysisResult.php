<?php

namespace App\Services\Analysis;

use App\Services\Web\WebPageContent;

final readonly class AnalysisResult
{
    /**
     * @param  list<string>  $topics  話題
     * @param  list<array{start_seconds: int|null, end_seconds: int|null, point: string, quote?: string|null}>  $keyPoints  要点。動画は該当時刻つき、Web ページは時刻の代わりに本文からの引用つき
     * @param  list<array{title: string, description: string, confidence: float, key_point: int|null}>  $concepts  抽出した概念（key_point は根拠となる要点の添字）
     * @param  ?WebPageContent  $source  分析に使った Web ページの本文（動画は空）
     */
    public function __construct(
        public string $summary,
        public array $topics,
        public array $keyPoints,
        public array $concepts,
        public string $model,
        public ?string $prompt = null,
        public ?WebPageContent $source = null,
    ) {}

    /**
     * video_analyses.content に保存する形。
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return [
            'topics' => $this->topics,
            'key_points' => $this->keyPoints,
            'concepts' => array_column($this->concepts, 'title'),
        ];
    }
}
