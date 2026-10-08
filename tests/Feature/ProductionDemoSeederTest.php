<?php

namespace Tests\Feature;

use App\Actions\Productions\RequestProductionRender;
use App\Models\KnowledgeNode;
use App\Models\ProductionPlan;
use App\Models\ProductionRender;
use App\Models\ProductionScene;
use App\Models\User;
use App\Models\VideoProduction;
use Database\Seeders\ProductionDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductionDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_renderable_demo_production(): void
    {
        Queue::fake();
        Process::fake();
        $user = User::factory()->create();

        $this->seed(ProductionDemoSeeder::class);

        $production = VideoProduction::sole();
        $this->assertStringStartsWith(ProductionDemoSeeder::MARK, $production->title);
        $this->assertStringStartsWith(ProductionDemoSeeder::MARK, $production->decisionNode->title);
        $this->assertSame([1, 2], $production->plans()->orderBy('version')->pluck('version')->all());
        $this->assertSame(['s1', 's2', 's3', 's4'], $production->confirmedPlan->videoScenes->pluck('scene_key')->all());

        $render = app(RequestProductionRender::class)->handle($production->confirmedPlan, ProductionRender::KIND_FINAL, $user);
        $this->assertSame(450, $render->duration_in_frames); // (3 + 5 + 4 + 3) 秒 × 30
    }

    public function test_seeding_again_replaces_the_demo_and_remove_leaves_real_data(): void
    {
        Storage::fake('local');
        $real = VideoProduction::factory()->create(['title' => '実際の制作']);

        $this->seed(ProductionDemoSeeder::class);
        $demo = VideoProduction::where('title', 'like', ProductionDemoSeeder::MARK.'%')->sole();
        $render = ProductionRender::factory()->completedFinal()->create(['production_plan_id' => $demo->confirmedPlan->id]);
        Storage::disk('local')->put($render->storage_path, 'mp4');

        $this->seed(ProductionDemoSeeder::class);
        $this->assertSame(1, VideoProduction::where('title', 'like', ProductionDemoSeeder::MARK.'%')->count());
        Storage::disk('local')->assertMissing($render->storage_path);

        ProductionDemoSeeder::remove();

        $this->assertSame([$real->id], VideoProduction::pluck('id')->all());
        $this->assertSame(0, KnowledgeNode::where('title', 'like', ProductionDemoSeeder::MARK.'%')->count());
        $this->assertSame(0, ProductionRender::count());
        $this->assertSame($real->plans()->count(), ProductionPlan::count());
        $this->assertSame(0, ProductionScene::count());
    }
}
