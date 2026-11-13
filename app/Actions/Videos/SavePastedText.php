<?php

namespace App\Actions\Videos;

use App\Models\KnowledgeNode;
use App\Models\User;
use App\Models\Video;
use App\Services\Knowledge\KnowledgeCurator;
use App\Services\Web\WebPageClient;
use App\Services\Web\WebPageException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ログインが必要なページ（ChatGPT・Claude・Gemini などの AI モデルとの会話）や JavaScript で本文を表示するページに、
 * 利用者がページから写した本文を保存する。次の分析からは、URL から取得せずにこの本文を使う。
 *
 * 取得できないページは題名もサイト名（「ChatGPT」など）になるので、題名も直せる。直した題名は情報源のノードにもそろえる。
 * 写した本文には画像がファイル名（「xxxxx.png」）だけで残るので、保存するたびに元のページから画像の URL を取り直す
 * （ページを開けなければ画像なし。本文の保存は止めない）。
 */
class SavePastedText
{
    public function __construct(
        private readonly KnowledgeCurator $curator,
        private readonly WebPageClient $web,
    ) {}

    /**
     * @param  ?string  $text  空なら貼り付けた本文を消し、URL から取得する方法に戻す
     */
    public function handle(Video $video, string $title, ?string $text, User $user): void
    {
        if (! $video->isWebPage()) {
            throw new InvalidArgumentException('本文を貼り付けられるのは、Web ページの情報源だけです。');
        }

        $text = filled($text) ? trim($text) : null;
        $images = $text !== null ? $this->images($video, $text) : null;

        DB::transaction(function () use ($video, $title, $text, $images, $user) {
            $video->pasted_images = $images;

            if ($text !== $video->pasted_text) {
                $video->fill(['pasted_text' => $text, 'pasted_text_updated_at' => $text !== null ? now() : null]);
            }

            $video->save();

            if ($title !== $video->title) {
                $video->update(['title' => $title]);

                $node = KnowledgeNode::query()->where('video_id', $video->id)->where('node_type', KnowledgeNode::TYPE_VIDEO)->first();

                if ($node !== null && $node->status !== KnowledgeNode::STATUS_DEPRECATED) {
                    $this->curator->updateNode($node, $title, $node->description, $user, '情報源の題名を修正');
                }
            }
        });
    }

    /**
     * @return ?array<string, string> 本文に画像の名前がなければ null（ページを取りに行かない）
     */
    private function images(Video $video, string $text): ?array
    {
        $hasImageName = collect(preg_split('/\R/u', $text))->contains(fn (string $line) => preg_match(WebPageClient::IMAGE_NAME, trim($line)));

        if (! $hasImageName || $video->url === null) {
            return null;
        }

        try {
            return $this->web->images($video->url, $text) ?: null;
        } catch (WebPageException) {
            return null;
        }
    }
}
