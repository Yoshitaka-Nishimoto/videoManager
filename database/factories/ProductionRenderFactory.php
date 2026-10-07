<?php

namespace Database\Factories;

use App\Models\ProductionPlan;
use App\Models\ProductionRender;
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
            'status' => ProductionRender::STATUS_QUEUED,
            'progress' => 0,
        ];
    }
}
