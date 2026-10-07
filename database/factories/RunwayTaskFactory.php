<?php

namespace Database\Factories;

use App\Models\RunwayTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunwayTask>
 */
class RunwayTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_type' => RunwayTask::TYPE_IMAGE_TO_VIDEO,
            'model' => 'gen4_turbo',
            'prompt_text' => 'The person slowly turns toward the camera and smiles.',
            'ratio' => '1280:720',
            'duration_seconds' => 5,
            'status' => RunwayTask::STATUS_QUEUED,
            'progress' => 0,
            'estimated_cost' => 0.25,
        ];
    }

    /**
     * Indicate that the task finished and its output was saved.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RunwayTask::STATUS_COMPLETED,
            'runway_task_id' => fake()->uuid(),
            'runway_status' => 'SUCCEEDED',
            'progress' => 100,
            'submitted_at' => now()->subMinutes(3),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the task failed with the given Runway failure code.
     */
    public function failed(string $failureCode = 'INTERNAL'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RunwayTask::STATUS_FAILED,
            'runway_task_id' => fake()->uuid(),
            'runway_status' => 'FAILED',
            'failure_code' => $failureCode,
            'failure_message' => '生成に失敗しました。',
            'submitted_at' => now()->subMinutes(3),
        ]);
    }
}
