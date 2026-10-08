<?php

namespace App\Actions\Videos;

use App\Models\Video;
use App\Services\YouTube\YouTubeClient;
use App\Services\YouTube\YouTubeException;

/**
 * YouTube の動画の再生回数・高評価・コメント数を取り直す。タイトルなど他の項目は変えない。
 */
class RefreshVideoStatistics
{
    public function __construct(private readonly YouTubeClient $youtube) {}

    /**
     * @throws YouTubeException
     */
    public function handle(Video $video): Video
    {
        if ($video->youtube_id === null) {
            throw new YouTubeException('統計を取得できるのは YouTube の動画だけです。');
        }

        $details = $this->youtube->fetch($video->youtube_id);

        // API キーがないときは oEmbed で取得するが、oEmbed では統計が返らない。
        if ($details->statisticsFetchedAt === null) {
            throw new YouTubeException('統計の取得には YouTube Data API のキー（YOUTUBE_API_KEY）が必要です。');
        }

        $video->update($details->toStatisticsAttributes());

        return $video;
    }
}
