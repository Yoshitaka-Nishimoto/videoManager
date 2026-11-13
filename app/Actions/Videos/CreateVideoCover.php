<?php

namespace App\Actions\Videos;

use App\Models\Video;
use App\Models\VideoAnalysis;
use App\Services\Remotion\WindowsStillClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 情報源の表紙（静止画）を作る。Windows の静止画のサーバーで remotion/src の remotion.cover を描き、
 * 画像を保存して videos.cover_* に残す。作り直すと上書きする（public_docs/video_covers.md）。
 */
class CreateVideoCover
{
    public const SCENE_TYPE = 'remotion.cover';

    /** 表紙に載せる話題（最新の分析の topics）の数。 */
    public const MAX_TOPICS = 5;

    public function __construct(private WindowsStillClient $client) {}

    /**
     * 見出し・副題を空のまま作るときの内容。前に作った表紙があれば、その見出し・副題を使う。
     *
     * @return array{heading: string, subheading: string|null, label: string, topics: list<string>, footer: string|null}
     */
    public function defaultContent(Video $video): array
    {
        $topics = $this->latestAnalysis($video)?->content['topics'] ?? [];
        $date = $video->isFile() ? $video->created_at : $video->published_at;

        return [
            'heading' => $video->cover_content['heading'] ?? (string) $video->title,
            'subheading' => array_key_exists('subheading', $video->cover_content ?? []) ? $video->cover_content['subheading'] : $video->channel_title,
            'label' => collect([$video->sourceLabel(), $video->genreLabel()])->filter()->implode(' ・ '),
            'topics' => collect(is_array($topics) ? $topics : [])->filter(fn ($topic) => is_string($topic) && $topic !== '')->take(self::MAX_TOPICS)->values()->all(),
            'footer' => $date?->format('Y-m-d'),
        ];
    }

    /**
     * @throws RuntimeException Windows の静止画のサーバーにつながらない・失敗したとき
     */
    public function handle(Video $video, string $heading, ?string $subheading): Video
    {
        $content = [
            ...$this->defaultContent($video),
            'heading' => trim($heading),
            'subheading' => filled($subheading) ? trim($subheading) : null,
        ];

        $still = $this->client->render([
            'settings' => [
                'width' => (int) config('remotion.width'),
                'height' => (int) config('remotion.height'),
                'fps' => (int) config('remotion.fps'),
                'style' => ['font' => 'Noto Sans JP', 'theme' => 'light'],
            ],
            'scene' => [
                'key' => 'cover',
                'scene_type' => self::SCENE_TYPE,
                'title' => Str::limit((string) $video->title, 100),
                'duration_seconds' => 1,
                'narration' => null,
                'caption' => null,
                'transition' => 'none',
                'content' => ['cover' => $content],
            ],
        ]);

        $path = 'videos/covers/'.$video->id.'.png';
        Storage::disk(config('remotion.disk'))->put($path, $still->image);

        $video->update([
            'cover_path' => $path,
            'cover_content' => $content,
            'cover_created_at' => now(),
        ]);

        return $video;
    }

    private function latestAnalysis(Video $video): ?VideoAnalysis
    {
        return $video->analyses()
            ->where('status', VideoAnalysis::STATUS_COMPLETED)
            ->orderByDesc('version')
            ->first();
    }
}
