<?php

namespace App\Services\Analysis;

use App\Ai\Agents\VideoAnalyst;
use App\Models\Video;
use App\Services\Ai\GeminiModels;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Video as VideoFile;
use Laravel\Ai\Responses\AgentResponse;

/**
 * YouTube の URL を Gemini に動画として渡し、内容を分析する。
 *
 * モデルが混雑・回数制限で応答しないときは、代わりのモデルを順に試す。
 * AI 使用量は Laravel AI SDK のイベント経由で自動的に記録される（RecordAgentUsage）。
 */
class GeminiVideoAnalyzer implements VideoAnalyzer
{
    use NormalizesAnalysisOutput;

    /**
     * @param  ?string  $model  最初に使うモデル（null なら SDK の既定）
     * @param  list<string>  $fallbackModels  混雑・回数制限のときに順に試すモデル
     */
    public function __construct(
        private readonly ?string $model = null,
        private readonly array $fallbackModels = [],
    ) {}

    public function analyze(Video $video, callable $reportProgress): AnalysisResult
    {
        if ($video->youtube_id === null) {
            throw new InvalidArgumentException('Gemini で分析できるのは YouTube の動画だけです。');
        }

        $prompt = $this->prompt($video);
        $reportProgress(15);

        /** @var AgentResponse $response */
        [$response, $model] = (new GeminiModels($this->model, $this->fallbackModels))->attempt(
            fn (?string $model) => (new VideoAnalyst)->prompt(
                $prompt,
                attachments: [VideoFile::fromUrl('https://www.youtube.com/watch?v='.$video->youtube_id)],
                provider: Lab::Gemini,
                model: $model,
            ),
            purpose: '分析',
            context: ['video_id' => $video->id],
        );

        $reportProgress(90);

        return $this->toResult($response->toArray(), $video, $response->meta->model ?? $model ?? 'gemini', $prompt);
    }

    private function prompt(Video $video): string
    {
        return implode("\n", [
            "動画タイトル：{$video->title}",
            '動画の長さ：'.($video->duration_seconds !== null ? "{$video->duration_seconds}秒" : '不明'),
            $this->existingConceptsLine($video),
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
            ->values()
            ->all();

        return new AnalysisResult(
            summary: trim($output['summary'] ?? ''),
            topics: $this->normalizeTopics($output),
            keyPoints: $keyPoints,
            concepts: $this->normalizeConcepts($output, $keyPoints),
            model: $model,
            prompt: $prompt,
        );
    }
}
