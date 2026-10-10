<?php

namespace Database\Factories;

use App\Models\KnowledgeNode;
use App\Models\VideoProduction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProduction>
 */
class VideoProductionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'decision_node_id' => KnowledgeNode::factory()->decision(),
            'genre' => 'ai',
            'title' => fake()->realText(20),
            'brief' => fake()->realText(80),
            'ratio' => '1280:720',
            'target_duration_seconds' => 30,
            'status' => VideoProduction::STATUS_ACTIVE,
        ];
    }
}
