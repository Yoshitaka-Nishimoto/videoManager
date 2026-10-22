<?php

namespace Tests\Feature;

use App\Models\KnowledgeCodeLink;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeRevision;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\CodeLocation;
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

    public function test_a_decision_created_from_a_concept_is_linked_as_its_inspiration(): void
    {
        $concept = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認']);

        $decision = $this->curator->createDecisionFromConcept($concept, '生成前に入力画像を検査する', '解像度と顔の向きを確認する', $this->user);

        $this->assertSame(KnowledgeNode::TYPE_DECISION, $decision->node_type);
        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $decision->status);
        $this->assertSame(KnowledgeNode::PROPOSED_BY_HUMAN, $decision->proposed_by);

        $edge = $decision->outgoingEdges()->sole();
        $this->assertTrue($edge->targetNode->is($concept));
        $this->assertSame(KnowledgeRelationType::KEY_INSPIRED_BY, $edge->relationType->key);

        $revision = $decision->revisions()->sole();
        $this->assertSame(KnowledgeRevision::ACTION_CREATE, $revision->action);
        $this->assertSame('概念「入力画像の品質確認」から作成', $revision->reason);
    }

    public function test_a_decision_can_only_be_created_from_an_active_concept(): void
    {
        $decision = KnowledgeNode::factory()->decision()->create();
        $deprecated = KnowledgeNode::factory()->create(['status' => KnowledgeNode::STATUS_DEPRECATED]);

        foreach ([$decision, $deprecated] as $node) {
            try {
                $this->curator->createDecisionFromConcept($node, '新しい設計判断', null, $this->user);
                $this->fail('概念以外・廃止済みの概念から設計判断が作れてしまいました。');
            } catch (KnowledgeRuleViolation) {
                // 期待どおり
            }
        }

        $this->assertSame(1, KnowledgeNode::where('node_type', KnowledgeNode::TYPE_DECISION)->count());
    }

    public function test_an_implementation_created_from_a_decision_implements_it(): void
    {
        $decision = KnowledgeNode::factory()->decision()->create(['title' => '生成前に入力画像を検査する']);

        $implementation = $this->curator->createImplementationFromDecision($decision, 'Runway の失敗防止：入力画像の検査', null, $this->user);

        $this->assertSame(KnowledgeNode::TYPE_IMPLEMENTATION, $implementation->node_type);
        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $implementation->status);
        $this->assertSame($this->user->id, $implementation->confirmed_by);

        $edge = $implementation->outgoingEdges()->sole();
        $this->assertTrue($edge->targetNode->is($decision));
        $this->assertSame(KnowledgeRelationType::KEY_IMPLEMENTS, $edge->relationType->key);
        $this->assertSame('設計判断「生成前に入力画像を検査する」から作成', $implementation->revisions()->sole()->reason);
    }

    public function test_an_implementation_can_only_be_created_from_an_active_decision(): void
    {
        $concept = KnowledgeNode::factory()->create();
        $deprecated = KnowledgeNode::factory()->decision()->create(['status' => KnowledgeNode::STATUS_DEPRECATED]);

        foreach ([$concept, $deprecated] as $node) {
            try {
                $this->curator->createImplementationFromDecision($node, '新しい実装', null, $this->user);
                $this->fail('設計判断以外・廃止済みの設計判断から実装が作れてしまいました。');
            } catch (KnowledgeRuleViolation) {
                // 期待どおり
            }
        }

        $this->assertSame(0, KnowledgeNode::where('node_type', KnowledgeNode::TYPE_IMPLEMENTATION)->count());
    }

    public function test_a_code_location_is_linked_as_confirmed_with_its_history(): void
    {
        $implementation = KnowledgeNode::factory()->implementation()->create();
        $location = new CodeLocation('app/Services/Runway/InputImageChecker.php', 'InputImageChecker::check', 7, 10, str_repeat('a', 40), 'graph_node');

        $link = $this->curator->addCodeLink($implementation, $location, $this->user, '解像度を確認している', '実装を確認した');

        $this->assertSame(
            ['app/Services/Runway/InputImageChecker.php', 'InputImageChecker::check', 7, 10, str_repeat('a', 40), 'graph_node', '解像度を確認している'],
            [$link->path, $link->symbol, $link->start_line, $link->end_line, $link->commit_sha, $link->graphify_node_id, $link->note],
        );
        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $link->status);
        $this->assertSame($this->user->id, $link->created_by);

        $revision = $link->revisions()->sole();
        $this->assertSame(KnowledgeRevision::ACTION_CREATE, $revision->action);
        $this->assertSame('実装を確認した', $revision->reason);
    }

    public function test_code_can_only_be_linked_to_an_active_implementation(): void
    {
        $location = new CodeLocation('app/Models/KnowledgeNode.php');

        foreach ([KnowledgeNode::factory()->decision()->create(), KnowledgeNode::factory()->implementation()->create(['status' => KnowledgeNode::STATUS_DEPRECATED])] as $node) {
            try {
                $this->curator->addCodeLink($node, $location, $this->user);
                $this->fail('実装以外・廃止済みの実装にコードがつながってしまいました。');
            } catch (KnowledgeRuleViolation) {
                // 期待どおり
            }
        }

        $this->assertSame(0, KnowledgeCodeLink::count());
    }

    public function test_the_same_code_location_cannot_be_linked_twice_even_after_deprecation(): void
    {
        $implementation = KnowledgeNode::factory()->implementation()->create();
        $fileLink = $this->curator->addCodeLink($implementation, new CodeLocation('app/Models/KnowledgeNode.php'), $this->user);
        $this->curator->addCodeLink($implementation, new CodeLocation('app/Models/KnowledgeNode.php', 'KnowledgeNode::codeLinks'), $this->user);
        $this->curator->deprecateCodeLink($fileLink, $this->user);

        $this->expectException(KnowledgeRuleViolation::class);

        $this->curator->addCodeLink($implementation, new CodeLocation('app/Models/KnowledgeNode.php'), $this->user);
    }

    public function test_a_candidate_code_location_can_be_confirmed_but_not_after_deprecation(): void
    {
        $link = KnowledgeCodeLink::factory()->create();

        $this->curator->confirmCodeLink($link, $this->user, 'コードを読んで確認した');
        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $link->refresh()->status);
        $this->assertSame($this->user->id, $link->confirmed_by);

        $this->curator->deprecateCodeLink($link, $this->user);
        $this->assertSame(KnowledgeNode::STATUS_DEPRECATED, $link->refresh()->status);
        $this->assertSame([KnowledgeRevision::ACTION_CONFIRM, KnowledgeRevision::ACTION_DEPRECATE], $link->revisions()->orderBy('id')->pluck('action')->all());

        $this->expectException(KnowledgeRuleViolation::class);

        $this->curator->confirmCodeLink($link, $this->user);
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
