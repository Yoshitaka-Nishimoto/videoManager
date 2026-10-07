<?php

namespace Database\Factories;

use App\Models\RunwayTask;
use App\Models\RunwayTaskInput;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunwayTaskInput>
 */
class RunwayTaskInputFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'runway_task_id' => RunwayTask::factory(),
            'role' => RunwayTaskInput::ROLE_PROMPT_IMAGE,
            'position' => 0,
            'media_type' => 'image',
            'storage_path' => 'productions/'.fake()->uuid().'.png',
        ];
    }
}
