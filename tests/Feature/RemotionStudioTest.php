<?php

namespace Tests\Feature;

use App\Models\ProductionPlan;
use App\Models\ProductionScene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RemotionStudioTest extends TestCase
{
    use RefreshDatabase;

    private string $build;

    protected function setUp(): void
    {
        parent::setUp();

        // 本物のバンドルの代わりに、テスト用の小さなバンドルを置く。
        $this->build = storage_path('framework/testing/remotion-studio');
        File::ensureDirectoryExists($this->build);
        File::put($this->build.'/index.html', '<html><head><script src="/remotion-studio/bundle.js"></script></head><body></body></html>');
        File::put($this->build.'/bundle.js', 'console.log("bundle");');
        File::put(dirname($this->build).'/secret.txt', 'secret');
        config(['remotion.project_path' => dirname($this->build), 'remotion.studio_build_directory' => 'remotion-studio']);

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->build);
        File::delete(dirname($this->build).'/secret.txt');

        parent::tearDown();
    }

    public function test_guests_cannot_open_the_studio(): void
    {
        auth()->logout();

        $this->get('/remotion-studio/')->assertRedirect('/login');
    }

    public function test_the_studio_page_hides_rendering_and_serves_bundle_files(): void
    {
        $this->get('/remotion-studio/')
            ->assertOk()
            ->assertSee('MutationObserver', false)
            ->assertDontSee('videoManagerStudioInput', false);

        $file = $this->get('/remotion-studio/bundle.js')->assertOk()->baseResponse->getFile();
        $this->assertSame(realpath($this->build.'/bundle.js'), $file->getRealPath());
    }

    public function test_files_outside_the_bundle_are_not_served(): void
    {
        $this->get('/remotion-studio/../secret.txt')->assertDontSee('secret');
        $this->get('/remotion-studio/%2e%2e/secret.txt')->assertDontSee('secret');
    }

    public function test_a_plan_is_embedded_into_the_studio_page(): void
    {
        $plan = ProductionPlan::factory()->create();
        ProductionScene::factory()->create(['production_plan_id' => $plan->id, 'scene_key' => 's1', 'position' => 1, 'title' => '導入</script>']);

        $html = $this->get("/remotion-studio/plans/{$plan->id}/s1/")->assertOk()->getContent();

        $this->assertStringContainsString('window.videoManagerStudioInput = {', $html);
        $this->assertStringContainsString('window.videoManagerStudioSceneKey = "s1"', $html);
        $this->assertStringNotContainsString('導入</script>', $html); // スクリプトを閉じられないようにする
        $this->assertLessThan(strpos($html, '/remotion-studio/bundle.js'), strpos($html, 'videoManagerStudioInput'));
    }

    public function test_an_unknown_plan_is_not_found(): void
    {
        $this->get('/remotion-studio/plans/999999/')->assertNotFound();
    }

    public function test_a_missing_bundle_explains_how_to_build_it(): void
    {
        File::delete($this->build.'/index.html');

        $this->get('/remotion-studio/')->assertNotFound();
    }
}
