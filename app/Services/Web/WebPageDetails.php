<?php

namespace App\Services\Web;

use Carbon\CarbonImmutable;

/**
 * 登録するときに取る Web ページの情報（題名・サイト名・説明・画像）。
 */
final readonly class WebPageDetails
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $siteName = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public ?CarbonImmutable $publishedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toVideoAttributes(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'channel_title' => $this->siteName,
            'description' => $this->description,
            'thumbnail_url' => $this->imageUrl,
            'published_at' => $this->publishedAt,
        ];
    }
}
