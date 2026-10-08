<?php

namespace App\Http\Controllers;

use App\Models\ProductionRender;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 書き出した静止画・動画を返す。保存先は非公開のディスクなので、ログインした人にだけこの経路で見せる。
 */
class ProductionRenderFileController extends Controller
{
    public function __invoke(ProductionRender $render): BinaryFileResponse
    {
        $disk = Storage::disk(config('remotion.disk'));

        abort_unless($render->isCompleted() && $disk->exists($render->storage_path), 404);

        // ファイルとして返すと Range リクエストに対応し、動画の再生位置を移動できる。
        return response()->file($disk->path($render->storage_path), [
            'Content-Type' => $render->mime_type ?? 'application/octet-stream',
        ]);
    }
}
