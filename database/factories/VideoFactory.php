<?php

namespace Database\Factories;

use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'youtube_id' => fake()->unique()->regexify('[A-Za-z0-9_-]{11}'),
            'url' => fn (array $attributes) => 'https://www.youtube.com/watch?v='.$attributes['youtube_id'],
            'title' => fake()->sentence(6),
            'channel_title' => fake()->company(),
            'description' => fake()->paragraphs(2, true),
            'duration_seconds' => fake()->numberBetween(30, 60 * 60 * 2),
            'published_at' => fake()->dateTimeBetween('-3 years'),
            'thumbnail_url' => fn (array $attributes) => 'https://i.ytimg.com/vi/'.$attributes['youtube_id'].'/hqdefault.jpg',
        ];
    }
}
