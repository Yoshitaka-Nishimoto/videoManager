<?php

namespace App\Services\Analysis;

final readonly class AnalysisResult
{
    /**
     * @param  list<string>  $topics  話題
     * @param  list<array{start_seconds: int, end_seconds: int, point: string}>  $keyPoints  動画の該当時刻つきの要点
     * @param  list<array{title: string, description: string, confidence: float, key_point: int|null}>  $concepts  抽出した概念（key_point は根拠となる要点の添字）
     */
    public function __construct(
        public string $summary,
        public array $topics,
        public array $keyPoints,
        public array $concepts,
        public string $model,
        public ?string $prompt = null,
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
