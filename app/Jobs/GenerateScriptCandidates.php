<?php

namespace App\Jobs;

use App\Models\AiUsageLog;
use App\Models\ProductionPlan;
use App\Services\AiUsage\AiUsageRecorder;
use App\Services\Script\ScriptWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 制作案の台本の候補（3 案）を作り、production_plans.script_candidates に保存する。
 */
class GenerateScriptCandidates implements ShouldQueue
{
    use Queueable;

    /** AI の呼び出しは費用がかかるため自動ではやり直さない。失敗したら画面から作り直す。 */
    public int $tries = 1;

    public int $timeout = 300;

    /** この依頼を出した時刻。止められた・新しく依頼し直された依頼のジョブを見分けるのに使う。 */
    public ?string $requestedAt;

    public function __construct(public ProductionPlan $plan)
    {
        $this->requestedAt = $plan->script_requested_at?->toIso8601String();
    }

    public function handle(ScriptWriter $writer, AiUsageRecorder $usage): void
    {
        // 止められた・依頼し直された依頼なら、Gemini を呼ばずに終わる。
        if (! $this->isCurrentRequest()) {
            return;
        }

        $this->plan->update(['script_started_at' => now()]);

        // 台本の候補作りの AI 呼び出しは「script」機能として、この制作案に紐付けて記録する。
        $draft = $usage->within(AiUsageLog::FEATURE_SCRIPT, $this->plan, fn () => $writer->write($this->plan));

        if ($draft->candidates === []) {
            throw new RuntimeException('台本の候補が返ってきませんでした。');
        }

        // 作成中に止められていたら、応答は捨てる。
        if (! $this->isCurrentRequest()) {
            return;
        }

        $this->plan->update([
            'script_status' => ProductionPlan::SCRIPT_READY,
            'script_candidates' => $draft->candidates,
            'script_error' => null,
            'script_started_at' => null,
            'model' => $draft->model,
            'prompt' => $draft->prompt,
            'ai_response' => $draft->raw,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if (! $this->isCurrentRequest()) {
            return;
        }

        $this->plan->update([
            'script_status' => ProductionPlan::SCRIPT_FAILED,
            'script_error' => Str::limit($exception?->getMessage() ?? '台本の候補を作れませんでした。', 2000),
            'script_started_at' => null,
        ]);
    }

    /**
     * 制作案がまだ「作成中」で、この依頼が最新の依頼か。
     */
    private function isCurrentRequest(): bool
    {
        $this->plan->refresh();

        return $this->plan->script_status === ProductionPlan::SCRIPT_GENERATING
            && $this->plan->script_requested_at?->toIso8601String() === $this->requestedAt;
    }
}
