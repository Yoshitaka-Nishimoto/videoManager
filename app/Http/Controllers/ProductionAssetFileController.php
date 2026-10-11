<?php

namespace App\Http\Controllers;

use App\Models\ProductionAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 人が用意した素材（ラフ画・サンプル画像）を返す。保存先は非公開のディスクなので、ログインした人にだけこの経路で見せる。
 */
class ProductionAssetFileController extends Controller
{
    public function __invoke(ProductionAsset $asset): BinaryFileResponse
    {
        $disk = Storage::disk(ProductionAsset::DISK);

        abort_unless($disk->exists($asset->storage_path), 404);

        return response()->file($disk->path($asset->storage_path), [
            'Content-Type' => $asset->mime_type ?? 'application/octet-stream',
        ]);
    }
}
