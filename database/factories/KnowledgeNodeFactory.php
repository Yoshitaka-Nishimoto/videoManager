<?php

namespace Database\Factories;

use App\Models\KnowledgeNode;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeNode>
 */
class KnowledgeNodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_type' => KnowledgeNode::TYPE_CONCEPT,
            'title' => fake()->realText(20),
            'description' => fake()->realText(100),
            'status' => KnowledgeNode::STATUS_CANDIDATE,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_AI,
        ];
    }

    /**
     * A node representing a video.
     */
    public function forVideo(?Video $video = null): static
    {
        return $this->state(fn (array $attributes) => [
            'node_type' => KnowledgeNode::TYPE_VIDEO,
            'video_id' => $video ?? Video::factory(),
            'status' => KnowledgeNode::STATUS_CONFIRMED,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
        ]);
    }

    /**
     * A node representing an analysis version.
     */
    public function forAnalysis(?VideoAnalysis $analysis = null): static
    {
        return $this->state(fn (array $attributes) => [
            'node_type' => KnowledgeNode::TYPE_ANALYSIS,
            'video_analysis_id' => $analysis ?? VideoAnalysis::factory(),
            'status' => KnowledgeNode::STATUS_CONFIRMED,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
        ]);
    }

    /**
     * Indicate that a person has confirmed the node.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeNode::STATUS_CONFIRMED,
            'confirmed_by' => User::factory(),
            'confirmed_at' => now(),
        ]);
    }
}
