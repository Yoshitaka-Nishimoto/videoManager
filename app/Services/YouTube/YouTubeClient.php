<?php

namespace App\Services\YouTube;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * YouTube Data API v3 で動画情報を取得する。
 *
 * API キー（YOUTUBE_API_KEY）が未設定のときは、キー不要の oEmbed でタイトル・チャンネル名・サムネイルだけを取得する。
 */
class YouTubeClient
{
    private const VIDEOS_ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos';

    private const OEMBED_ENDPOINT = 'https://www.youtube.com/oembed';

    public function __construct(private readonly ?string $apiKey) {}

    public function fetch(string $youtubeId): YouTubeVideoDetails
    {
        try {
            return $this->apiKey ? $this->fetchFromDataApi($youtubeId) : $this->fetchFromOEmbed($youtubeId);
        } catch (ConnectionException $e) {
            throw new YouTubeException('YouTube に接続できませんでした。時間をおいて再度お試しください。', previous: $e);
        }
    }

    private function fetchFromDataApi(string $youtubeId): YouTubeVideoDetails
    {
        $response = Http::timeout(10)->get(self::VIDEOS_ENDPOINT, [
            'id' => $youtubeId,
            'part' => 'snippet,contentDetails',
            'key' => $this->apiKey,
        ]);

        if ($response->failed()) {
            throw new YouTubeException('YouTube Data API の呼び出しに失敗しました（'.$response->status().'）。API キーを確認してください。');
        }

        $item = $response->json('items.0');

        if ($item === null) {
            throw new YouTubeException('動画が見つかりません。非公開または削除された可能性があります。');
        }

        $snippet = $item['snippet'];
        $thumbnails = $snippet['thumbnails'] ?? [];

        return new YouTubeVideoDetails(
            youtubeId: $youtubeId,
            title: $snippet['title'],
            channelTitle: $snippet['channelTitle'] ?? null,
            description: ($snippet['description'] ?? '') ?: null,
            durationSeconds: $this->durationSeconds($item['contentDetails']['duration'] ?? null),
            publishedAt: isset($snippet['publishedAt']) ? CarbonImmutable::parse($snippet['publishedAt']) : null,
            thumbnailUrl: ($thumbnails['high'] ?? $thumbnails['medium'] ?? $thumbnails['default'] ?? [])['url'] ?? null,
        );
    }

    private function fetchFromOEmbed(string $youtubeId): YouTubeVideoDetails
    {
        $response = Http::timeout(10)->get(self::OEMBED_ENDPOINT, [
            'url' => 'https://www.youtube.com/watch?v='.$youtubeId,
            'format' => 'json',
        ]);

        if ($response->failed()) {
            throw new YouTubeException('動画が見つかりません。非公開または削除された可能性があります。');
        }

        return new YouTubeVideoDetails(
            youtubeId: $youtubeId,
            title: $response->json('title'),
            channelTitle: $response->json('author_name'),
            thumbnailUrl: $response->json('thumbnail_url'),
        );
    }

    /**
     * ISO 8601 の再生時間（例: PT1H2M3S）を秒に変換する。
     */
    private function durationSeconds(?string $duration): ?int
    {
        if ($duration === null) {
            return null;
        }

        try {
            $interval = new DateInterval($duration);
        } catch (Throwable) {
            return null;
        }

        return $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
    }
}
