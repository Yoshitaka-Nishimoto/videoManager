<?php

namespace App\Models;

use Database\Factories\ProductionRenderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remotion での書き出し（確認用の preview と完成版の final）。
 */
#[Fillable([
    'video_production_id',
    'production_plan_id',
    'kind',
    'status',
    'progress',
    'storage_path',
    'video_id',
    'error_message',
    'requested_by',
    'started_at',
    'completed_at',
])]
class ProductionRender extends Model
{
    /** @use HasFactory<ProductionRenderFactory> */
    use HasFactory;

    public const KIND_PREVIEW = 'preview';

    public const KIND_FINAL = 'final';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VideoProduction, $this>
     */
    public function production(): BelongsTo
    {
        return $this->belongsTo(VideoProduction::class, 'video_production_id');
    }

    /**
     * @return BelongsTo<ProductionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
