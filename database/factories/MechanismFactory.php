<?php

namespace Database\Factories;

use App\Models\Mechanism;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mechanism>
 */
class MechanismFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->realText(20),
            'goal' => fake()->realText(120),
            'status' => Mechanism::STATUS_ACTIVE,
            'current_round' => 1,
            'owner_id' => User::factory(),
        ];
    }
}
