<?php

namespace Database\Factories;

use App\Models\ProductionPlan;
use App\Models\ProductionRender;
use App\Models\ProductionScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionRender>
 */
class ProductionRenderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_plan_id' => ProductionPlan::factory()->confirmed(),
            'video_production_id' => fn (array $attributes) => ProductionPlan::find($attributes['production_plan_id'])->video_production_id,
            'kind' => ProductionRender::KIND_PREVIEW,
            'composition_id' => ProductionRender::COMPOSITION_PRODUCTION,
            'input_props' => ['scenes' => []],
            'codec' => 'h264',
            'image_format' => 'jpeg',
            'width' => 1920,
            'height' => 1080,
            'fps' => 30,
            'scale' => 0.5,
            'status' => ProductionRender::STATUS_QUEUED,
            'progress' => 0,
        ];
    }

    /**
     * 1 つの場面の確認用の静止画。
     */
    public function still(): static
    {
        return $this->state(fn () => [
            'kind' => ProductionRender::KIND_STILL,
            'composition_id' => ProductionRender::COMPOSITION_SCENE,
            'production_scene_id' => fn (array $attributes) => ProductionScene::factory()->create(['production_plan_id' => $attributes['production_plan_id']])->id,
            'codec' => null,
            'image_format' => 'png',
            'frame' => 45,
            'scale' => 1,
        ]);
    }

    /**
     * 書き出しが完了した完成版。
     */
    public function completedFinal(): static
    {
        return $this->state(fn () => [
            'kind' => ProductionRender::KIND_FINAL,
            'scale' => 1,
            'crf' => 18,
            'status' => ProductionRender::STATUS_COMPLETED,
            'progress' => 100,
            'storage_path' => 'productions/renders/final.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 12_345_678,
            'render_ms' => 95_000,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);
    }
}
