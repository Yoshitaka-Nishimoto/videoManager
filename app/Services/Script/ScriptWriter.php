<?php

namespace App\Services\Script;

use App\Models\ProductionPlan;

/**
 * 制作案の場面の並び・長さと、制作の意図（ジャンル・対象者・口調・伝えたいこと）から、台本の候補を作る。
 *
 * Gemini（GeminiScriptWriter）と、API なしで流れを確かめるダミー（DummyScriptWriter）がある。
 */
interface ScriptWriter
{
    public function write(ProductionPlan $plan): ScriptDraft;
}
