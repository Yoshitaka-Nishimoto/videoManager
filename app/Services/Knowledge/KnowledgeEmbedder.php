<?php

namespace App\Services\Knowledge;

use App\Models\AiUsageLog;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Services\AiUsage\AiUsageRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Embeddings;

/**
 * 知識ノードの埋め込み（embedding）を作り、意味の近いノードを探す（knowledge_tables.md）。
 *
 * 埋め込みは候補を探す手段で、同じ概念かどうか・関係が正しいかの確定は人が行う。
 */
class KnowledgeEmbedder
{
    /** 一度に送る文の数（Gemini の batchEmbedContents の上限は 100）。 */
    private const BATCH_SIZE = 50;

    /** 重複の候補を探す種類。動画・分析は事実の記録なので統合しない（KnowledgeCurator と同じ）。 */
    public const DUPLICATE_TYPES = [
        KnowledgeNode::TYPE_CONCEPT,
        KnowledgeNode::TYPE_DECISION,
        KnowledgeNode::TYPE_IMPLEMENTATION,
    ];

    /**
     * 重複の候補とみなす類似度。gemini-embedding-2（1536 次元）では、関連はあるが別の概念どうしが 0.8〜0.87 になるため、
     * それより上にする（2026-10-23 の 98 件で確認）。
     */
    public const DUPLICATE_SIMILARITY = 0.9;

    public function __construct(private readonly AiUsageRecorder $usage) {}

    public function enabled(): bool
    {
        return (bool) config('services.knowledge_embeddings.enabled');
    }

    /**
     * 埋め込みの元にする文。説明が短い設計判断・実装は、つながる知識とコードも加えて意味を補う。
     */
    public function text(KnowledgeNode $node): string
    {
        $lines = match ($node->node_type) {
            KnowledgeNode::TYPE_VIDEO => [
                "動画：{$node->title}",
                // 動画ノードの説明は分析後に要約で更新されるが、動画のテーブルの要約を正とする。
                '要約：'.($node->video?->summary ?: $node->description),
            ],
            KnowledgeNode::TYPE_ANALYSIS => ["動画の分析：{$node->title}", "要約：{$node->description}"],
            KnowledgeNode::TYPE_DECISION => [
                "設計判断：{$node->title}",
                "説明：{$node->description}",
                '着想元の概念：'.$this->linkedTitles($node, KnowledgeRelationType::KEY_INSPIRED_BY),
            ],
            KnowledgeNode::TYPE_IMPLEMENTATION => [
                "実装：{$node->title}",
                "説明：{$node->description}",
                '実装する設計判断・概念：'.$this->linkedTitles($node, KnowledgeRelationType::KEY_IMPLEMENTS),
                'コード：'.$node->codeLinks()
                    ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
                    ->orderBy('path')
                    ->get()
                    ->map(fn ($link) => trim("{$link->path} {$link->symbol}"))
                    ->implode('、'),
            ],
            default => [KnowledgeNode::typeLabelFor($node->node_type)."：{$node->title}", "説明：{$node->description}"],
        };

        // 値のない行（「説明：」だけなど）は入れない。
        return collect($lines)->reject(fn (string $line) => preg_match('/：\s*$/u', $line))->implode("\n");
    }

    public function hash(KnowledgeNode $node): string
    {
        return hash('sha256', implode('|', [$this->model(), $this->dimensions(), $this->text($node)]));
    }

    public function isStale(KnowledgeNode $node): bool
    {
        return $node->status !== KnowledgeNode::STATUS_DEPRECATED
            && ($node->embedding === null || $node->embedding_hash !== $this->hash($node));
    }

    /**
     * ノードの埋め込みを作って保存する。作り直しが要らないノードは送らない。
     *
     * @param  iterable<KnowledgeNode>  $nodes
     * @return int 作ったノードの数
     */
    public function embed(iterable $nodes, bool $force = false): int
    {
        $targets = collect($nodes)->filter(fn (KnowledgeNode $node) => $force ? $node->status !== KnowledgeNode::STATUS_DEPRECATED : $this->isStale($node));

        foreach ($targets->chunk(self::BATCH_SIZE) as $chunk) {
            $texts = $chunk->map(fn (KnowledgeNode $node) => $this->text($node))->values();

            $response = $this->usage->within(AiUsageLog::FEATURE_EMBEDDING, null, fn () => Embeddings::for($texts->all())
                ->dimensions($this->dimensions())
                ->generate(config('services.knowledge_embeddings.provider'), $this->model()));

            foreach ($chunk->values() as $index => $node) {
                // 埋め込みを保存しても、ノードの内容は変わらないので、作り直しのジョブを呼ばない。
                $node->forceFill([
                    'embedding' => $response->embeddings[$index],
                    'embedding_model' => $response->meta->model ?? $this->model(),
                    'embedding_hash' => $this->hash($node),
                    'embedded_at' => now(),
                ])->saveQuietly();
            }
        }

        return $targets->count();
    }

    /**
     * 重複の候補：同じ種類の、意味の近いノード。動画・分析は対象にしない。
     *
     * @return Collection<int, KnowledgeNode> similarity（0〜1）付き
     */
    public function duplicateCandidates(KnowledgeNode $node, int $limit = 5, float $minSimilarity = self::DUPLICATE_SIMILARITY): Collection
    {
        if ($node->embedding === null || ! in_array($node->node_type, self::DUPLICATE_TYPES, true)) {
            return new Collection;
        }

        return $this->nearest($node->embedding, $minSimilarity, $limit)
            ->whereKeyNot($node->id)
            ->where('node_type', $node->node_type)
            ->get();
    }

    /**
     * 言い換え検索：文と意味の近いノード（全種類）。
     *
     * @return Collection<int, KnowledgeNode> similarity（0〜1）付き
     */
    public function search(string $query, int $limit = 10, float $minSimilarity = 0.5): Collection
    {
        return $this->nearest($this->vectorFor($query), $minSimilarity, $limit)->get();
    }

    /**
     * 文の埋め込み。同じ文は 1 日キャッシュする（検索欄の再描画やページ送りのたびに API を呼ばないように）。
     *
     * @return array<int, float>
     */
    public function vectorFor(string $text): array
    {
        return $this->usage->within(AiUsageLog::FEATURE_EMBEDDING, null, fn () => Embeddings::for([$text])
            ->dimensions($this->dimensions())
            ->cache(60 * 60 * 24)
            ->generate(config('services.knowledge_embeddings.provider'), $this->model())
            ->first());
    }

    /**
     * 文と意味の近いノードを、近い順に返すクエリ（similarity 付き）。状態・種類・見える範囲などの条件は呼び出し側で足す。
     *
     * @param  array<int, float>  $vector
     * @return Builder<KnowledgeNode>
     */
    public function similarTo(array $vector, float $minSimilarity = 0.5): Builder
    {
        return KnowledgeNode::query()
            ->select('knowledge_nodes.*')
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [json_encode($vector)])
            ->whereNotNull('embedding')
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->whereVectorSimilarTo('embedding', $vector, $minSimilarity);
    }

    /**
     * @param  array<int, float>  $vector
     * @return Builder<KnowledgeNode>
     */
    private function nearest(array $vector, float $minSimilarity, int $limit): Builder
    {
        return $this->similarTo($vector, $minSimilarity)->limit($limit);
    }

    private function linkedTitles(KnowledgeNode $node, string $relationKey): string
    {
        return KnowledgeEdge::query()
            ->where('source_node_id', $node->id)
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->whereRelation('relationType', 'key', $relationKey)
            ->with('targetNode')
            ->get()
            ->pluck('targetNode.title')
            ->implode('、');
    }

    private function model(): string
    {
        return (string) config('services.knowledge_embeddings.model');
    }

    private function dimensions(): int
    {
        return (int) config('services.knowledge_embeddings.dimensions');
    }
}
