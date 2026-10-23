<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeCodeLink;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use Illuminate\Support\Collection;

/**
 * Graphify の graph.json に、DB の知識（ノード・エッジ）と「実装 → コード」の対応を加える（knowledge_tables.md）。
 *
 * 書き出した graph.json から `graphify cluster-only` で graph.html を作ると、Graphify の画面で
 * 概念 → 設計判断 → 実装 → コードとたどれる。元の graph.json は変更しない。廃止済みの知識は含めない。
 */
class KnowledgeGraphExporter
{
    /** 知識のノード ID の接頭辞。Graphify のノード ID と重ならないようにする。 */
    public const NODE_PREFIX = 'knowledge_';

    /** 実装 → コードのエッジの関係名。 */
    public const CODE_RELATION = 'knowledge_implemented_in';

    /**
     * @param  array<string, mixed>  $graph  graph.json の中身
     * @return array{graph: array<string, mixed>, nodes: int, edges: int, code_links: int, unresolved: list<string>}
     */
    public function merge(array $graph): array
    {
        $codeNodes = collect($graph['nodes'] ?? [])->keyBy('id');

        $nodes = KnowledgeNode::query()
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->orderBy('id')
            ->get();
        $nodeIds = $nodes->pluck('id')->flip();

        $edges = KnowledgeEdge::query()
            ->with('relationType')
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->orderBy('id')
            ->get()
            ->filter(fn (KnowledgeEdge $edge) => $nodeIds->has($edge->source_node_id) && $nodeIds->has($edge->target_node_id));

        $links = KnowledgeCodeLink::query()
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->orderBy('id')
            ->get()
            ->filter(fn (KnowledgeCodeLink $link) => $nodeIds->has($link->knowledge_node_id));

        $codeEdges = [];
        $unresolved = [];

        foreach ($links as $link) {
            $target = $this->codeNodeId($link, $codeNodes);

            if ($target === null) {
                $unresolved[] = $link->path.($link->symbol ? " {$link->symbol}" : '');

                continue;
            }

            $codeEdges[] = [
                'source' => $this->nodeId($link->knowledge_node_id),
                'target' => $target,
                'relation' => self::CODE_RELATION,
                '_origin' => 'videomanager',
                'confidence' => $this->confidence($link->status),
                'confidence_score' => 1.0,
                'source_file' => $link->path,
                'source_location' => $link->start_line !== null ? "L{$link->start_line}" : null,
                'weight' => 1.0,
            ];
        }

        $graph['nodes'] = [...($graph['nodes'] ?? []), ...$nodes->map(fn (KnowledgeNode $node) => [
            'id' => $this->nodeId($node->id),
            'label' => '['.KnowledgeNode::typeLabelFor($node->node_type).'] '.$node->title,
            '_origin' => 'videomanager',
            'file_type' => 'knowledge',
            'norm_label' => mb_strtolower($node->title),
            // graph.html の「Source」に出る。知識の画面を開くための URL。
            'source_file' => route('knowledge.show', $node),
            'source_location' => null,
        ])->all()];

        $graph['links'] = [...($graph['links'] ?? []), ...$edges->map(fn (KnowledgeEdge $edge) => [
            'source' => $this->nodeId($edge->source_node_id),
            'target' => $this->nodeId($edge->target_node_id),
            'relation' => 'knowledge_'.$edge->relationType->key,
            '_origin' => 'videomanager',
            'confidence' => $this->confidence($edge->status),
            'confidence_score' => $edge->confidence !== null ? (float) $edge->confidence : 1.0,
            'source_file' => route('knowledge.show', $edge->source_node_id),
            'source_location' => null,
            'weight' => 1.0,
        ])->values()->all(), ...$codeEdges];

        return [
            'graph' => $graph,
            'nodes' => $nodes->count(),
            'edges' => $edges->count(),
            'code_links' => count($codeEdges),
            'unresolved' => $unresolved,
        ];
    }

    public function nodeId(int $knowledgeNodeId): string
    {
        return self::NODE_PREFIX.$knowledgeNodeId;
    }

    /**
     * 対応がつなぐ Graphify のノード。記録した ID が今の graph.json になければ、同じファイル・同じシンボルのノードを探す。
     *
     * @param  Collection<string, array<string, mixed>>  $codeNodes
     */
    private function codeNodeId(KnowledgeCodeLink $link, $codeNodes): ?string
    {
        if ($link->graphify_node_id !== null && $codeNodes->has($link->graphify_node_id)) {
            return $link->graphify_node_id;
        }

        $method = $link->symbol === null ? null : last(explode('::', $link->symbol));
        $labels = $method === null ? [basename($link->path)] : [".{$method}()", "{$method}()", $method];

        return $codeNodes->first(fn (array $node) => ($node['source_file'] ?? null) === $link->path
            && in_array($node['label'] ?? null, $labels, true))['id'] ?? null;
    }

    /**
     * 確認済みは実線（EXTRACTED）、候補は点線（INFERRED）で表示される。
     */
    private function confidence(string $status): string
    {
        return $status === KnowledgeNode::STATUS_CONFIRMED ? 'EXTRACTED' : 'INFERRED';
    }
}
