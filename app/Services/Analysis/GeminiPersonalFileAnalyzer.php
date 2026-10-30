<?php

namespace App\Services\Analysis;

use App\Ai\Agents\PersonalDataAnalyst;
use App\Models\Video;
use App\Services\Ai\GeminiModels;
use App\Services\Files\PersonalFileReader;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;

/**
 * 個人のファイル（血液検査の結果の CSV など）を文章にして Gemini に渡し、分析する。
 *
 * 既存の概念は、共有の概念と本人の概念だけを渡す（他の利用者の個人の概念は渡さない）。
 * 利用者の名前など、本人を特定する情報はプロンプトに入れない。
 * 文章にしたデータは AnalysisResult::$source で返し、分析の版ごとに保存する。
 * データは文章なので、Gemini 以外の AI（OpenAI など。services.video_analyzer.text_provider）でも分析できる。
 */
class GeminiPersonalFileAnalyzer implements VideoAnalyzer
{
    use NormalizesAnalysisOutput;

    /**
     * @param  ?string  $model  最初に使うモデル（null なら SDK の既定）
     * @param  list<string>  $fallbackModels  混雑・回数制限のときに順に試すモデル
     * @param  Lab  $provider  分析に使う AI
     */
    public function __construct(
        private readonly PersonalFileReader $files,
        private readonly ?string $model = null,
        private readonly array $fallbackModels = [],
        private readonly Lab $provider = Lab::Gemini,
    ) {}

    public function analyze(Video $video, callable $reportProgress): AnalysisResult
    {
        if (! $video->isFile()) {
            throw new InvalidArgumentException('個人のファイルの分析で扱えるのは、ファイルの情報源だけです。');
        }

        $content = $this->files->read($video);
        $reportProgress(15);

        $header = implode("\n", [
            "ファイル名：{$video->title}",
            'ジャンル：'.($video->genreLabel() ?? '未設定'),
            $this->existingConceptsLine($video),
            '',
            'このデータを分析してください。',
        ]);

        /** @var AgentResponse $response */
        [$response, $model] = (new GeminiModels($this->model, $this->fallbackModels))->attempt(
            fn (?string $model) => (new PersonalDataAnalyst)->prompt(
                $header."\n\nデータ：\n<<<\n{$content->text}\n>>>",
                provider: $this->provider,
                model: $model,
            ),
            purpose: '分析',
            context: ['video_id' => $video->id],
        );

        $reportProgress(90);

        $output = $response->toArray();
        $keyPoints = $this->quotedKeyPoints($output, $content->text);

        // 保存するプロンプトにはデータを含めない（データは source_text に別に残す）。
        return new AnalysisResult(
            summary: trim($output['summary'] ?? ''),
            topics: $this->normalizeTopics($output),
            keyPoints: $keyPoints,
            concepts: $this->normalizeConcepts($output, $keyPoints),
            model: $response->meta->model ?? $model ?? $this->provider->value,
            prompt: $header."\n\nデータ：（分析の source_text を参照）",
            source: $content,
        );
    }
}
