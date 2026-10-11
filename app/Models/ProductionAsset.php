<?php

namespace App\Models;

use Database\Factories\ProductionAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 人が用意した制作の素材（モック図、写真、画面録画、音源）。
 */
#[Fillable([
    'video_production_id',
    'kind',
    'title',
    'storage_path',
    'original_name',
    'mime_type',
    'uploaded_by',
])]
class ProductionAsset extends Model
{
    /** @use HasFactory<ProductionAssetFactory> */
    use HasFactory;

    public const KIND_MOCK = 'mock';

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    public const KIND_AUDIO = 'audio';

    /** 素材を保存するディスク（非公開。画面には ProductionAssetFileController を通して見せる）。 */
    public const DISK = 'local';

    /** 画面からアップロードできる静止画の種類。 */
    public const IMAGE_KINDS = [self::KIND_MOCK, self::KIND_IMAGE];

    /**
     * 制作ごとの素材フォルダー（ディスク内のパス）。
     */
    public static function directoryFor(VideoProduction $production): string
    {
        return "productions/{$production->id}/assets";
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_MOCK => 'ラフ画',
            self::KIND_IMAGE => 'サンプル画像',
            self::KIND_VIDEO => '動画',
            self::KIND_AUDIO => '音声',
            default => $this->kind,
        };
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /**
     * @return BelongsTo<VideoProduction, $this>
     */
    public function production(): BelongsTo
    {
        return $this->belongsTo(VideoProduction::class, 'video_production_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * この素材を渡した Runway の生成依頼の入力。
     *
     * @return HasMany<RunwayTaskInput, $this>
     */
    public function runwayInputs(): HasMany
    {
        return $this->hasMany(RunwayTaskInput::class);
    }
}
