<?php

namespace App\Models;

use Database\Factories\ProductionAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
