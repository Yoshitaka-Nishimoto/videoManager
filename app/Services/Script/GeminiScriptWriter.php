<?php

namespace App\Services\Script;

use App\Ai\Agents\ScriptWriterAgent;
use App\Models\ProductionPlan;
use App\Services\Ai\GeminiModels;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Gemini で台本の候補を 3 案作る。混雑・回数制限のときは代わりのモデルを順に試す（GeminiModels）。
 * AI 使用量は Laravel AI SDK のイベント経由で自動的に記録される（RecordAgentUsage）。
 * Gemini 以外の AI（OpenAI など。services.video_analyzer.text_provider）でも作れる。
 */
class GeminiScriptWriter implements ScriptWriter
{
    public function __construct(
        private readonly GeminiModels $models,
        private readonly Lab $provider = Lab::Gemini,
    ) {}

    public function write(ProductionPlan $plan): ScriptDraft
    {
        $prompt = json_encode(ScriptContext::for($plan), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        /** @var AgentResponse $response */
        [$response, $model] = $this->models->attempt(
            fn (?string $model) => (new ScriptWriterAgent)->prompt($prompt, provider: $this->provider, model: $model),
            purpose: '台本の候補',
            context: ['production_plan_id' => $plan->id],
        );

        $output = $response->toArray();

        return new ScriptDraft(
            candidates: ScriptContext::normalize($output['candidates'] ?? [], $plan),
            model: $response->meta->model ?? $model ?? $this->provider->value,
            prompt: $prompt,
            raw: $output,
        );
    }
}
