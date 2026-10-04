<?php

namespace Tests\Feature;

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeRevision;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeCurator;
use App\Services\Knowledge\KnowledgeRuleViolation;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeCuratorTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeCurator $curator;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeRelationTypeSeeder::class);
        $this->curator = app(KnowledgeCurator::class);
        $this->user = User::factory()->create();
    }

    public function test_confirming_a_node_records_who_why_and_the_status_change(): void
    {
        $node = KnowledgeNode::factory()->create();

        $this->curator->confirmNode($node, $this->user, '動画で確認した');

        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $node->refresh()->status);
        $this->assertSame($this->user->id, $node->confirmed_by);

        $revision = $node->revisions()->sole();
        $this->assertSame(KnowledgeRevision::ACTION_CONFIRM, $revision->action);
        $this->assertSame(['status' => KnowledgeNode::STATUS_CANDIDATE], $revision->before);
        $this->assertSame('動画で確認した', $revision->reason);
        $this->assertSame($this->user->id, $revision->changed_by);
    }

    public function test_a_deprecated_node_cannot_be_confirmed(): void
    {
        $node = KnowledgeNode::factory()->create(['status' => KnowledgeNode::STATUS_DEPRECATED]);

        $this->expectException(KnowledgeRuleViolation::class);

        $this->curator->confirmNode($node, $this->user);
    }

    public function test_updating_a_node_keeps_the_previous_wording(): void
    {
        $node = KnowledgeNode::factory()->create(['title' => '画像品質確認', 'description' => null]);

        $this->curator->updateNode($node, '入力画像の品質確認', '生成前に確認する', $this->user, '名称を統一');
        $this->curator->updateNode($node, '入力画像の品質確認', '生成前に確認する', $this->user);

        $revision = $node->revisions()->sole();
        $this->assertSame(['title' => '画像品質確認', 'description' => null], $revision->before);
        $this->assertSame('入力画像の品質確認', $revision->after['title']);
    }

    public function test_merging_moves_edges_and_sources_to_the_surviving_node(): void
    {
        $from = KnowledgeNode::factory()->create(['title' => '画像品質確認']);
        $into = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認']);
        $analysis = KnowledgeNode::factory()->forAnalysis()->create();
        $other = KnowledgeNode::factory()->create();
        $alreadyMerged = KnowledgeNode::factory()->create(['status' => KnowledgeNode::STATUS_DEPRECATED, 'merged_into_id' => $from->id]);

        $extracted = $this->relation('extracted_from');
        $related = $this->relation('related_to');

        $duplicate = KnowledgeEdge::factory()->create(['source_node_id' => $from->id, 'target_node_id' => $analysis->id, 'relation_type_id' => $extracted]);
        $kept = KnowledgeEdge::factory()->create(['source_node_id' => $into->id, 'target_node_id' => $analysis->id, 'relation_type_id' => $extracted]);
        $moved = KnowledgeEdge::factory()->create(['source_node_id' => $other->id, 'target_node_id' => $from->id, 'relation_type_id' => $related]);
        $between = KnowledgeEdge::factory()->create(['source_node_id' => $from->id, 'target_node_id' => $into->id, 'relation_type_id' => $related]);
        $edgeSource = KnowledgeSource::factory()->create(['sourceable_type' => $duplicate->getMorphClass(), 'sourceable_id' => $duplicate->id]);
        $nodeSource = KnowledgeSource::factory()->create(['sourceable_id' => $from->id]);

        $this->curator->mergeNode($from, $into, $this->user, '同じ概念');

        $from->refresh();
        $this->assertSame(KnowledgeNode::STATUS_DEPRECATED, $from->status);
        $this->assertSame($into->id, $from->merged_into_id);
        $this->assertSame($into->id, $alreadyMerged->refresh()->merged_into_id);

        $this->assertSame(KnowledgeNode::STATUS_DEPRECATED, $duplicate->refresh()->status);
        $this->assertSame($kept->id, $edgeSource->refresh()->sourceable_id);
        $this->assertSame($into->id, $moved->refresh()->target_node_id);
        $this->assertSame(KnowledgeNode::STATUS_DEPRECATED, $between->refresh()->status);
        $this->assertSame($into->id, $nodeSource->refresh()->sourceable_id);

        $this->assertSame(KnowledgeRevision::ACTION_MERGE, $into->revisions()->sole()->action);
    }

    public function test_only_knowledge_nodes_of_the_same_type_can_be_merged(): void
    {
        $concept = KnowledgeNode::factory()->create();
        $decision = KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_DECISION]);

        $this->expectException(KnowledgeRuleViolation::class);

        $this->curator->mergeNode($concept, $decision, $this->user);
    }

    public function test_video_nodes_cannot_be_merged(): void
    {
        $a = KnowledgeNode::factory()->forVideo()->create();
        $b = KnowledgeNode::factory()->forVideo()->create();

        $this->expectException(KnowledgeRuleViolation::class);

        $this->curator->mergeNode($a, $b, $this->user);
    }

    public function test_a_person_can_add_a_confirmed_relation(): void
    {
        $implementation = KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_IMPLEMENTATION]);
        $concept = KnowledgeNode::factory()->create();

        $edge = $this->curator->addEdge($implementation, $this->relationType('implements'), $concept, $this->user, '品質確認を実装');

        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $edge->status);
        $this->assertSame(KnowledgeNode::PROPOSED_BY_HUMAN, $edge->proposed_by);
        $this->assertSame(KnowledgeRevision::ACTION_CREATE, $edge->revisions()->sole()->action);
    }

    public function test_relations_must_respect_the_allowed_node_types(): void
    {
        $concept = KnowledgeNode::factory()->create();
        $other = KnowledgeNode::factory()->create();

        $this->expectExceptionMessage('この種類のノードの組み合わせには使えません');

        // 「実装する」の接続元は実装ノードだけ。
        $this->curator->addEdge($concept, $this->relationType('implements'), $other, $this->user);
    }

    public function test_the_same_relation_cannot_be_added_twice(): void
    {
        [$a, $b] = KnowledgeNode::factory()->count(2)->create();
        $this->curator->addEdge($a, $this->relationType('prerequisite_of'), $b, $this->user);

        $this->expectExceptionMessage('同じ関係がすでにあります');

        $this->curator->addEdge($a, $this->relationType('prerequisite_of'), $b, $this->user);
    }

    public function test_a_cycle_is_rejected_when_the_relation_forbids_it(): void
    {
        [$a, $b, $c] = KnowledgeNode::factory()->count(3)->create();
        $prerequisite = $this->relationType('prerequisite_of');
        $this->curator->addEdge($a, $prerequisite, $b, $this->user);
        $this->curator->addEdge($b, $prerequisite, $c, $this->user);

        $this->expectExceptionMessage('循環を禁止しています');

        $this->curator->addEdge($c, $prerequisite, $a, $this->user);
    }

    public function test_a_cycle_is_allowed_for_loose_relations(): void
    {
        [$a, $b] = KnowledgeNode::factory()->count(2)->create();
        $related = $this->relationType('related_to');
        $this->curator->addEdge($a, $related, $b, $this->user);

        $this->curator->addEdge($b, $related, $a, $this->user);

        $this->assertSame(2, KnowledgeEdge::count());
    }

    public function test_deprecated_relations_no_longer_count_towards_a_cycle(): void
    {
        [$a, $b] = KnowledgeNode::factory()->count(2)->create();
        $prerequisite = $this->relationType('prerequisite_of');
        $edge = $this->curator->addEdge($a, $prerequisite, $b, $this->user);
        $this->curator->deprecateEdge($edge, $this->user, '誤り');

        $this->curator->addEdge($b, $prerequisite, $a, $this->user);

        $this->assertSame(KnowledgeNode::STATUS_DEPRECATED, $edge->refresh()->status);
    }

    private function relationType(string $key): KnowledgeRelationType
    {
        return KnowledgeRelationType::where('key', $key)->sole();
    }

    private function relation(string $key): int
    {
        return $this->relationType($key)->id;
    }
}
