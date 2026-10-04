<?php

namespace App\Models;

use Database\Factories\VideoAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'video_id',
    'version',
    'status',
    'progress',
    'summary',
    'content',
    'model',
    'prompt',
    'error_message',
    'requested_by',
    'started_at',
    'analyzed_at',
])]
class VideoAnalysis extends Model
{
    /** @use HasFactory<VideoAnalysisFactory> */
    use HasFactory;

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
            'version' => 'integer',
            'progress' => 'integer',
            'content' => 'json:unicode',
            'started_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
