<?php

namespace App\Actions\Videos;

use App\Models\User;
use App\Models\Video;
use App\Services\Knowledge\KnowledgeCandidateWriter;
use App\Services\Web\WebPageClient;
use App\Services\Web\WebPageException;
use Illuminate\Support\Facades\DB;

/**
 * 公式サイト・GitHub などの Web ページを情報源として登録し、情報源のノードを作る。
 * 登録済みの URL なら既存のものを返し、削除済みなら復元する（RegisterYouTubeVideo と同じ）。
 */
class RegisterWebPage
{
    public function __construct(
        private readonly WebPageClient $web,
        private readonly KnowledgeCandidateWriter $knowledge,
    ) {}

    /**
     * @return array{video: Video, created: bool}
     *
     * @throws WebPageException
     */
    public function handle(string $url, User $user): array
    {
        $url = WebPageClient::normalizeUrl($url)
            ?? throw new WebPageException('URL として読み取れませんでした。http:// または https:// で始まる URL を入力してください。');

        $existing = Video::withTrashed()
            ->where('source_type', Video::SOURCE_WEB)
            ->where('url', $url)
            ->first();

        if ($existing?->trashed()) {
            $existing->restore();

            return ['video' => $existing, 'created' => true];
        }

        if ($existing !== null) {
            return ['video' => $existing, 'created' => false];
        }

        $details = $this->web->details($url);

        $video = DB::transaction(function () use ($details, $user) {
            $video = Video::query()->create([
                ...$details->toVideoAttributes(),
                'source_type' => Video::SOURCE_WEB,
                'registered_by' => $user->id,
            ]);

            $this->knowledge->videoNode($video);

            return $video;
        });

        return ['video' => $video, 'created' => true];
    }
}
