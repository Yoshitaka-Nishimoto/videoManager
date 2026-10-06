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
 * YouTube の URL から動画を登録し、動画ノードを作る。登録済みの動画なら既存のものを返し、削除済みなら復元する。
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

        // youtube_id は削除済みの行とも重複できないため、削除済みも含めて探す。
        $existing = Video::withTrashed()->where('youtube_id', $youtubeId)->first();

        // 削除済みの動画は、分析履歴や知識ノードを残したまま復元して登録し直す。
        if ($existing?->trashed()) {
            $existing->restore();

            return ['video' => $existing, 'created' => true];
        }

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
