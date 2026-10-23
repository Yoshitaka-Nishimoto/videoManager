<?php

namespace Tests\Feature;

use App\Models\KnowledgeCodeLink;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeSource;
use App\Models\User;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Concerns\BuildsFakeRepository;
use Tests\TestCase;

class KnowledgePagesTest extends TestCase
{
    use BuildsFakeRepository;
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

    public function test_a_deleted_video_is_shown_as_a_source_without_a_link(): void
    {
        $concept = KnowledgeNode::factory()->create();
        $source = KnowledgeSource::factory()->create([
            'sourceable_type' => $concept->getMorphClass(),
            'sourceable_id' => $concept->id,
        ]);
        $source->video->update(['title' => '削除した動画']);
        $source->video->delete();

        $this->get(route('knowledge.show', $concept))
            ->assertOk()
            ->assertSee('削除した動画')
            ->assertSee('（削除済み）')
            ->assertDontSee(route('videos.show', $source->video_id));
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

    public function test_a_decision_can_be_created_from_a_concept_page(): void
    {
        $concept = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認']);

        Livewire::test('pages::knowledge.show', ['node' => $concept])
            ->assertSee('この概念から設計判断を作る')
            ->set('decisionTitle', '生成前に入力画像を検査する')
            ->set('reason', '失敗を減らすため')
            ->call('createDecision')
            ->assertHasNoErrors();

        $decision = KnowledgeNode::where('node_type', KnowledgeNode::TYPE_DECISION)->sole();
        $this->assertSame('生成前に入力画像を検査する', $decision->title);
        $this->assertSame('失敗を減らすため', $decision->revisions()->sole()->reason);

        $this->get(route('knowledge.show', $concept))->assertSee('着想を与えた')->assertSee('生成前に入力画像を検査する');
    }

    public function test_creating_a_decision_redirects_to_it_and_requires_a_title(): void
    {
        $concept = KnowledgeNode::factory()->create();

        Livewire::test('pages::knowledge.show', ['node' => $concept])
            ->call('createDecision')
            ->assertHasErrors(['decisionTitle' => 'required']);

        $component = Livewire::test('pages::knowledge.show', ['node' => $concept])
            ->set('decisionTitle', '生成前に入力画像を検査する')
            ->call('createDecision');

        $component->assertRedirect(route('knowledge.show', KnowledgeNode::where('node_type', KnowledgeNode::TYPE_DECISION)->sole()));
    }

    public function test_the_create_decision_form_is_only_on_active_concepts(): void
    {
        $decision = KnowledgeNode::factory()->decision()->create();
        $deprecated = KnowledgeNode::factory()->create(['status' => KnowledgeNode::STATUS_DEPRECATED]);

        $this->get(route('knowledge.show', $decision))->assertOk()->assertDontSee('この概念から設計判断を作る');
        $this->get(route('knowledge.show', $deprecated))->assertOk()->assertDontSee('この概念から設計判断を作る');
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

    public function test_an_implementation_can_be_created_from_a_decision_page(): void
    {
        $decision = KnowledgeNode::factory()->decision()->create(['title' => '生成前に入力画像を検査する']);

        Livewire::test('pages::knowledge.show', ['node' => $decision])
            ->assertSee('この設計判断の実装を作る')
            ->call('createImplementation')
            ->assertHasErrors(['implementationTitle' => 'required'])
            ->set('implementationTitle', 'Runway の失敗防止：入力画像の検査')
            ->call('createImplementation')
            ->assertRedirect(route('knowledge.show', KnowledgeNode::where('node_type', KnowledgeNode::TYPE_IMPLEMENTATION)->sole()));

        $this->get(route('knowledge.show', KnowledgeNode::factory()->create()))->assertOk()->assertDontSee('この設計判断の実装を作る');
    }

    public function test_code_is_linked_by_choosing_a_suggested_file_and_symbol(): void
    {
        $this->buildFakeRepository();
        $implementation = KnowledgeNode::factory()->implementation()->create();

        Livewire::test('pages::knowledge.show', ['node' => $implementation])
            ->assertSee('コードの場所をつなぐ')
            ->set('codePath', 'checker')
            ->assertSee('app/Services/Runway/InputImageChecker.php')
            ->call('chooseCodePath', 'app/Services/Runway/InputImageChecker.php')
            ->assertSee('InputImageChecker::check')
            ->set('codeSymbol', 'InputImageChecker::check')
            ->set('codeNote', '解像度を確認している')
            ->call('addCodeLink')
            ->assertHasNoErrors()
            ->assertSet('codePath', '')
            ->assertSee('https://github.com/owner/repo/blob/'.self::FAKE_COMMIT.'/app/Services/Runway/InputImageChecker.php#L7', false);

        $link = $implementation->codeLinks()->sole();
        $this->assertSame([7, self::FAKE_COMMIT], [$link->start_line, $link->commit_sha]);
    }

    public function test_a_file_outside_the_repository_folders_is_shown_as_a_message(): void
    {
        $this->buildFakeRepository();
        $implementation = KnowledgeNode::factory()->implementation()->create();

        Livewire::test('pages::knowledge.show', ['node' => $implementation])
            ->set('codePath', 'vendor/laravel/Checker.php')
            ->call('addCodeLink')
            ->assertHasErrors('action');

        $this->assertSame(0, KnowledgeCodeLink::count());
    }

    public function test_a_concept_page_shows_the_code_of_implementations_of_its_decisions(): void
    {
        $concept = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認']);
        $decision = KnowledgeNode::factory()->decision()->create();
        $implementation = KnowledgeNode::factory()->implementation()->create(['title' => 'Runway の失敗防止：入力画像の検査']);
        $retired = KnowledgeNode::factory()->implementation()->create(['title' => '廃止した実装', 'status' => KnowledgeNode::STATUS_DEPRECATED]);
        KnowledgeEdge::factory()->create(['source_node_id' => $decision->id, 'target_node_id' => $concept->id, 'relation_type_id' => $this->relation(KnowledgeRelationType::KEY_INSPIRED_BY)]);
        KnowledgeEdge::factory()->create(['source_node_id' => $implementation->id, 'target_node_id' => $decision->id, 'relation_type_id' => $this->relation(KnowledgeRelationType::KEY_IMPLEMENTS)]);
        KnowledgeEdge::factory()->create(['source_node_id' => $retired->id, 'target_node_id' => $decision->id, 'relation_type_id' => $this->relation(KnowledgeRelationType::KEY_IMPLEMENTS)]);
        KnowledgeCodeLink::factory()->for($implementation, 'node')->create(['path' => 'app/Services/Runway/InputImageChecker.php', 'symbol' => 'InputImageChecker::check']);
        KnowledgeCodeLink::factory()->for($implementation, 'node')->create(['path' => 'app/Old.php', 'status' => KnowledgeNode::STATUS_DEPRECATED]);

        foreach ([$concept, $decision] as $node) {
            $this->get(route('knowledge.show', $node))
                ->assertOk()
                ->assertSee('実装とコード')
                ->assertSee('Runway の失敗防止：入力画像の検査')
                ->assertSee('InputImageChecker::check')
                ->assertDontSee('app/Old.php');

            // 廃止した実装は「関係」の一覧には残るが、「実装とコード」には出さない。
            $implementations = Livewire::test('pages::knowledge.show', ['node' => $node])->instance()->implementations;
            $this->assertSame(['Runway の失敗防止：入力画像の検査'], $implementations->pluck('title')->all());
        }
    }

    public function test_code_links_are_adopted_or_deprecated_only_from_their_implementation(): void
    {
        $link = KnowledgeCodeLink::factory()->create();
        $other = KnowledgeNode::factory()->implementation()->create();

        Livewire::test('pages::knowledge.show', ['node' => $other])
            ->call('deprecateCodeLink', $link->id)
            ->assertNotFound();

        Livewire::test('pages::knowledge.show', ['node' => $link->node])
            ->call('confirmCodeLink', $link->id)
            ->assertSee('確認済み');

        $this->assertSame(KnowledgeNode::STATUS_CONFIRMED, $link->refresh()->status);
    }

    public function test_implementations_are_found_by_the_path_of_their_code_regardless_of_status(): void
    {
        $implementation = KnowledgeNode::factory()->implementation()->confirmed()->create(['title' => 'AI 呼び出しのモデル選択']);
        KnowledgeCodeLink::factory()->for($implementation, 'node')->create(['path' => 'app/Services/Ai/GeminiModels.php', 'symbol' => 'GeminiModels::attempt', 'graphify_node_id' => 'app_services_ai_geminimodels_geminimodels_attempt']);
        $retired = KnowledgeNode::factory()->implementation()->create(['title' => '古い実装']);
        KnowledgeCodeLink::factory()->for($retired, 'node')->create(['path' => 'app/Services/Ai/GeminiModels.php', 'status' => KnowledgeNode::STATUS_DEPRECATED]);

        Livewire::test('pages::knowledge.index')
            ->set('q', 'Services/Ai/GeminiModels')
            ->assertSee('状態にかかわらず表示しています')
            ->assertSee('AI 呼び出しのモデル選択')
            ->assertSee('コード：app/Services/Ai/GeminiModels.php GeminiModels::attempt')
            ->assertDontSee('古い実装')
            ->set('q', 'app_services_ai_geminimodels_geminimodels_attempt')
            ->set('status', '')
            ->assertSee('AI 呼び出しのモデル選択');
    }

    private function relation(string $key): int
    {
        return KnowledgeRelationType::where('key', $key)->value('id');
    }
}
