<?php

namespace App\Services\Analysis;

use App\Models\KnowledgeNode;
use Illuminate\Support\Str;

/**
 * 動画・Web ページの分析で共通する、プロンプトに含める既存の概念と、AI の回答の補正。
 */
trait NormalizesAnalysisOutput
{
    /** プロンプトに含める既存の概念の最大数。 */
    private const EXISTING_CONCEPTS_LIMIT = 100;

    /**
     * 同じ意味の概念に同じ名称を使わせるため、既存の概念の名称を並べる。確認済みを優先する。
     */
    private function existingConceptsLine(): string
    {
        $existing = KnowledgeNode::query()
            ->where('node_type', KnowledgeNode::TYPE_CONCEPT)
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->orderByRaw("case status when 'confirmed' then 0 else 1 end")
            ->latest('id')
            ->limit(self::EXISTING_CONCEPTS_LIMIT)
            ->pluck('title');

        return '既存の概念：'.($existing->isEmpty() ? 'なし' : $existing->implode('、'));
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string>
     */
    private function normalizeTopics(array $output): array
    {
        return collect($output['topics'] ?? [])
            ->filter(fn ($topic) => is_string($topic) && filled($topic))
            ->map(fn ($topic) => trim($topic))
            ->values()
            ->all();
    }

    /**
     * 名称の重複を除き、確信度を 0〜1 に収め、存在しない要点の番号を空にする。
     *
     * @param  array<string, mixed>  $output
     * @param  list<array<string, mixed>>  $keyPoints  補正済みの要点
     * @return list<array{title: string, description: string, confidence: float, key_point: int|null}>
     */
    private function normalizeConcepts(array $output, array $keyPoints): array
    {
        return collect($output['concepts'] ?? [])
            ->filter(fn ($concept) => is_array($concept) && filled($concept['title'] ?? null))
            ->unique(fn (array $concept) => trim($concept['title']))
            ->map(fn (array $concept) => [
                'title' => Str::limit(trim($concept['title']), 250, ''),
                'description' => trim($concept['description'] ?? ''),
                'confidence' => round(max(0, min(1, (float) ($concept['confidence'] ?? 0.5))), 3),
                'key_point' => isset($keyPoints[$concept['key_point'] ?? -1]) ? (int) $concept['key_point'] : null,
            ])
            ->values()
            ->all();
    }
}
