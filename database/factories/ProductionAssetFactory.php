<?php

namespace Database\Factories;

use App\Models\ProductionAsset;
use App\Models\VideoProduction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionAsset>
 */
class ProductionAssetFactory extends Factory
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
            'kind' => ProductionAsset::KIND_IMAGE,
            'title' => fake()->realText(20),
            'storage_path' => 'productions/'.fake()->uuid().'.png',
            'original_name' => 'photo.png',
            'mime_type' => 'image/png',
        ];
    }

    /**
     * A mock drawing passed to Gemini.
     */
    public function mock(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ProductionAsset::KIND_MOCK,
        ]);
    }
}
