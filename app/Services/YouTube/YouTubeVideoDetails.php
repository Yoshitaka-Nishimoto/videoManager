<?php

namespace App\Services\YouTube;

use Carbon\CarbonImmutable;

final readonly class YouTubeVideoDetails
{
    /**
     * @param  ?CarbonImmutable  $statisticsFetchedAt  統計を取得した日時。oEmbed では統計が取れないため null
     */
    public function __construct(
        public string $youtubeId,
        public string $title,
        public ?string $channelTitle = null,
        public ?string $description = null,
        public ?int $durationSeconds = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?string $thumbnailUrl = null,
        public ?int $viewCount = null,
        public ?int $likeCount = null,
        public ?int $commentCount = null,
        public ?CarbonImmutable $statisticsFetchedAt = null,
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
            ...$this->toStatisticsAttributes(),
        ];
    }

    /**
     * videos テーブルに保存する統計（再生回数・高評価・コメント数と取得日時）。
     *
     * @return array<string, mixed>
     */
    public function toStatisticsAttributes(): array
    {
        return [
            'view_count' => $this->viewCount,
            'like_count' => $this->likeCount,
            'comment_count' => $this->commentCount,
            'statistics_fetched_at' => $this->statisticsFetchedAt,
        ];
    }
}
