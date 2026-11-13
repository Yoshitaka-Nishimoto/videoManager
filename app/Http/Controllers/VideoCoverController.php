<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 情報源の表紙（静止画）を返す。保存先は非公開のディスクなので、ログインした人にだけこの経路で見せる。
 * 本人だけの情報源は、ルートモデルバインディング（Video の VisibleToCurrentUser）で他の人には 404 になる。
 * ?download=1 なら保存用（ファイル名は題名）。
 */
class VideoCoverController extends Controller
{
    public function __invoke(Request $request, Video $video): BinaryFileResponse
    {
        $disk = Storage::disk(config('remotion.disk'));

        abort_unless($video->hasCover() && $disk->exists($video->cover_path), 404);

        $response = response()->file($disk->path($video->cover_path), [
            'Content-Type' => 'image/png',
            // ?v=（作った日時）付きの URL は作り直すと変わるので、ブラウザに長く残してよい（一覧で毎回送らない）。
            'Cache-Control' => $request->filled('v') ? 'private, max-age=31536000, immutable' : 'private, no-cache',
        ]);

        if ($request->boolean('download')) {
            $name = Str::limit(preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', (string) $video->title), 80, '');
            $response->setContentDisposition('attachment', "cover-{$video->id}-{$name}.png", "cover-{$video->id}.png");
        }

        return $response;
    }
}
