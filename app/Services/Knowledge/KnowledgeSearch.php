<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeNode;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * キーワードと意味を合わせて知識ノードを探す（design.md の「検索」）。
 *
 * 短い日本語のキーワードは埋め込みの類似度に差が付きにくく、具体的な言葉は概念より出典の動画に入っていることが多い。
 * そこで、名称・説明・出典のキーワードの一致と、意味の近さの四つの一覧を、それぞれの順位で合わせて並べる（RRF）。
 */
class KnowledgeSearch
{
    /** RRF の定数。順位 r の点は 1/(RRF_K + r)。上位どうしの差を大きくしすぎないための一般的な値。 */
    public const RRF_K = 60;

    /** 一つの一覧から取り出す件数の上限。 */
    public const MATCH_LIMIT = 100;

    public const REASON_TITLE = 'title';

    public const REASON_DESCRIPTION = 'description';

    public const REASON_SOURCE = 'source';

    public const REASON_MEANING = 'meaning';

    public const REASON_LABELS = [
        self::REASON_TITLE => '名称に一致',
        self::REASON_DESCRIPTION => '説明に一致',
        self::REASON_SOURCE => '出典に一致',
        self::REASON_MEANING => '意味が近い',
    ];

    public function __construct(private readonly KnowledgeEmbedder $embedder) {}

    /**
     * @param  ?array<int, float>  $vector  検索語の埋め込み。null なら、キーワードの一致だけで探す
     * @param  Closure(Builder<KnowledgeNode>): mixed  $filters  状態・種類・ジャンルなどの絞り込み（四つの一覧すべてに使う）
     * @param  float  $minSimilarity  意味の近さだけで見つける類似度の下限
     * @return Collection<int, KnowledgeNode> 関係の深い順。similarity（埋め込みがなければ null）と match_reasons（REASON_* の配列）付き
     */
    public function search(string $keyword, ?array $vector, Closure $filters, int $limit = 30, float $minSimilarity = 0.6): Collection
    {
        $like = '%'.$keyword.'%';
        $sourceMatches = fn ($sources) => $sources->where(fn ($q) => $q
            ->whereLike('excerpt', $like)
            ->orWhereHas('video', fn ($videos) => $videos->whereLike('title', $like)->orWhereLike('summary', $like)));

        $lists = [
            self::REASON_TITLE => $this->matchingIds($vector, $filters, fn ($q) => $q->whereLike('title', $like)),
            self::REASON_DESCRIPTION => $this->matchingIds($vector, $filters, fn ($q) => $q->whereLike('description', $like)),
            self::REASON_SOURCE => $this->matchingIds($vector, $filters, fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('sources', $sourceMatches)
                ->orWhereHas('outgoingEdges', fn ($edges) => $edges
                    ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
                    ->whereHas('sources', $sourceMatches)))),
        ];

        if ($vector !== null) {
            $lists[self::REASON_MEANING] = $this->embedder->similarTo($vector, $minSimilarity)
                ->tap($filters)
                ->select('knowledge_nodes.id')
                ->limit(self::MATCH_LIMIT)
                ->pluck('id')
                ->all();
        }

        $scores = [];
        $reasons = [];
        foreach ($lists as $reason => $ids) {
            foreach (array_values($ids) as $index => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (self::RRF_K + $index + 1);
                $reasons[$id][] = $reason;
            }
        }

        if ($scores === []) {
            return new Collection;
        }

        $nodes = KnowledgeNode::query()
            ->select('knowledge_nodes.*')
            ->when($vector !== null, fn ($query) => $this->embedder->withSimilarity($query, $vector))
            ->whereKey(array_keys($scores))
            ->get()
            ->each(fn (KnowledgeNode $node) => $node->setAttribute('match_reasons', $reasons[$node->id]));

        return $nodes
            ->sortBy([
                fn (KnowledgeNode $a, KnowledgeNode $b) => $scores[$b->id] <=> $scores[$a->id],
                fn (KnowledgeNode $a, KnowledgeNode $b) => ($b->similarity ?? -1) <=> ($a->similarity ?? -1),
                fn (KnowledgeNode $a, KnowledgeNode $b) => $b->id <=> $a->id,
            ])
            ->take($limit)
            ->values();
    }

    /**
     * キーワードに一致するノードの id。埋め込みがあれば類似度の高い順（埋め込みのないものは後ろ）、なければ新しい順。
     *
     * @param  ?array<int, float>  $vector
     * @param  Closure(Builder<KnowledgeNode>): mixed  $filters
     * @param  Closure(Builder<KnowledgeNode>): mixed  $matches
     * @return list<int>
     */
    private function matchingIds(?array $vector, Closure $filters, Closure $matches): array
    {
        return KnowledgeNode::query()
            ->select('knowledge_nodes.id')
            ->tap($filters)
            ->tap($matches)
            ->when($vector !== null, fn ($query) => $this->embedder->withSimilarity($query, $vector)->orderByRaw('similarity desc nulls last'))
            ->orderByDesc('id')
            ->limit(self::MATCH_LIMIT)
            ->pluck('id')
            ->all();
    }
}
