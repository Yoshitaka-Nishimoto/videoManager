<?php

namespace App\Actions\Productions;

use App\Jobs\GenerateScriptCandidates;
use App\Models\ProductionPlan;
use Illuminate\Support\Facades\DB;

/**
 * 台本の候補作りを依頼する。前の候補は、新しい候補ができたときに置き換わる。
 */
class RequestScriptCandidates
{
    /**
     * @throws ScriptNotAllowedException
     */
    public function handle(ProductionPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            // 同じ制作案への同時の依頼で候補作りが二重に動かないよう、行をロックしてから確かめる。
            $locked = ProductionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isEditable()) {
                throw new ScriptNotAllowedException('台本を作れるのは候補の版だけです。確認済みの版を直すときは、新しい版を作ります。');
            }

            if ($locked->script_status === ProductionPlan::SCRIPT_GENERATING) {
                throw new ScriptNotAllowedException('台本の候補を作っているところです。終わるまで待つか、「止める」を押してください。');
            }

            $locked->update([
                'script_status' => ProductionPlan::SCRIPT_GENERATING,
                'script_error' => null,
                'script_requested_at' => now(),
                'script_started_at' => null,
            ]);
            $plan->setRawAttributes($locked->getAttributes(), true);
        });

        GenerateScriptCandidates::dispatch($plan)->afterCommit();
    }
}
