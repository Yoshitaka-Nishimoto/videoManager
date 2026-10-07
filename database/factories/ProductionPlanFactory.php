<?php

namespace Database\Factories;

use App\Models\KnowledgeNode;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Models\VideoProduction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionPlan>
 */
class ProductionPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_production_id' => VideoProduction::factory(),
            'version' => 1,
            'status' => ProductionPlan::STATUS_CANDIDATE,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_AI,
            'summary' => fake()->realText(80),
            'settings' => [
                'style' => ['tone' => '落ち着いた解説', 'font' => 'Noto Sans JP', 'theme' => 'light'],
                'narration' => ['enabled' => true, 'voice' => '落ち着いた女性の声'],
                'bgm' => null,
            ],
            'model' => 'gemini-3-pro',
        ];
    }

    /**
     * Indicate that a person has confirmed the plan.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProductionPlan::STATUS_CONFIRMED,
            'confirmed_by' => User::factory(),
            'confirmed_at' => now(),
        ]);
    }
}
