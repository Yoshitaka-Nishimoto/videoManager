<?php

namespace Database\Factories;

use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoAnalysis>
 */
class VideoAnalysisFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'version' => 1,
            'status' => VideoAnalysis::STATUS_QUEUED,
            'progress' => 0,
        ];
    }

    /**
     * Indicate that the analysis has finished successfully.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VideoAnalysis::STATUS_COMPLETED,
            'progress' => 100,
            'summary' => fake()->realText(120),
            'content' => ['topics' => fake()->randomElements(['動画生成', '画像処理', 'プロンプト設計', '字幕編集', 'ナレッジグラフ'], 2)],
            'model' => 'claude-sonnet-5-5',
            'started_at' => now()->subMinutes(5),
            'analyzed_at' => now(),
        ]);
    }

    /**
     * Indicate that the analysis is in progress.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VideoAnalysis::STATUS_RUNNING,
            'progress' => fake()->numberBetween(10, 90),
            'model' => 'claude-sonnet-5-5',
            'started_at' => now()->subMinutes(2),
        ]);
    }

    /**
     * Indicate that the analysis has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VideoAnalysis::STATUS_FAILED,
            'progress' => fake()->numberBetween(0, 60),
            'model' => 'claude-sonnet-5-5',
            'error_message' => '字幕を取得できませんでした。',
            'started_at' => now()->subMinutes(10),
        ]);
    }
}
