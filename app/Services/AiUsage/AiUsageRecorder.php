<?php

namespace App\Services\AiUsage;

use App\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * AI の呼び出しごとに、トークン数・推定費用・成否を ai_usage_logs に記録する。
 *
 * 機能名と呼び出し元は within() で囲んだ範囲から自動で引き継ぐ。囲まれていなければ、呼び出し側が渡した機能名を使う。
 */
class AiUsageRecorder
{
    private const CONTEXT_KEY = 'ai_usage';

    /**
     * 指定した機能・呼び出し元として、範囲内の AI 呼び出しを記録する。
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function within(string $feature, ?Model $usable, callable $callback): mixed
    {
        return Context::scope($callback, hidden: [self::CONTEXT_KEY => [
            'feature' => $feature,
            'usable_type' => $usable?->getMorphClass(),
            'usable_id' => $usable?->getKey(),
        ]]);
    }

    public function record(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        bool $succeeded = true,
        ?string $errorMessage = null,
        string $defaultFeature = 'other',
    ): AiUsageLog {
        $scope = Context::getHidden(self::CONTEXT_KEY, []);

        return AiUsageLog::query()->create([
            'provider' => $provider,
            'model' => $model,
            'feature' => $scope['feature'] ?? $defaultFeature,
            'usable_type' => $scope['usable_type'] ?? null,
            'usable_id' => $scope['usable_id'] ?? null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost' => $this->estimateCost($model, $inputTokens, $outputTokens),
            'succeeded' => $succeeded,
            'error_message' => $errorMessage,
        ]);
    }

    private function estimateCost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $price = config("ai_usage.prices.{$model}");

        if ($price === null) {
            return null;
        }

        return round(($inputTokens * $price['input'] + $outputTokens * $price['output']) / 1_000_000, 6);
    }
}
