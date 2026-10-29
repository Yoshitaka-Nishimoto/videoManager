<?php

namespace App\Services\Analysis;

use App\Ai\Agents\WebPageAnalyst;
use App\Models\Video;
use App\Services\Ai\GeminiModels;
use App\Services\Web\WebPageClient;
use App\Services\Web\WebPageContent;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Web ページの本文をこちらで取得して Gemini に渡し、内容を分析する。
 *
 * 取得した本文は AnalysisResult::$source で返し、分析の版ごとに保存する（何を読んで分析したかを確かめられるように）。
 * モデルの切り替えと AI 使用量の記録は GeminiVideoAnalyzer と同じ。
 */
class GeminiWebPageAnalyzer implements VideoAnalyzer
{
    use NormalizesAnalysisOutput;

    /**
     * @param  ?string  $model  最初に使うモデル（null なら SDK の既定）
     * @param  list<string>  $fallbackModels  混雑・回数制限のときに順に試すモデル
     */
    public function __construct(
        private readonly WebPageClient $web,
        private readonly ?string $model = null,
        private readonly array $fallbackModels = [],
    ) {}

    public function analyze(Video $video, callable $reportProgress): AnalysisResult
    {
        if (! $video->isWebPage() || $video->url === null) {
            throw new InvalidArgumentException('Web ページの分析で扱えるのは、Web ページの情報源だけです。');
        }

        $content = $this->web->content($video->url);
        $reportProgress(15);

        $header = $this->promptHeader($video, $content);

        /** @var AgentResponse $response */
        [$response, $model] = (new GeminiModels($this->model, $this->fallbackModels))->attempt(
            fn (?string $model) => (new WebPageAnalyst)->prompt(
                $header."\n\n本文：\n<<<\n{$content->text}\n>>>",
                provider: Lab::Gemini,
                model: $model,
            ),
            purpose: '分析',
            context: ['video_id' => $video->id],
        );

        $reportProgress(90);

        // 保存するプロンプトには本文を含めない（本文は source_text に別に残す）。
        return $this->toResult(
            $response->toArray(),
            $content,
            $response->meta->model ?? $model ?? 'gemini',
            $header."\n\n本文：（分析の source_text を参照）",
        );
    }

    private function promptHeader(Video $video, WebPageContent $content): string
    {
        return implode("\n", [
            "ページの題名：{$video->title}",
            'サイト：'.($video->channel_title ?? '不明'),
            "URL：{$video->url}",
            ...($content->sourceUrl !== $video->url ? ["本文の取得元：{$content->sourceUrl}"] : []),
            ...($content->truncated ? ['注意：本文が長いため、先頭の '.number_format(WebPageClient::MAX_TEXT_LENGTH).' 文字だけを渡しています。'] : []),
            $this->existingConceptsLine(),
            '',
            'このページを分析してください。',
        ]);
    }

    /**
     * AI の回答を検証して AnalysisResult にする。本文に見つからない引用は、根拠に戻れないので空にする。
     *
     * @param  array<string, mixed>  $output
     */
    private function toResult(array $output, WebPageContent $content, string $model, string $prompt): AnalysisResult
    {
        $flatText = $this->flatten($content->text);

        $keyPoints = collect($output['key_points'] ?? [])
            ->filter(fn ($point) => is_array($point) && filled($point['point'] ?? null))
            ->map(function (array $point) use ($flatText) {
                $quote = trim((string) ($point['quote'] ?? ''));

                return [
                    'start_seconds' => null,
                    'end_seconds' => null,
                    'point' => trim($point['point']),
                    'quote' => $quote !== '' && str_contains($flatText, $this->flatten($quote)) ? $quote : null,
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
            source: $content,
        );
    }

    /**
     * 空白の違いで引用の照合が外れないよう、空白を詰める。
     */
    private function flatten(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
