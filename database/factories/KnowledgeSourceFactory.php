<?php

namespace Database\Factories;

use App\Models\KnowledgeNode;
use App\Models\KnowledgeSource;
use App\Models\VideoAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeSource>
 */
class KnowledgeSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->numberBetween(0, 600);

        return [
            'sourceable_type' => (new KnowledgeNode)->getMorphClass(),
            'sourceable_id' => KnowledgeNode::factory(),
            'video_analysis_id' => VideoAnalysis::factory(),
            'video_id' => fn (array $attributes) => VideoAnalysis::find($attributes['video_analysis_id'])?->video_id,
            'start_seconds' => $start,
            'end_seconds' => $start + fake()->numberBetween(10, 90),
            'excerpt' => fake()->realText(60),
        ];
    }
}
