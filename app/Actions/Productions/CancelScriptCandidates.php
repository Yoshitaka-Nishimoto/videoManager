<?php

namespace App\Actions\Productions;

use App\Models\ProductionPlan;
use Illuminate\Support\Facades\DB;

/**
 * 台本の候補作りを止める。前に作った候補があれば、その状態に戻す。
 *
 * キューに残ったジョブは、動き出したときに止められたことを確かめて何もせずに終わる（Gemini は呼ばない）。
 * すでに Gemini に依頼中の場合、その応答は捨てる（その 1 回分の費用はかかる）。
 */
class CancelScriptCandidates
{
    public function handle(ProductionPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $locked = ProductionPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($locked->script_status !== ProductionPlan::SCRIPT_GENERATING) {
                return;
            }

            $locked->update([
                'script_status' => $locked->script_candidates ? ProductionPlan::SCRIPT_READY : null,
                'script_requested_at' => null,
                'script_started_at' => null,
            ]);
            $plan->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
