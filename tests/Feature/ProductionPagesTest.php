<?php

namespace Tests\Feature;

use App\Jobs\RenderProduction;
use App\Models\ProductionPlan;
use App\Models\ProductionRender;
use App\Models\ProductionScene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionPagesTest extends TestCase
{
    use RefreshDatabase;

    private ProductionPlan $plan;

    private ProductionScene $scene;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Process::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->plan = ProductionPlan::factory()->create();
        $this->plan->production->update(['title' => '入力画像の品質確認の解説']);
        $this->scene = ProductionScene::factory()->create(['production_plan_id' => $this->plan->id, 'scene_key' => 's1', 'position' => 1, 'title' => '導入']);
    }

    public function test_guests_cannot_see_productions_or_their_files(): void
    {
        auth()->logout();
        $render = ProductionRender::factory()->completedFinal()->create();

        $this->get('/productions')->assertRedirect('/login');
        $this->get(route('production-renders.file', $render))->assertRedirect('/login');
    }

    public function test_the_list_and_the_detail_page_render(): void
    {
        $this->get('/productions')->assertOk()->assertSee('入力画像の品質確認の解説');

        $this->get(route('productions.show', $this->plan->production))
            ->assertOk()
            ->assertSee('第1版（候補）')
            ->assertSee('s1　導入')
            ->assertSee('まだ書き出していません。');
    }

    public function test_a_still_and_a_preview_can_be_requested_from_the_page(): void
    {
        Livewire::test('pages::productions.show', ['production' => $this->plan->production])
            ->call('renderStill', $this->scene->id)
            ->call('renderVideo', ProductionRender::KIND_PREVIEW)
            ->assertHasNoErrors()
            ->assertSee('静止画')
            ->assertSee('確認用')
            ->assertSee('待機中');

        $this->assertSame([ProductionRender::KIND_STILL, ProductionRender::KIND_PREVIEW], ProductionRender::orderBy('id')->pluck('kind')->all());
        Queue::assertPushed(RenderProduction::class, fn (RenderProduction $job) => $job->connection === 'remotion');
    }

    public function test_the_final_video_needs_a_confirmed_plan(): void
    {
        Livewire::test('pages::productions.show', ['production' => $this->plan->production])
            ->call('renderVideo', ProductionRender::KIND_FINAL)
            ->assertHasErrors('render');

        $this->assertSame(0, ProductionRender::count());
    }

    public function test_the_page_polls_while_a_render_is_in_progress(): void
    {
        $component = Livewire::test('pages::productions.show', ['production' => $this->plan->production]);
        $this->assertStringNotContainsString('wire:poll', $component->html());

        ProductionRender::factory()->create(['production_plan_id' => $this->plan->id, 'status' => ProductionRender::STATUS_RUNNING, 'stage' => ProductionRender::STAGE_RENDERING, 'progress' => 40]);

        $html = Livewire::test('pages::productions.show', ['production' => $this->plan->production])->html();
        $this->assertStringContainsString('wire:poll', $html);
        $this->assertStringContainsString('描画中', $html);
        $this->assertStringContainsString('40%', $html);
    }

    public function test_a_completed_render_is_served_and_shown(): void
    {
        $render = ProductionRender::factory()->completedFinal()->create(['production_plan_id' => $this->plan->id]);
        Storage::disk('local')->put($render->storage_path, 'fake-mp4');

        $this->get(route('productions.show', $this->plan->production))
            ->assertSee('最新の動画（完成版')
            ->assertSee(route('production-renders.file', $render));

        $response = $this->get(route('production-renders.file', $render))->assertOk();
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    public function test_an_unfinished_render_has_no_file(): void
    {
        $render = ProductionRender::factory()->create(['production_plan_id' => $this->plan->id]);

        $this->get(route('production-renders.file', $render))->assertNotFound();
    }
}
