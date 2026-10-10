<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\FailoverableException;
use LogicException;

/**
 * Gemini を呼ぶときのモデルの順番。混雑・回数制限（503 / 429）で失敗したら、代わりのモデルを順に試す。
 * すべて失敗したら最後の例外を投げる（ジョブ側で時間をおいて再試行するか、失敗として記録する）。
 *
 * モデルは config/services.php の video_analyzer.gemini_model / gemini_fallback_models で決める。
 */
final class GeminiModels
{
    /**
     * @param  ?string  $model  最初に使うモデル（null なら SDK の既定）
     * @param  list<string>  $fallbackModels  混雑・回数制限のときに順に試すモデル
     */
    public function __construct(
        private readonly ?string $model = null,
        private readonly array $fallbackModels = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.video_analyzer.gemini_model') ?: null,
            config('services.video_analyzer.gemini_fallback_models', []),
        );
    }

    /**
     * モデルを順に試し、最初に成功した結果と、そのモデルを返す。
     *
     * @template T
     *
     * @param  callable(?string): T  $call  モデル名を受け取って Gemini を呼ぶ
     * @param  string  $purpose  ログに残す用途（例：分析、台本の候補）
     * @param  array<string, mixed>  $context  ログに残す識別情報
     * @return array{T, ?string}
     */
    public function attempt(callable $call, string $purpose, array $context = []): array
    {
        $models = collect([$this->model, ...$this->fallbackModels])->unique()->values();

        foreach ($models as $index => $model) {
            try {
                return [$call($model), $model];
            } catch (FailoverableException $e) {
                if ($index === $models->count() - 1) {
                    throw $e;
                }

                Log::warning("Gemini のモデルが応答しないため、次のモデルで{$purpose}を作ります。", [
                    ...$context,
                    'model' => $model ?? 'SDK の既定',
                    'next_model' => $models[$index + 1],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new LogicException("{$purpose}に使うモデルがありません。");
    }
}
