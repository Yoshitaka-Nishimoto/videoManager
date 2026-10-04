<?php

namespace Database\Factories;

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeEdge>
 */
class KnowledgeEdgeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_node_id' => KnowledgeNode::factory(),
            'target_node_id' => KnowledgeNode::factory(),
            'relation_type_id' => KnowledgeRelationType::factory(),
            'status' => KnowledgeNode::STATUS_CANDIDATE,
            'confidence' => fake()->randomFloat(3, 0.5, 1),
            'proposed_by' => KnowledgeNode::PROPOSED_BY_AI,
        ];
    }
}
