<?php

namespace App\Services\Analysis;

use App\Ai\Agents\VideoAnalyst;
use App\Models\KnowledgeNode;
use App\Models\Video;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Video as VideoFile;

/**
 * YouTube の URL を Gemini に動画として渡し、内容を分析する。
 *
 * AI 使用量は Laravel AI SDK のイベント経由で自動的に記録される（RecordAgentUsage）。
 */
class GeminiVideoAnalyzer implements VideoAnalyzer
{
    /** プロンプトに含める既存の概念の最大数。 */
    private const EXISTING_CONCEPTS_LIMIT = 100;

    public function __construct(private readonly ?string $model = null) {}

    public function analyze(Video $video, callable $reportProgress): AnalysisResult
    {
        if ($video->youtube_id === null) {
            throw new InvalidArgumentException('Gemini で分析できるのは YouTube の動画だけです。');
        }

        $prompt = $this->prompt($video);
        $reportProgress(15);

        $response = (new VideoAnalyst)->prompt(
            $prompt,
            attachments: [VideoFile::fromUrl('https://www.youtube.com/watch?v='.$video->youtube_id)],
            provider: Lab::Gemini,
            model: $this->model,
        );

        $reportProgress(90);

        return $this->toResult($response->toArray(), $video, $response->meta->model ?? $this->model ?? 'gemini', $prompt);
    }

    private function prompt(Video $video): string
    {
        $existing = KnowledgeNode::query()
            ->where('node_type', KnowledgeNode::TYPE_CONCEPT)
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->orderByRaw("case status when 'confirmed' then 0 else 1 end")
            ->latest('id')
            ->limit(self::EXISTING_CONCEPTS_LIMIT)
            ->pluck('title');

        return implode("\n", [
            "動画タイトル：{$video->title}",
            '動画の長さ：'.($video->duration_seconds !== null ? "{$video->duration_seconds}秒" : '不明'),
            '既存の概念：'.($existing->isEmpty() ? 'なし' : $existing->implode('、')),
            '',
            'この動画を分析してください。',
        ]);
    }

    /**
     * AI の回答を検証し、範囲外の時刻や番号を補正して AnalysisResult にする。
     *
     * @param  array<string, mixed>  $output
     */
    private function toResult(array $output, Video $video, string $model, string $prompt): AnalysisResult
    {
        $duration = $video->duration_seconds;
        $clamp = fn (mixed $seconds): int => max(0, min((int) $seconds, $duration ?? PHP_INT_MAX));

        $keyPoints = collect($output['key_points'] ?? [])
            ->filter(fn ($point) => is_array($point) && filled($point['point'] ?? null))
            ->map(function (array $point) use ($clamp) {
                $start = $clamp($point['start_seconds'] ?? 0);

                return [
                    'start_seconds' => $start,
                    'end_seconds' => max($start, $clamp($point['end_seconds'] ?? $start)),
                    'point' => trim($point['point']),
                ];
            })
            ->values();

        $concepts = collect($output['concepts'] ?? [])
            ->filter(fn ($concept) => is_array($concept) && filled($concept['title'] ?? null))
            ->unique(fn (array $concept) => trim($concept['title']))
            ->map(fn (array $concept) => [
                'title' => Str::limit(trim($concept['title']), 250, ''),
                'description' => trim($concept['description'] ?? ''),
                'confidence' => round(max(0, min(1, (float) ($concept['confidence'] ?? 0.5))), 3),
                'key_point' => isset($keyPoints[$concept['key_point'] ?? -1]) ? (int) $concept['key_point'] : null,
            ])
            ->values();

        return new AnalysisResult(
            summary: trim($output['summary'] ?? ''),
            topics: collect($output['topics'] ?? [])->filter(fn ($topic) => is_string($topic) && filled($topic))->map(fn ($topic) => trim($topic))->values()->all(),
            keyPoints: $keyPoints->all(),
            concepts: $concepts->all(),
            model: $model,
            prompt: $prompt,
        );
    }
}
