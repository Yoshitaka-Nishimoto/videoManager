<?php

namespace Database\Factories;

use App\Models\KnowledgeCodeLink;
use App\Models\KnowledgeNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeCodeLink>
 */
class KnowledgeCodeLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $class = fake()->unique()->word().'Service';
        $start = fake()->numberBetween(1, 200);

        return [
            'knowledge_node_id' => KnowledgeNode::factory()->implementation(),
            'path' => "app/Services/{$class}.php",
            'symbol' => "{$class}::handle",
            'start_line' => $start,
            'end_line' => $start + fake()->numberBetween(5, 40),
            'status' => KnowledgeNode::STATUS_CANDIDATE,
            'proposed_by' => KnowledgeNode::PROPOSED_BY_HUMAN,
        ];
    }

    /**
     * Indicate that the link points to a whole file.
     */
    public function wholeFile(): static
    {
        return $this->state(fn (array $attributes) => [
            'symbol' => null,
            'start_line' => null,
            'end_line' => null,
        ]);
    }
}
