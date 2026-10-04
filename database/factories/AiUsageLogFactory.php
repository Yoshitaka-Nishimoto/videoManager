<?php

namespace Database\Factories;

use App\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageLog>
 */
class AiUsageLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'feature' => AiUsageLog::FEATURE_ANALYSIS,
            'input_tokens' => fake()->numberBetween(500, 8000),
            'output_tokens' => fake()->numberBetween(100, 2000),
            'estimated_cost' => fake()->randomFloat(6, 0.001, 0.1),
            'succeeded' => true,
        ];
    }

    /**
     * Indicate that the AI call failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'output_tokens' => 0,
            'succeeded' => false,
            'error_message' => 'APIの応答がタイムアウトしました。',
        ]);
    }
}
