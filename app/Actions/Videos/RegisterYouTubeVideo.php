<?php

namespace App\Actions\Videos;

use App\Models\User;
use App\Models\Video;
use App\Services\Knowledge\KnowledgeCandidateWriter;
use App\Services\YouTube\YouTubeClient;
use App\Services\YouTube\YouTubeException;
use App\Services\YouTube\YouTubeVideoId;
use Illuminate\Support\Facades\DB;

/**
 * YouTube の URL から動画を登録し、動画ノードを作る。登録済みの動画なら既存のものを返す。
 */
class RegisterYouTubeVideo
{
    public function __construct(
        private readonly YouTubeClient $youtube,
        private readonly KnowledgeCandidateWriter $knowledge,
    ) {}

    /**
     * @return array{video: Video, created: bool}
     *
     * @throws YouTubeException
     */
    public function handle(string $url, User $user): array
    {
        $youtubeId = YouTubeVideoId::parse($url)
            ?? throw new YouTubeException('YouTube の動画 URL として読み取れませんでした。');

        $existing = Video::query()->where('youtube_id', $youtubeId)->first();

        if ($existing !== null) {
            return ['video' => $existing, 'created' => false];
        }

        $details = $this->youtube->fetch($youtubeId);

        $video = DB::transaction(function () use ($details, $user) {
            $video = Video::query()->create([
                ...$details->toVideoAttributes(),
                'source_type' => Video::SOURCE_YOUTUBE,
                'registered_by' => $user->id,
            ]);

            $this->knowledge->videoNode($video);

            return $video;
        });

        return ['video' => $video, 'created' => true];
    }
}
