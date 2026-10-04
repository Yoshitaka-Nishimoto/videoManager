<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeRevision;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 人が知識を確定・修正・統合する操作。どの操作も規則を確認し、変更履歴を残す。
 *
 * ノードとエッジは削除せず、不要になったものは「廃止」にする。
 */
class KnowledgeCurator
{
    /** 統合できるノードの種類。動画・分析ノードは事実の記録なので統合しない。 */
    private const MERGEABLE_TYPES = [
        KnowledgeNode::TYPE_CONCEPT,
        KnowledgeNode::TYPE_DECISION,
        KnowledgeNode::TYPE_IMPLEMENTATION,
    ];

    public function confirmNode(KnowledgeNode $node, User $user, ?string $reason = null): void
    {
        $this->ensureNotDeprecated($node);
        $this->changeStatus($node, KnowledgeNode::STATUS_CONFIRMED, KnowledgeRevision::ACTION_CONFIRM, $user, $reason);
    }

    public function deprecateNode(KnowledgeNode $node, User $user, ?string $reason = null): void
    {
        $this->changeStatus($node, KnowledgeNode::STATUS_DEPRECATED, KnowledgeRevision::ACTION_DEPRECATE, $user, $reason);
    }

    public function updateNode(KnowledgeNode $node, string $title, ?string $description, User $user, ?string $reason = null): void
    {
        $this->ensureNotDeprecated($node);

        DB::transaction(function () use ($node, $title, $description, $user, $reason) {
            $before = $node->only(['title', 'description']);
            $node->update(['title' => $title, 'description' => $description]);
            $after = $node->only(['title', 'description']);

            if ($before !== $after) {
                $this->recordRevision($node, KnowledgeRevision::ACTION_UPDATE, $before, $after, $user, $reason);
            }
        });
    }

    /**
     * $from を $into に統合する。$from のエッジと出典は $into に付け替え、$from は廃止して統合先を記録する。
     */
    public function mergeNode(KnowledgeNode $from, KnowledgeNode $into, User $user, ?string $reason = null): void
    {
        if ($from->is($into)) {
            throw new KnowledgeRuleViolation('同じノードには統合できません。');
        }

        if (! in_array($from->node_type, self::MERGEABLE_TYPES, true) || $from->node_type !== $into->node_type) {
            throw new KnowledgeRuleViolation('統合できるのは、同じ種類の概念・設計判断・実装ノードだけです。');
        }

        $this->ensureNotDeprecated($from);
        $this->ensureNotDeprecated($into, '統合先のノードは廃止されています。');

        DB::transaction(function () use ($from, $into, $user, $reason) {
            $edges = KnowledgeEdge::query()
                ->where('source_node_id', $from->id)
                ->orWhere('target_node_id', $from->id)
                ->get();

            foreach ($edges as $edge) {
                $this->moveEdge($edge, $from, $into, $user);
            }

            KnowledgeSource::query()
                ->where('sourceable_type', $from->getMorphClass())
                ->where('sourceable_id', $from->id)
                ->update(['sourceable_id' => $into->id]);

            // 以前 $from に統合されていたノードも、新しい統合先を指すようにする。
            KnowledgeNode::query()->where('merged_into_id', $from->id)->update(['merged_into_id' => $into->id]);

            $before = $from->only(['status', 'merged_into_id']);
            $from->update(['status' => KnowledgeNode::STATUS_DEPRECATED, 'merged_into_id' => $into->id]);
            $this->recordRevision($from, KnowledgeRevision::ACTION_MERGE, $before, $from->only(['status', 'merged_into_id']), $user, $reason);
            $this->recordRevision($into, KnowledgeRevision::ACTION_MERGE, null, ['merged_from_id' => $from->id, 'merged_from_title' => $from->title], $user, $reason);
        });
    }

    public function confirmEdge(KnowledgeEdge $edge, User $user, ?string $reason = null): void
    {
        if ($edge->status === KnowledgeNode::STATUS_DEPRECATED) {
            throw new KnowledgeRuleViolation('廃止された関係は採用できません。');
        }

        $this->changeStatus($edge, KnowledgeNode::STATUS_CONFIRMED, KnowledgeRevision::ACTION_CONFIRM, $user, $reason);
    }

    public function deprecateEdge(KnowledgeEdge $edge, User $user, ?string $reason = null): void
    {
        $this->changeStatus($edge, KnowledgeNode::STATUS_DEPRECATED, KnowledgeRevision::ACTION_DEPRECATE, $user, $reason);
    }

    /**
     * 人が関係を追加する。人が追加した関係は確認済みとして登録する。
     */
    public function addEdge(KnowledgeNode $source, KnowledgeRelationType $relationType, KnowledgeNode $target, User $user, ?string $reason = null): KnowledgeEdge
    {
        if ($source->is($target)) {
            throw new KnowledgeRuleViolation('ノード自身への関係は作れません。');
        }

        $this->ensureNotDeprecated($source);
        $this->ensureNotDeprecated($target, '接続先のノードは廃止されています。');

        if (! in_array($source->node_type, $relationType->allowed_source_types ?? [$source->node_type], true)
            || ! in_array($target->node_type, $relationType->allowed_target_types ?? [$target->node_type], true)) {
            throw new KnowledgeRuleViolation("「{$relationType->label}」は、この種類のノードの組み合わせには使えません。");
        }

        $exists = KnowledgeEdge::query()
            ->where('source_node_id', $source->id)
            ->where('target_node_id', $target->id)
            ->where('relation_type_id', $relationType->id)
            ->exists();

        if ($exists) {
            throw new KnowledgeRuleViolation('同じ関係がすでにあります（廃止済みを含む）。');
        }

        if (! $relationType->allow_cycle && $this->reachable($target, $source, $relationType)) {
            throw new KnowledgeRuleViolation("「{$relationType->label}」は循環を禁止しています。この関係を追加すると循環ができます。");
        }

        return DB::transaction(function () use ($source, $relationType, $target, $user, $reason) {
            $edge = KnowledgeEdge::query()->create([
                'source_node_id' => $source->id,
                'target_node_id' => $target->id,
                'relation_type_id' => $relationType->id,
                'status' => KnowledgeNode::STATUS_CONFIRMED,
                'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ]);

            $this->recordRevision($edge, KnowledgeRevision::ACTION_CREATE, null, $edge->only(['source_node_id', 'target_node_id', 'relation_type_id', 'status']), $user, $reason);

            return $edge;
        });
    }

    /**
     * $from から同じ種類の有効な関係をたどって $to に着けるか。
     */
    private function reachable(KnowledgeNode $from, KnowledgeNode $to, KnowledgeRelationType $relationType): bool
    {
        $visited = [];
        $frontier = [$from->id];

        while ($frontier !== []) {
            if (in_array($to->id, $frontier, true)) {
                return true;
            }

            $visited = [...$visited, ...$frontier];

            $frontier = KnowledgeEdge::query()
                ->where('relation_type_id', $relationType->id)
                ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
                ->whereIn('source_node_id', $frontier)
                ->whereNotIn('target_node_id', $visited)
                ->distinct()
                ->pluck('target_node_id')
                ->all();
        }

        return false;
    }

    private function moveEdge(KnowledgeEdge $edge, KnowledgeNode $from, KnowledgeNode $into, User $user): void
    {
        $sourceId = $edge->source_node_id === $from->id ? $into->id : $edge->source_node_id;
        $targetId = $edge->target_node_id === $from->id ? $into->id : $edge->target_node_id;
        $before = $edge->only(['source_node_id', 'target_node_id', 'status']);

        $duplicate = KnowledgeEdge::query()
            ->where('source_node_id', $sourceId)
            ->where('target_node_id', $targetId)
            ->where('relation_type_id', $edge->relation_type_id)
            ->first();

        if ($sourceId === $targetId || $duplicate !== null) {
            // 統合先との間の関係、または統合先にすでにある関係は廃止する。出典は残っている関係へ移す。
            if ($duplicate !== null) {
                $edge->sources()->update(['sourceable_id' => $duplicate->id]);
            }

            $edge->update(['status' => KnowledgeNode::STATUS_DEPRECATED]);
        } else {
            $edge->update(['source_node_id' => $sourceId, 'target_node_id' => $targetId]);
        }

        $this->recordRevision($edge, KnowledgeRevision::ACTION_MERGE, $before, $edge->only(['source_node_id', 'target_node_id', 'status']), $user, "「{$from->title}」を「{$into->title}」に統合");
    }

    private function changeStatus(KnowledgeNode|KnowledgeEdge $model, string $status, string $action, User $user, ?string $reason): void
    {
        if ($model->status === $status) {
            return;
        }

        DB::transaction(function () use ($model, $status, $action, $user, $reason) {
            $before = ['status' => $model->status];

            $model->update([
                'status' => $status,
                ...($status === KnowledgeNode::STATUS_CONFIRMED ? ['confirmed_by' => $user->id, 'confirmed_at' => now()] : []),
            ]);

            $this->recordRevision($model, $action, $before, ['status' => $status], $user, $reason);
        });
    }

    private function ensureNotDeprecated(KnowledgeNode $node, string $message = 'このノードは廃止されています。'): void
    {
        if ($node->status === KnowledgeNode::STATUS_DEPRECATED) {
            throw new KnowledgeRuleViolation($message);
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function recordRevision(Model $model, string $action, ?array $before, ?array $after, User $user, ?string $reason): void
    {
        KnowledgeRevision::query()->create([
            'revisable_type' => $model->getMorphClass(),
            'revisable_id' => $model->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'changed_by' => $user->id,
        ]);
    }
}
