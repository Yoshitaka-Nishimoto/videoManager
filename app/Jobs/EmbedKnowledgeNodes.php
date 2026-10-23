<?php

namespace App\Jobs;

use App\Models\KnowledgeNode;
use App\Services\Knowledge\KnowledgeEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 知識ノードの埋め込みを作り直す。元の文が変わっていないノードは送らない（KnowledgeEmbedder::isStale）。
 */
class EmbedKnowledgeNodes implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  list<int>  $nodeIds
     */
    public function __construct(public array $nodeIds) {}

    /**
     * 埋め込みが有効なときだけ、トランザクションの確定後にジョブを入れる。
     *
     * @param  list<int|null>  $nodeIds
     */
    public static function dispatchFor(array $nodeIds): void
    {
        $nodeIds = array_values(array_unique(array_filter($nodeIds)));

        if ($nodeIds !== [] && config('services.knowledge_embeddings.enabled')) {
            static::dispatch($nodeIds)->afterCommit();
        }
    }

    public function handle(KnowledgeEmbedder $embedder): void
    {
        if (! $embedder->enabled()) {
            return;
        }

        $embedder->embed(KnowledgeNode::query()->with('video')->whereKey($this->nodeIds)->get());
    }
}
