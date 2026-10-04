<?php

namespace App\Listeners;

use App\Services\AiUsage\AiUsageRecorder;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;

/**
 * Laravel AI SDK のエージェント呼び出しを、成功・失敗とも AI 使用量として記録する。
 */
class RecordAgentUsage
{
    public function __construct(private readonly AiUsageRecorder $recorder) {}

    public function handlePrompted(AgentPrompted $event): void
    {
        $this->recorder->record(
            provider: $event->response->meta->provider ?? $event->prompt->provider->name(),
            model: $event->response->meta->model ?? $event->prompt->model,
            inputTokens: $event->response->usage->inputTokens,
            outputTokens: $event->response->usage->outputTokens,
            defaultFeature: class_basename($event->prompt->agent),
        );
    }

    public function handleFailed(AgentFailed $event): void
    {
        $this->recorder->record(
            provider: $event->prompt->provider->name(),
            model: $event->prompt->model,
            inputTokens: 0,
            outputTokens: 0,
            succeeded: false,
            errorMessage: $event->exception->getMessage(),
            defaultFeature: class_basename($event->prompt->agent),
        );
    }
}
