<?php

namespace Tests\Feature;

use App\Actions\Productions\CancelProductionRender;
use App\Actions\Productions\RenderNotAllowedException;
use App\Actions\Productions\RequestProductionRender;
use App\Jobs\RenderProduction;
use App\Models\ProductionPlan;
use App\Models\ProductionRender;
use App\Models\ProductionScene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ProductionRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProductionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Process::fake([
            '*rev-parse*' => "0123456789abcdef0123456789abcdef01234567\n",
            '*status*' => '',
        ]);

        $this->user = User::factory()->create();
        $this->plan = ProductionPlan::factory()->confirmed()->create();
        $this->scene(['scene_key' => 's2', 'position' => 2, 'duration_seconds' => 3.5]);
        $this->scene(['scene_key' => 's1', 'position' => 1, 'duration_seconds' => 2]);
        ProductionScene::factory()->material()->create(['production_plan_id' => $this->plan->id, 'scene_key' => 'm1', 'position' => 1]);
    }

    public function test_a_preview_is_queued_with_the_played_scenes_in_order(): void
    {
        $render = $this->request(ProductionRender::KIND_PREVIEW);

        $this->assertSame(ProductionRender::COMPOSITION_PRODUCTION, $render->composition_id);
        $this->assertSame(ProductionRender::STATUS_QUEUED, $render->status);
        $this->assertSame(['s1', 's2'], array_column($render->input_props['scenes'], 'key'));
        $this->assertSame(['width' => 1920, 'height' => 1080, 'fps' => 30], array_intersect_key($render->input_props['settings'], array_flip(['width', 'height', 'fps'])));
        $this->assertSame(165, $render->duration_in_frames); // (2 + 3.5) 秒 × 30
        $this->assertSame('0.50', $render->scale);
        $this->assertSame(config('remotion.kinds.preview.crf'), $render->crf);
        $this->assertSame('0123456789abcdef0123456789abcdef01234567', $render->code_version);
        Queue::assertPushed(RenderProduction::class, fn (RenderProduction $job) => $job->render->is($render));
    }

    public function test_the_size_follows_the_ratio_of_the_production(): void
    {
        $this->plan->production->update(['ratio' => '720:1280']);

        $render = $this->request(ProductionRender::KIND_PREVIEW);

        $this->assertSame([1080, 1920], [$render->width, $render->height]);
    }

    public function test_uncommitted_remotion_changes_are_marked_in_the_code_version(): void
    {
        Process::fake([
            '*rev-parse*' => "0123456789abcdef0123456789abcdef01234567\n",
            '*status*' => " M remotion/src/theme.ts\n",
        ]);

        $render = $this->request(ProductionRender::KIND_PREVIEW);

        $this->assertStringEndsWith('-dirty', $render->code_version);
    }

    public function test_a_still_renders_one_scene_at_its_middle_frame(): void
    {
        $scene = $this->plan->videoScenes()->where('scene_key', 's2')->sole();

        $render = $this->request(ProductionRender::KIND_STILL, $scene);

        $this->assertSame(ProductionRender::COMPOSITION_SCENE, $render->composition_id);
        $this->assertSame('s2', $render->input_props['scene']['key']);
        $this->assertSame(105, $render->duration_in_frames);
        $this->assertSame(52, $render->frame);
        $this->assertTrue($render->scene->is($scene));
    }

    public function test_a_still_needs_a_remotion_scene_of_the_plan(): void
    {
        $runway = ProductionScene::factory()->runway()->create(['production_plan_id' => $this->plan->id, 'scene_key' => 's3', 'position' => 3]);
        $other = ProductionScene::factory()->create();

        foreach ([null, $runway, $other] as $scene) {
            try {
                $this->request(ProductionRender::KIND_STILL, $scene);
                $this->fail('書き出しが受け付けられました。');
            } catch (RenderNotAllowedException) {
                //
            }
        }

        $this->assertSame(0, ProductionRender::count());
    }

    public function test_a_video_waits_until_runway_outputs_are_selected(): void
    {
        ProductionScene::factory()->runway()->create(['production_plan_id' => $this->plan->id, 'scene_key' => 's3', 'position' => 3]);

        $this->expectException(RenderNotAllowedException::class);
        $this->expectExceptionMessage('s3');

        $this->request(ProductionRender::KIND_PREVIEW);
    }

    public function test_only_a_confirmed_plan_can_be_rendered_as_the_final_video(): void
    {
        $this->plan->update(['status' => ProductionPlan::STATUS_CANDIDATE]);

        $this->assertSame(ProductionRender::KIND_PREVIEW, $this->request(ProductionRender::KIND_PREVIEW)->kind);

        $this->expectException(RenderNotAllowedException::class);

        $this->request(ProductionRender::KIND_FINAL);
    }

    public function test_the_job_records_progress_and_the_output(): void
    {
        Storage::fake('local');
        $render = $this->request(ProductionRender::KIND_PREVIEW);
        $progress = [];
        Process::fake([
            '*render.mjs*' => Process::describe()
                ->output('{"type":"stage","stage":"bundling"}')
                ->output('Downloading Chrome Headless Shell - 98.9 Mb/98.9 Mb')
                ->output('{"type":"progress","stage":"rendering","progress":42,"renderedFrames":70,"encodedFrames":40}')
                ->output('{"type":"progress","stage":"muxing","progress":100,"renderedFrames":165,"encodedFrames":165}')
                ->output('{"type":"done","outputPath":"x","sizeBytes":62355,"renderMs":15520,"width":960,"height":540,"fps":30,"durationInFrames":165,"frame":null,"remotionVersion":"4.0.534"}')
                ->exitCode(0),
        ]);
        ProductionRender::updated(function (ProductionRender $model) use (&$progress) {
            if ($model->wasChanged('progress')) {
                $progress[] = $model->progress;
            }
        });

        (new RenderProduction($render))->handle();

        $render->refresh();
        $this->assertSame(ProductionRender::STATUS_COMPLETED, $render->status);
        $this->assertSame([42, 99, 100], $progress); // 完了までは 100 にしない
        $this->assertSame("productions/renders/{$render->id}-preview.mp4", $render->storage_path);
        $this->assertSame('video/mp4', $render->mime_type);
        $this->assertSame([960, 540, 62355, 15520, '4.0.534'], [$render->width, $render->height, $render->size_bytes, $render->render_ms, $render->remotion_version]);
        $this->assertStringContainsString('Downloading Chrome', $render->log);
        $this->assertNotNull($render->completed_at);
        Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'scripts/render.mjs')
            && $process->path === config('remotion.project_path'));
    }

    public function test_a_still_sets_the_preview_of_its_scene(): void
    {
        Storage::fake('local');
        $scene = $this->plan->videoScenes()->where('scene_key', 's1')->sole();
        $render = $this->request(ProductionRender::KIND_STILL, $scene);
        Process::fake([
            '*render.mjs*' => Process::describe()
                ->output('{"type":"done","outputPath":"x","sizeBytes":101499,"renderMs":2000,"width":1920,"height":1080,"fps":30,"durationInFrames":60,"frame":30,"remotionVersion":"4.0.534"}')
                ->exitCode(0),
        ]);

        (new RenderProduction($render))->handle();

        $this->assertSame("productions/renders/{$render->id}-still.png", $scene->refresh()->preview_path);
        $this->assertSame('image/png', $render->refresh()->mime_type);
    }

    public function test_a_failed_render_keeps_the_error_and_the_log(): void
    {
        Storage::fake('local');
        $render = $this->request(ProductionRender::KIND_PREVIEW);
        Process::fake([
            '*render.mjs*' => Process::describe()
                ->output('{"type":"stage","stage":"bundling"}')
                ->output('{"type":"error","message":"Error: Failed to launch the browser process!\n    at Socket.onClose (index.mjs:4151:14)"}')
                ->exitCode(1),
        ]);
        $job = new RenderProduction($render);

        try {
            $job->handle();
            $this->fail('例外が投げられませんでした。');
        } catch (RuntimeException $e) {
            $this->assertSame('Error: Failed to launch the browser process!', $e->getMessage());
            (new RenderProduction($render->refresh()))->failed($e);
        }

        $render->refresh();
        $this->assertSame(ProductionRender::STATUS_FAILED, $render->status);
        $this->assertSame('Error: Failed to launch the browser process!', $render->error_message);
        $this->assertStringContainsString('Socket.onClose', $render->log);
        $this->assertNull($render->stage);
    }

    private function request(string $kind, ?ProductionScene $scene = null): ProductionRender
    {
        return app(RequestProductionRender::class)->handle($this->plan->fresh(), $kind, $this->user, $scene)->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function scene(array $attributes): ProductionScene
    {
        return ProductionScene::factory()->create(['production_plan_id' => $this->plan->id, ...$attributes]);
    }

    public function test_a_queued_render_can_be_cancelled_and_its_job_does_nothing(): void
    {
        $render = $this->request(ProductionRender::KIND_PREVIEW);
        Process::fake();

        Livewire::actingAs($this->user)->test('pages::productions.show', ['production' => $this->plan->production])
            ->call('cancelRender', $render->id)
            ->assertSee('取り消しました。');

        (new RenderProduction($render))->handle();

        $this->assertSame([ProductionRender::STATUS_FAILED, '取り消しました。'], [$render->refresh()->status, $render->error_message]);
        Process::assertDidntRun(fn ($process) => str_contains(implode(' ', (array) $process->command), 'render.mjs'));
    }

    public function test_a_running_render_is_stopped_when_cancelled(): void
    {
        Storage::fake('local');
        Sleep::fake();
        $render = $this->request(ProductionRender::KIND_PREVIEW);
        Process::fake([
            '*render.mjs*' => Process::describe()
                ->output('{"type":"stage","stage":"bundling"}')
                ->iterations(5)
                ->exitCode(0),
        ]);
        // 書き出しが始まった直後に、画面から取り消された状態にする。
        ProductionRender::updated(function (ProductionRender $model) {
            if ($model->wasChanged('stage') && $model->stage === ProductionRender::STAGE_BUNDLING && $model->status === ProductionRender::STATUS_RUNNING) {
                app(CancelProductionRender::class)->handle($model);
            }
        });
        $job = new RenderProduction($render);

        try {
            $job->handle();
            $this->fail('例外が投げられませんでした。');
        } catch (RuntimeException $e) {
            $this->assertSame('取り消しました。', $e->getMessage());
            $job->failed($e);
        }

        $this->assertSame([ProductionRender::STATUS_FAILED, '取り消しました。'], [$render->refresh()->status, $render->error_message]);
    }

    public function test_a_render_left_running_by_a_crash_is_not_run_again(): void
    {
        $render = $this->request(ProductionRender::KIND_STILL, $this->plan->videoScenes()->first());
        $render->update(['status' => ProductionRender::STATUS_RUNNING, 'stage' => ProductionRender::STAGE_BUNDLING]);
        Process::fake();

        (new RenderProduction($render))->handle();

        $this->assertSame(ProductionRender::STATUS_FAILED, $render->refresh()->status);
        $this->assertStringContainsString('途中で止まりました', $render->error_message);
        Process::assertDidntRun(fn ($process) => str_contains(implode(' ', (array) $process->command), 'render.mjs'));
    }
}
