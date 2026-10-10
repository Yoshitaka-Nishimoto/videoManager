<?php

namespace Tests\Feature;

use App\Models\KnowledgeNode;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Models\VideoProduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/productions/create')->assertRedirect('/login');
    }

    public function test_choosing_a_genre_sets_the_default_structure_until_one_is_chosen(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::productions.create')
            ->set('genre', 'healthy_longevity')
            ->assertSet('structure', 'compare')
            ->set('genre', 'video_creation')
            ->assertSet('structure', 'howto')
            ->set('structure', 'short')
            ->set('genre', 'ai')
            ->assertSet('structure', 'short');
    }

    public function test_opening_from_a_decision_fills_the_title_and_brief(): void
    {
        $this->actingAs(User::factory()->create());
        $decision = $this->decision();

        Livewire::test('pages::productions.create', ['decisionId' => $decision->id])
            ->assertSet('title', '生成の前に入力画像を確認する')
            ->assertSet('brief', '解像度を確認する。顔の向きを確認する。明るさを確認する。');
    }

    public function test_a_production_is_created_with_the_first_plan_and_scenes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $decision = $this->decision();

        Livewire::test('pages::productions.create', ['decisionId' => $decision->id])
            ->set('genre', 'ai')
            ->set('duration', 60)
            ->set('tone', 'upbeat')
            ->set('audience', 'practitioner')
            ->set('ratio', '720:1280')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect(route('productions.show', VideoProduction::sole()));

        $production = VideoProduction::sole();
        $this->assertSame(['ai', '720:1280', 60, $user->id], [$production->genre, $production->ratio, $production->target_duration_seconds, $production->created_by]);
        $this->assertTrue($production->decisionNode->is($decision));

        $plan = $production->plans()->sole();
        $this->assertSame([1, ProductionPlan::STATUS_CANDIDATE], [$plan->version, $plan->status]);
        $this->assertSame(['explain', 'practitioner', 'テンポよく'], [$plan->settings['structure'], $plan->settings['audience'], $plan->settings['style']['tone']]);

        // 解説型：タイトル → 箇条書き → 図解 → まとめ。60 秒を 2:3:3:2 で分ける。
        $scenes = $plan->videoScenes;
        $this->assertSame(['remotion.title', 'remotion.text', 'remotion.diagram', 'remotion.title'], $scenes->pluck('scene_type')->all());
        $this->assertSame(['12.00', '18.00', '18.00', '12.00'], $scenes->pluck('duration_seconds')->all());
        $this->assertSame(['解像度を確認する', '顔の向きを確認する', '明るさを確認する'], $scenes[1]->content['text']['items']);
        $this->assertSame(['a', 'b', 'c'], array_column($scenes[2]->content['diagram']['nodes'], 'id'));
        $this->assertSame('lower_third', $scenes[3]->content['title']['layout']);
    }

    public function test_the_compare_structure_leaves_the_chart_values_for_a_person(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::productions.create', ['decisionId' => $this->decision()->id])
            ->set('genre', 'healthy_longevity')
            ->call('create')
            ->assertHasNoErrors();

        $chart = VideoProduction::sole()->plans()->sole()->videoScenes->firstWhere('scene_type', 'remotion.chart');
        $this->assertSame([0, 0], $chart->content['chart']['series'][0]['values']);
    }

    public function test_required_choices_are_reported(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::productions.create')
            ->call('create')
            ->assertHasErrors(['decisionId' => 'required', 'genre' => 'required', 'structure' => 'required', 'title' => 'required'])
            ->assertSee('ジャンル');

        $this->assertSame(0, VideoProduction::count());
    }

    public function test_only_a_decision_node_can_be_the_origin(): void
    {
        $this->actingAs(User::factory()->create());
        $concept = KnowledgeNode::factory()->create();

        Livewire::test('pages::productions.create', ['decisionId' => $concept->id])
            ->set('genre', 'ai')
            ->set('title', 'タイトル')
            ->call('create')
            ->assertHasErrors(['decisionId' => 'exists']);
    }

    public function test_a_decision_page_links_to_the_create_page(): void
    {
        $this->actingAs(User::factory()->create());
        $decision = $this->decision();

        $this->get(route('knowledge.show', $decision))
            ->assertSee(route('productions.create', ['decision' => $decision->id]), false);
    }

    private function decision(): KnowledgeNode
    {
        return KnowledgeNode::factory()->decision()->create([
            'title' => '生成の前に入力画像を確認する',
            'description' => '解像度を確認する。顔の向きを確認する。明るさを確認する。',
        ]);
    }
}
