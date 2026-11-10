<?php

namespace App\Services\Mechanisms;

use App\Models\KnowledgeCodeLink;
use App\Models\KnowledgeNode;
use App\Models\MechanismRound;
use App\Services\Knowledge\KnowledgeEmbedder;
use App\Services\Knowledge\KnowledgeSearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * ② 知識検索で、仕組みの周に関係するプロジェクト内の知識を探す（plotdesign.md の「知識検索」）。
 *
 * 検索語（① で AI が出した検索語、言い換え、前の周のはっきりしない点への回答、次の周への入力、目標）ごとに KnowledgeSearch で探し、
 * 各一覧での順位を合わせて（RRF）、関係の深い順に MAX_NODES 件まで返す。
 * 持ち主が見られる知識（共有と本人の個人の知識）だけ。廃止した知識と、動画・分析のノードは入れない。
 */
class MechanismKnowledge
{
    /** AI に渡す知識の上限。 */
    public const MAX_NODES = 30;

    /** 1 つの検索語で探す件数。 */
    public const PER_QUERY = 20;

    /** 意味の近さだけで見つけるときの類似度の下限（知識一覧の「意味で検索」と同じ）。 */
    public const MIN_SIMILARITY = 0.6;

    /** 仕組みの根拠にする知識の種類。 */
    public const NODE_TYPES = [KnowledgeNode::TYPE_CONCEPT, KnowledgeNode::TYPE_DECISION, KnowledgeNode::TYPE_IMPLEMENTATION];

    /** AI に渡す説明の上限（文字数）。 */
    private const DESCRIPTION_LENGTH = 300;

    public function __construct(
        private readonly KnowledgeSearch $search,
        private readonly KnowledgeEmbedder $embedder,
    ) {}

    /**
     * 探すのに使う検索語。
     *
     * @return list<string>
     */
    public function queries(MechanismRound $round): array
    {
        $understanding = $round->understanding ?? [];

        return collect([
            ...($understanding['search_keywords'] ?? []),
            $understanding['restated_goal'] ?? null,
            ...array_column($round->receivedAnswers(), 'answer'),
            $round->instruction,
            $round->mechanism->goal,
        ])
            ->filter(fn ($query) => is_string($query))
            ->map(fn (string $query) => Str::squish($query))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $queries
     * @return Collection<int, KnowledgeNode> 関係の深い順。match_reasons（KnowledgeSearch::REASON_* の配列）と best_similarity 付き
     */
    public function find(MechanismRound $round, array $queries): Collection
    {
        $ownerId = $round->mechanism->owner_id;
        $filters = fn ($query) => $query
            ->visibleTo($ownerId)
            ->whereIn('node_type', self::NODE_TYPES)
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->whereNull('merged_into_id');

        $scores = [];
        $reasons = [];
        $similarities = [];

        foreach ($queries as $query) {
            $nodes = $this->search->search($query, $this->vectorFor($query), $filters, self::PER_QUERY, self::MIN_SIMILARITY);

            foreach ($nodes->values() as $index => $node) {
                $scores[$node->id] = ($scores[$node->id] ?? 0) + 1 / (KnowledgeSearch::RRF_K + $index + 1);
                $reasons[$node->id] = array_values(array_unique([...($reasons[$node->id] ?? []), ...$node->match_reasons]));
                $similarities[$node->id] = max($similarities[$node->id] ?? 0, (float) ($node->similarity ?? 0));
            }
        }

        if ($scores === []) {
            return new Collection;
        }

        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, self::MAX_NODES);

        return KnowledgeNode::query()
            ->visibleTo($ownerId)
            ->whereKey($ids)
            ->with(['codeLinks' => fn ($q) => $q->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)])
            ->get()
            ->each(function (KnowledgeNode $node) use ($reasons, $similarities) {
                $node->setAttribute('match_reasons', $reasons[$node->id]);
                $node->setAttribute('best_similarity', $similarities[$node->id] > 0 ? round($similarities[$node->id], 3) : null);
            })
            ->sortBy(fn (KnowledgeNode $node) => array_search($node->id, $ids, true))
            ->values();
    }

    /**
     * AI に渡す知識の一覧。
     *
     * @param  Collection<int, KnowledgeNode>  $nodes
     * @return list<array<string, mixed>>
     */
    public function forPrompt(Collection $nodes): array
    {
        return $nodes->map(fn (KnowledgeNode $node) => array_filter([
            'id' => $node->id,
            'type' => $node->typeLabel(),
            'status' => KnowledgeNode::statusLabelFor($node->status),
            'personal' => $node->owner_id !== null ? true : null,
            'title' => $node->title,
            'description' => Str::limit((string) $node->description, self::DESCRIPTION_LENGTH),
            'code' => $node->codeLinks->map(fn (KnowledgeCodeLink $link) => trim($link->path.' '.$link->symbol))->values()->all() ?: null,
        ], fn ($value) => $value !== null && $value !== ''))->values()->all();
    }

    /**
     * 検索語の埋め込み。使わない設定のとき・作れなかったときは null（キーワードの一致だけで探す）。
     *
     * @return ?array<int, float>
     */
    private function vectorFor(string $query): ?array
    {
        if (! $this->embedder->enabled()) {
            return null;
        }

        try {
            return $this->embedder->vectorFor($query);
        } catch (Throwable $e) {
            Log::warning('仕組みの知識検索で埋め込みを作れなかったため、キーワードの一致だけで探します。', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
