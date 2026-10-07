<?php

namespace App\Models;

use Database\Factories\RunwayTaskInputFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Runway に渡した素材。元は、人が用意した素材（production_asset_id）か、前の生成物（source_output_id）。
 */
#[Fillable([
    'runway_task_id',
    'role',
    'position',
    'media_type',
    'storage_path',
    'source_output_id',
    'production_asset_id',
    'runway_uri',
    'runway_uri_expires_at',
])]
class RunwayTaskInput extends Model
{
    /** @use HasFactory<RunwayTaskInputFactory> */
    use HasFactory;

    public const ROLE_PROMPT_IMAGE = 'prompt_image';

    public const ROLE_FIRST_FRAME = 'first_frame';

    public const ROLE_LAST_FRAME = 'last_frame';

    public const ROLE_REFERENCE_IMAGE = 'reference_image';

    public const ROLE_VIDEO = 'video';

    public const ROLE_AUDIO = 'audio';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'runway_uri_expires_at' => 'datetime',
        ];
    }

    /**
     * アップロード済みの URI がまだ使えるか。
     */
    public function hasUsableUri(): bool
    {
        return $this->runway_uri !== null && $this->runway_uri_expires_at?->isFuture() === true;
    }

    /**
     * @return BelongsTo<RunwayTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(RunwayTask::class, 'runway_task_id');
    }

    /**
     * @return BelongsTo<RunwayTaskOutput, $this>
     */
    public function sourceOutput(): BelongsTo
    {
        return $this->belongsTo(RunwayTaskOutput::class, 'source_output_id');
    }

    /**
     * @return BelongsTo<ProductionAsset, $this>
     */
    public function productionAsset(): BelongsTo
    {
        return $this->belongsTo(ProductionAsset::class);
    }
}
