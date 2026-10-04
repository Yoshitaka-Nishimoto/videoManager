<?php

namespace Tests\Feature;

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeSource;
use App\Models\User;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeRelationTypeSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_list_shows_candidates_first_and_filters_by_status(): void
    {
        KnowledgeNode::factory()->create(['title' => '候補の概念']);
        KnowledgeNode::factory()->confirmed()->create(['title' => '確定した概念']);

        $this->get('/knowledge')->assertOk()->assertSee('候補の概念')->assertDontSee('確定した概念');

        Livewire::test('pages::knowledge.index')
            ->set('status', KnowledgeNode::STATUS_CONFIRMED)
            ->assertSee('確定した概念')
            ->assertDontSee('候補の概念');
    }

    public function test_a_candidate_can_be_adopted_from_the_list(): void
    {
        $node = KnowledgeNode::factory()->create();

        Livewire::test('pages::knowledge.index')->call('confirm', $node->id);

        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $node->refresh()->status);
    }

    public function test_the_detail_page_shows_sources_and_relations(): void
    {
        $concept = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認']);
        $analysis = KnowledgeNode::factory()->forAnalysis()->create(['title' => '動画の分析（第1版）']);
        $edge = KnowledgeEdge::factory()->create([
            'source_node_id' => $concept->id,
            'target_node_id' => $analysis->id,
            'relation_type_id' => KnowledgeRelationType::where('key', 'extracted_from')->value('id'),
        ]);
        KnowledgeSource::factory()->create([
            'sourceable_type' => $edge->getMorphClass(),
            'sourceable_id' => $edge->id,
            'start_seconds' => 62,
            'end_seconds' => 98,
            'excerpt' => '品質確認の説明',
        ]);

        $this->get(route('knowledge.show', $concept))
            ->assertOk()
            ->assertSee('入力画像の品質確認')
            ->assertSee('分析から抽出した')
            ->assertSee('動画の分析（第1版）')
            ->assertSee('1:02〜1:38')
            ->assertSee('品質確認の説明');
    }

    public function test_adopting_and_editing_on_the_detail_page(): void
    {
        $node = KnowledgeNode::factory()->create(['title' => '画像品質確認']);

        Livewire::test('pages::knowledge.show', ['node' => $node])
            ->set('reason', '動画で確認')
            ->call('confirm')
            ->call('edit')
            ->set('title', '入力画像の品質確認')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('入力画像の品質確認')
            ->assertSee('採用（動画で確認）');

        $this->assertSame('入力画像の品質確認', $node->refresh()->title);
        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $node->status);
    }

    public function test_adding_a_relation_by_searching_for_the_target(): void
    {
        $implementation = KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_IMPLEMENTATION, 'title' => 'Runway 失敗防止']);
        $concept = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認']);

        Livewire::test('pages::knowledge.show', ['node' => $implementation])
            ->set('relationSearch', '品質')
            ->assertSee('入力画像の品質確認')
            ->set('relationTypeId', (string) KnowledgeRelationType::where('key', 'implements')->value('id'))
            ->set('relationTargetId', (string) $concept->id)
            ->call('addRelation')
            ->assertHasNoErrors();

        $this->assertTrue($implementation->outgoingEdges()->where('target_node_id', $concept->id)->exists());
    }

    public function test_a_rule_violation_is_shown_as_a_message(): void
    {
        [$a, $b] = KnowledgeNode::factory()->count(2)->create();

        Livewire::test('pages::knowledge.show', ['node' => $a])
            ->set('relationTypeId', (string) KnowledgeRelationType::where('key', 'implements')->value('id'))
            ->set('relationTargetId', (string) $b->id)
            ->call('addRelation')
            ->assertHasErrors('action');
    }

    public function test_merging_redirects_to_the_surviving_node(): void
    {
        $from = KnowledgeNode::factory()->create(['title' => '画像品質確認']);
        $into = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認']);

        Livewire::test('pages::knowledge.show', ['node' => $from])
            ->set('mergeSearch', '入力画像')
            ->set('mergeTargetId', (string) $into->id)
            ->call('merge')
            ->assertRedirect(route('knowledge.show', $into));

        $this->assertSame($into->id, $from->refresh()->merged_into_id);
    }

    public function test_an_edge_of_another_node_cannot_be_changed_from_this_page(): void
    {
        $node = KnowledgeNode::factory()->create();
        $unrelated = KnowledgeEdge::factory()->create();

        Livewire::test('pages::knowledge.show', ['node' => $node])
            ->call('deprecateEdge', $unrelated->id)
            ->assertNotFound();

        $this->assertSame(KnowledgeNode::STATUS_CANDIDATE, $unrelated->refresh()->status);
    }
}
