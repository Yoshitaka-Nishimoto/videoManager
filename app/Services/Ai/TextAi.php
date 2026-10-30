<?php

namespace App\Services\Ai;

use Laravel\Ai\Enums\Lab;

/**
 * 文章だけを渡す AI 処理（Web ページ・個人のファイルの分析、台本の候補、知識への質問）の AI とモデル。
 * Gemini のクレジットが尽きたときなどに、.env の TEXT_AI_PROVIDER=openai で OpenAI に切り替える。
 */
final class TextAi
{
    /**
     * @param  ?string  $model  最初に使うモデル（null なら SDK の既定）
     * @param  list<string>  $fallbackModels  混雑・回数制限のときに順に試すモデル
     */
    public function __construct(
        public readonly Lab $provider,
        public readonly ?string $model = null,
        public readonly array $fallbackModels = [],
    ) {}

    public static function fromConfig(): self
    {
        $provider = Lab::from(config('services.video_analyzer.text_provider') ?: Lab::Gemini->value);

        return $provider === Lab::Gemini
            ? new self($provider, config('services.video_analyzer.gemini_model') ?: null, config('services.video_analyzer.gemini_fallback_models', []))
            : new self($provider, config('services.video_analyzer.openai_model') ?: null);
    }

    public function models(): GeminiModels
    {
        return new GeminiModels($this->model, $this->fallbackModels);
    }
}
