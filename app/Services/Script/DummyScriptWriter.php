<?php

namespace App\Services\Script;

use App\Models\ProductionPlan;
use App\Models\ProductionScene;

/**
 * API キーなしで台本の候補の流れを確かめるためのダミー。伝えたいことの文を並べ替えて 3 案を作る。
 */
class DummyScriptWriter implements ScriptWriter
{
    public const MODEL = 'dummy';

    private const ANGLES = [
        ['結論から', '最初に結論を伝え、理由を順に説明する。', ''],
        ['問いかけから', '問いかけで始め、答えを順に示す。', 'なぜでしょうか。'],
        ['具体例から', '身近な例から入り、要点にまとめる。', '例えば、'],
    ];

    public function write(ProductionPlan $plan): ScriptDraft
    {
        $context = ScriptContext::for($plan);
        $points = collect(preg_split('/[。\n]+/u', (string) $context['brief']))->map(fn (string $s) => trim($s))->filter()->values()->all() ?: [$context['title']];

        $candidates = array_map(fn (array $angle) => [
            'label' => $angle[0],
            'summary' => $angle[1],
            'scenes' => $plan->videoScenes->values()->map(fn (ProductionScene $scene, int $i) => $this->scene($scene, $points, $i, $angle[2], $context))->all(),
        ], self::ANGLES);

        return new ScriptDraft(ScriptContext::normalize($candidates, $plan), self::MODEL);
    }

    /**
     * @param  list<string>  $points
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function scene(ProductionScene $scene, array $points, int $index, string $lead, array $context): array
    {
        $point = $points[$index % count($points)];
        $narration = mb_substr($lead.$point.'。', 0, NarrationTiming::characterBudget((float) $scene->duration_seconds));

        return [
            'key' => $scene->scene_key,
            'narration' => $narration,
            'caption' => mb_substr($point, 0, 40),
            'heading' => $index === 0 ? $context['title'] : $scene->title,
            'subheading' => '',
            'items' => array_slice($points, 0, 3),
            'labels' => array_slice($points, 0, count($scene->content['diagram']['nodes'] ?? [])),
        ];
    }
}
