<?php

namespace Database\Factories;

use App\Models\ProductionPlan;
use App\Models\ProductionScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionScene>
 */
class ProductionSceneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $position = fake()->unique()->numberBetween(1, 1000);

        return [
            'production_plan_id' => ProductionPlan::factory(),
            'scene_key' => "s{$position}",
            'track' => ProductionScene::TRACK_VIDEO,
            'position' => $position,
            'scene_type' => 'remotion.title',
            'title' => fake()->realText(15),
            'duration_seconds' => 4,
            'content' => ['heading' => fake()->realText(15), 'subheading' => null, 'layout' => 'center'],
            'narration' => fake()->realText(30),
            'transition' => 'fade',
            'estimated_cost' => 0,
            'status' => ProductionScene::STATUS_READY,
        ];
    }

    /**
     * A scene generated with Runway (image to video).
     */
    public function runway(): static
    {
        return $this->state(fn (array $attributes) => [
            'scene_type' => 'runway.image_to_video',
            'duration_seconds' => 5,
            'content' => null,
            'prompt_text' => 'The person slowly turns toward the camera and smiles.',
            'generation_options' => ['model' => 'gen4_turbo', 'duration' => 5, 'image' => ['asset_id' => null]],
            'estimated_cost' => 0.25,
            'status' => ProductionScene::STATUS_PENDING,
        ]);
    }

    /**
     * A material (not played) such as a still image or BGM.
     */
    public function material(): static
    {
        return $this->state(function (array $attributes) {
            $position = fake()->unique()->numberBetween(1, 1000);

            return [
                'scene_key' => "m{$position}",
                'track' => ProductionScene::TRACK_MATERIAL,
                'position' => $position,
                'scene_type' => 'runway.image',
                'duration_seconds' => 0,
                'content' => null,
                'narration' => null,
                'transition' => null,
                'prompt_text' => 'A clean desk with a camera, soft morning light.',
                'generation_options' => ['model' => 'gen4_image_turbo', 'reference' => null],
                'estimated_cost' => 0.02,
                'status' => ProductionScene::STATUS_PENDING,
            ];
        });
    }
}
