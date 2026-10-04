<?php

namespace App\Services\YouTube;

use Carbon\CarbonImmutable;

final readonly class YouTubeVideoDetails
{
    public function __construct(
        public string $youtubeId,
        public string $title,
        public ?string $channelTitle = null,
        public ?string $description = null,
        public ?int $durationSeconds = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?string $thumbnailUrl = null,
    ) {}

    /**
     * videos テーブルに保存する属性。
     *
     * @return array<string, mixed>
     */
    public function toVideoAttributes(): array
    {
        return [
            'youtube_id' => $this->youtubeId,
            'url' => 'https://www.youtube.com/watch?v='.$this->youtubeId,
            'title' => $this->title,
            'channel_title' => $this->channelTitle,
            'description' => $this->description,
            'duration_seconds' => $this->durationSeconds,
            'published_at' => $this->publishedAt,
            'thumbnail_url' => $this->thumbnailUrl,
        ];
    }
}
