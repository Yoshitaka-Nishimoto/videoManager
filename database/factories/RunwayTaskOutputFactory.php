<?php

namespace Database\Factories;

use App\Models\RunwayTask;
use App\Models\RunwayTaskOutput;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RunwayTaskOutput>
 */
class RunwayTaskOutputFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'runway_task_id' => RunwayTask::factory()->completed(),
            'position' => 0,
            'media_type' => RunwayTaskOutput::MEDIA_VIDEO,
            'storage_path' => 'runway/'.fake()->uuid().'/0.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => fake()->numberBetween(1_000_000, 20_000_000),
            'width' => 1280,
            'height' => 720,
            'duration_seconds' => 5,
        ];
    }
}
