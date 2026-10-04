<?php

namespace Database\Factories;

use App\Models\KnowledgeRelationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeRelationType>
 */
class KnowledgeRelationTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'label' => '関連する',
            'inverse_label' => '関連される',
            'allow_cycle' => false,
        ];
    }
}
