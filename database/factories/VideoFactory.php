<?php

namespace Database\Factories;

use App\Models\User;
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
            'title' => fake()->realText(30),
            'channel_title' => fake()->company(),
            'description' => fake()->realText(200),
            'duration_seconds' => fake()->numberBetween(30, 60 * 60 * 2),
            'published_at' => fake()->dateTimeBetween('-3 years'),
            'thumbnail_url' => fn (array $attributes) => 'https://i.ytimg.com/vi/'.$attributes['youtube_id'].'/hqdefault.jpg',
        ];
    }

    /**
     * 公式サイト・GitHub などの Web ページ。
     */
    public function webPage(string $url = 'https://example.com/docs/guide'): static
    {
        return $this->state(fn () => [
            'source_type' => Video::SOURCE_WEB,
            'youtube_id' => null,
            'url' => $url,
            'channel_title' => 'Example Docs',
            'duration_seconds' => null,
            'thumbnail_url' => null,
        ]);
    }

    /**
     * 利用者がアップロードした個人のファイル。持ち主だけが見られる。
     */
    public function personalFile(User $owner, string $path = 'resources/test/blood.csv'): static
    {
        return $this->state(fn () => [
            'source_type' => Video::SOURCE_FILE,
            'youtube_id' => null,
            'url' => null,
            'storage_path' => $path,
            'title' => 'blood.csv',
            'channel_title' => null,
            'genre' => 'healthy_longevity',
            'duration_seconds' => null,
            'published_at' => null,
            'thumbnail_url' => null,
            'owner_id' => $owner->id,
            'registered_by' => $owner->id,
        ]);
    }
}
