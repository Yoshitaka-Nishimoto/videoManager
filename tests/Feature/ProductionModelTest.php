<?php

namespace Tests\Feature;

use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\ProductionPlan;
use App\Models\ProductionRender;
use App\Models\ProductionScene;
use App\Models\RunwayTask;
use App\Models\RunwayTaskInput;
use App\Models\RunwayTaskOutput;
use App\Models\User;
use App\Models\VideoProduction;
use App\Services\Knowledge\KnowledgeCurator;
use App\Services\Knowledge\KnowledgeRuleViolation;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scene_keeps_every_runway_task_and_selects_one_output(): void
    {
        $scene = ProductionScene::factory()->runway()->create();
        $first = RunwayTask::factory()->failed()->create(['production_scene_id' => $scene->id]);
        $retry = RunwayTask::factory()->completed()->create(['production_scene_id' => $scene->id, 'retry_of_id' => $first->id]);
        $output = RunwayTaskOutput::factory()->create(['runway_task_id' => $retry->id]);

        $scene->update(['selected_output_id' => $output->id, 'status' => ProductionScene::STATUS_READY]);

        $this->assertCount(2, $scene->runwayTasks);
        $this->assertTrue($scene->selectedOutput->is($output));
        $this->assertTrue($retry->retryOf->is($first));
        $this->assertSame('image_to_video', $scene->runwayTaskType());
    }

    public function test_a_previous_output_can_be_used_as_the_input_of_the_next_task(): void
    {
        $image = RunwayTaskOutput::factory()->create(['media_type' => RunwayTaskOutput::MEDIA_IMAGE]);
        $input = RunwayTaskInput::factory()->create(['source_output_id' => $image->id]);

        $this->assertTrue($input->sourceOutput->is($image));
    }

    public function test_scene_keys_and_positions_are_unique_within_a_plan(): void
    {
        $plan = ProductionPlan::factory()->create();
        ProductionScene::factory()->create(['production_plan_id' => $plan->id, 'scene_key' => 's1', 'position' => 1]);

        $this->expectException(QueryException::class);

        ProductionScene::factory()->create(['production_plan_id' => $plan->id, 'scene_key' => 's1', 'position' => 2]);
    }

    public function test_plans_are_split_into_played_scenes_and_materials(): void
    {
        $plan = ProductionPlan::factory()->create();
        ProductionScene::factory()->create(['production_plan_id' => $plan->id, 'scene_key' => 's2', 'position' => 2]);
        ProductionScene::factory()->create(['production_plan_id' => $plan->id, 'scene_key' => 's1', 'position' => 1]);
        ProductionScene::factory()->material()->create(['production_plan_id' => $plan->id, 'scene_key' => 'm1', 'position' => 1]);

        $this->assertSame(['s1', 's2'], $plan->videoScenes->pluck('scene_key')->all());
        $this->assertSame(['m1'], $plan->materials->pluck('scene_key')->all());
    }

    public function test_a_production_finds_its_confirmed_plan(): void
    {
        $production = VideoProduction::factory()->create();
        ProductionPlan::factory()->create(['video_production_id' => $production->id, 'version' => 1, 'status' => ProductionPlan::STATUS_DEPRECATED]);
        $confirmed = ProductionPlan::factory()->confirmed()->create(['video_production_id' => $production->id, 'version' => 2]);
        ProductionPlan::factory()->create(['video_production_id' => $production->id, 'version' => 3]);

        $this->assertTrue($production->confirmedPlan->is($confirmed));
        $this->assertSame(3, $production->latestPlan->version);
        $this->assertTrue($production->decisionNode->productions->first()->is($production));
    }

    public function test_only_some_runway_failures_can_be_retried(): void
    {
        $this->assertTrue(RunwayTask::factory()->failed('INTERNAL.BAD_OUTPUT.CODE01')->make()->isRetryable());
        $this->assertTrue(RunwayTask::factory()->failed('THIRD_PARTY.UNAVAILABLE')->make()->isRetryable());
        $this->assertFalse(RunwayTask::factory()->failed('SAFETY.INPUT.TEXT')->make()->isRetryable());
        $this->assertFalse(RunwayTask::factory()->failed('INPUT_PREPROCESSING.SAFETY.TEXT')->make()->isRetryable());
        $this->assertFalse(RunwayTask::factory()->failed('ASSET.INVALID')->make()->isRetryable());
        $this->assertFalse(RunwayTask::factory()->completed()->make()->isRetryable());
    }

    public function test_a_decision_can_be_inspired_by_a_concept_but_not_the_other_way_round(): void
    {
        $this->seed(KnowledgeRelationTypeSeeder::class);
        $curator = app(KnowledgeCurator::class);
        $user = User::factory()->create();
        $concept = KnowledgeNode::factory()->create();
        $decision = KnowledgeNode::factory()->decision()->create();
        $inspiredBy = KnowledgeRelationType::query()->where('key', KnowledgeRelationType::KEY_INSPIRED_BY)->firstOrFail();

        $edge = $curator->addEdge($decision, $inspiredBy, $concept, $user);

        $this->assertTrue($edge->targetNode->is($concept));

        $this->expectException(KnowledgeRuleViolation::class);
        $curator->addEdge($concept, $inspiredBy, $decision, $user);
    }

    public function test_a_produced_video_realizes_a_decision(): void
    {
        $this->seed(KnowledgeRelationTypeSeeder::class);
        $video = KnowledgeNode::factory()->forVideo()->create();
        $decision = KnowledgeNode::factory()->decision()->create();
        $realizes = KnowledgeRelationType::query()->where('key', KnowledgeRelationType::KEY_REALIZES)->firstOrFail();

        $edge = app(KnowledgeCurator::class)->addEdge($video, $realizes, $decision, User::factory()->create());

        $this->assertSame('実現する', $edge->relationType->label);
    }

    public function test_a_still_render_records_its_scene_and_the_input_it_was_given(): void
    {
        $render = ProductionRender::factory()->still()->create([
            'input_props' => ['scene' => ['scene_type' => 'remotion.title', 'content' => ['heading' => '見出し']]],
            'code_version' => 'e1a3c7fe594c',
        ]);

        $render->refresh();
        $this->assertTrue($render->isStill());
        $this->assertSame(ProductionRender::COMPOSITION_SCENE, $render->composition_id);
        $this->assertTrue($render->scene->plan->is($render->plan));
        $this->assertTrue($render->scene->renders->first()->is($render));
        $this->assertSame('見出し', $render->input_props['scene']['content']['heading']);
    }

    public function test_preview_and_final_use_the_production_composition(): void
    {
        $this->assertSame(ProductionRender::COMPOSITION_PRODUCTION, ProductionRender::compositionFor(ProductionRender::KIND_PREVIEW));
        $this->assertSame(ProductionRender::COMPOSITION_PRODUCTION, ProductionRender::compositionFor(ProductionRender::KIND_FINAL));
        $this->assertSame(ProductionRender::COMPOSITION_SCENE, ProductionRender::compositionFor(ProductionRender::KIND_STILL));

        foreach (config('remotion.kinds') as $kind => $settings) {
            $this->assertSame(ProductionRender::compositionFor($kind), $settings['composition_id']);
        }
    }

    public function test_a_completed_final_render_keeps_its_output_details(): void
    {
        $render = ProductionRender::factory()->completedFinal()->create()->refresh();

        $this->assertSame(ProductionRender::KIND_FINAL, $render->kind);
        $this->assertSame('video/mp4', $render->mime_type);
        $this->assertSame(12_345_678, $render->size_bytes);
        $this->assertNull($render->production_scene_id);
    }
}
