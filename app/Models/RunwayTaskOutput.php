<?php

namespace App\Models;

use Database\Factories\RunwayTaskOutputFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Runway の生成物。Runway の出力 URL は 24〜48 時間で切れるため、ダウンロードした手元のファイルだけを記録する。
 */
#[Fillable([
    'runway_task_id',
    'position',
    'media_type',
    'storage_path',
    'mime_type',
    'size_bytes',
    'width',
    'height',
    'duration_seconds',
    'video_id',
])]
class RunwayTaskOutput extends Model
{
    /** @use HasFactory<RunwayTaskOutputFactory> */
    use HasFactory;

    public const MEDIA_VIDEO = 'video';

    public const MEDIA_IMAGE = 'image';

    public const MEDIA_AUDIO = 'audio';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<RunwayTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(RunwayTask::class, 'runway_task_id');
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class)->withTrashed();
    }
}
