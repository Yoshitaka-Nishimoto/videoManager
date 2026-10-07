<?php

namespace App\Models;

use Database\Factories\RunwayTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Runway API への生成依頼（1 回の API 呼び出し）。
 *
 * やり直すときは元の行を書き換えず、retry_of_id を付けた新しい行を作る。
 */
#[Fillable([
    'production_scene_id',
    'task_type',
    'model',
    'prompt_text',
    'ratio',
    'duration_seconds',
    'seed',
    'options',
    'request_payload',
    'status',
    'runway_task_id',
    'runway_status',
    'progress',
    'failure_code',
    'failure_message',
    'estimated_cost',
    'retry_of_id',
    'requested_by',
    'submitted_at',
    'completed_at',
])]
class RunwayTask extends Model
{
    /** @use HasFactory<RunwayTaskFactory> */
    use HasFactory;

    public const TYPE_IMAGE_TO_VIDEO = 'image_to_video';

    public const TYPE_TEXT_TO_VIDEO = 'text_to_video';

    public const TYPE_VIDEO_TO_VIDEO = 'video_to_video';

    public const TYPE_AVATAR = 'avatar';

    public const TYPE_IMAGE = 'image';

    public const TYPE_SPEECH = 'speech';

    public const TYPE_SOUND = 'sound';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** やり直しても結果が変わらない失敗コードの先頭部分（安全性チェックでの拒否、素材の不備）。 */
    private const NON_RETRYABLE_FAILURES = ['SAFETY.', 'INPUT_PREPROCESSING.SAFETY.', 'ASSET.INVALID'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'seed' => 'integer',
            'options' => 'json:unicode',
            'request_payload' => 'json:unicode',
            'progress' => 'integer',
            'estimated_cost' => 'decimal:4',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    /**
     * 失敗した依頼をやり直せるか。
     */
    public function isRetryable(): bool
    {
        if ($this->status !== self::STATUS_FAILED) {
            return false;
        }

        foreach (self::NON_RETRYABLE_FAILURES as $prefix) {
            if (str_starts_with((string) $this->failure_code, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return BelongsTo<ProductionScene, $this>
     */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(ProductionScene::class, 'production_scene_id');
    }

    /**
     * @return HasMany<RunwayTaskInput, $this>
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(RunwayTaskInput::class)->orderBy('role')->orderBy('position');
    }

    /**
     * @return HasMany<RunwayTaskOutput, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(RunwayTaskOutput::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<RunwayTask, $this>
     */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(RunwayTask::class, 'retry_of_id');
    }

    /**
     * @return HasMany<RunwayTask, $this>
     */
    public function retries(): HasMany
    {
        return $this->hasMany(RunwayTask::class, 'retry_of_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
