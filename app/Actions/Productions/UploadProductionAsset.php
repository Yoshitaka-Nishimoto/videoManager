<?php

namespace App\Actions\Productions;

use App\Models\ProductionAsset;
use App\Models\User;
use App\Models\VideoProduction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 人が用意した静止画（ラフ画・サンプル画像）を、制作ごとの素材フォルダーに保存して登録する。
 *
 * 保存先は非公開のディスク（ProductionAsset::DISK）の productions/{制作ID}/assets/。
 * 画面には ProductionAssetFileController を通して見せる。
 */
class UploadProductionAsset
{
    public function handle(VideoProduction $production, UploadedFile $file, string $kind, ?User $user, ?string $title = null): ProductionAsset
    {
        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        $path = $file->storeAs(ProductionAsset::directoryFor($production), Str::ulid().'.'.$extension, ProductionAsset::DISK);

        try {
            return $production->assets()->create([
                'kind' => $kind,
                'title' => filled($title) ? $title : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'storage_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => $user?->id,
            ]);
        } catch (\Throwable $e) {
            // 登録できなかったファイルは残さない。
            Storage::disk(ProductionAsset::DISK)->delete($path);

            throw $e;
        }
    }
}
