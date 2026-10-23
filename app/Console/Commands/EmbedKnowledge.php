<?php

namespace App\Console\Commands;

use App\Models\KnowledgeNode;
use App\Services\Knowledge\KnowledgeEmbedder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('knowledge:embed
    {--force : 元の文が変わっていなくても、すべて作り直す}
    {--dry-run : AI を呼ばず、作る対象の件数と、説明を書き足すとよいノードだけ表示する}
    {--duplicates : 作ったあと、重複の候補（同じ種類で意味の近いノード）を表示する}')]
#[Description('知識ノードの埋め込み（pgvector）を作る。元の文が変わったノードだけを作り直す')]
class EmbedKnowledge extends Command
{
    /** これより短い説明は、埋め込みの精度が落ちやすい。 */
    private const SHORT_DESCRIPTION = 20;

    public function handle(KnowledgeEmbedder $embedder): int
    {
        $nodes = KnowledgeNode::query()
            ->with('video')
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->orderBy('id')
            ->get();

        $targets = $this->option('force') ? $nodes : $nodes->filter(fn (KnowledgeNode $node) => $embedder->isStale($node));

        $this->warnShortDescriptions($nodes);

        if ($this->option('dry-run')) {
            $this->info("埋め込みを作る対象：{$targets->count()} 件（有効なノード {$nodes->count()} 件のうち）");

            return self::SUCCESS;
        }

        if (! $embedder->enabled()) {
            $this->error('埋め込みが無効です（KNOWLEDGE_EMBEDDINGS=false）。');

            return self::FAILURE;
        }

        $count = $embedder->embed($targets, force: (bool) $this->option('force'));
        $this->info("埋め込みを {$count} 件作りました（有効なノード {$nodes->count()} 件のうち）。");

        if ($this->option('duplicates')) {
            $this->showDuplicates($embedder);
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, KnowledgeNode>  $nodes
     */
    private function warnShortDescriptions($nodes): void
    {
        $short = $nodes
            ->whereIn('node_type', [KnowledgeNode::TYPE_DECISION, KnowledgeNode::TYPE_IMPLEMENTATION])
            ->filter(fn (KnowledgeNode $node) => mb_strlen(trim((string) $node->description)) < self::SHORT_DESCRIPTION);

        if ($short->isEmpty()) {
            return;
        }

        $this->warn('説明が短い・空のため、書き足すと検索の精度が上がるノード（「何を、なぜそう決めたか」「どの機能で、どう実現したか」）：');

        foreach ($short as $node) {
            $this->line(sprintf('  [%s] %s  %s', KnowledgeNode::typeLabelFor($node->node_type), $node->title, route('knowledge.show', $node)));
        }
    }

    private function showDuplicates(KnowledgeEmbedder $embedder): void
    {
        $shown = [];
        $rows = [];

        $nodes = KnowledgeNode::query()
            ->whereIn('node_type', KnowledgeEmbedder::DUPLICATE_TYPES)
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->whereNotNull('embedding')
            ->orderBy('id')
            ->get();

        foreach ($nodes as $node) {
            foreach ($embedder->duplicateCandidates($node) as $other) {
                $pair = implode('-', [min($node->id, $other->id), max($node->id, $other->id)]);

                if (isset($shown[$pair])) {
                    continue;
                }

                $shown[$pair] = true;
                $rows[] = [KnowledgeNode::typeLabelFor($node->node_type), $node->title, $other->title, number_format((float) $other->similarity, 3)];
            }
        }

        if ($rows === []) {
            $this->info('重複の候補はありません。');

            return;
        }

        $this->table(['種類', 'ノード', '似ているノード', '類似度'], $rows);
    }
}
