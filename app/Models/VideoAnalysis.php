<?php

namespace App\Models;

use Database\Factories\VideoAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => '待機中',
            self::STATUS_RUNNING => '分析中',
            self::STATUS_COMPLETED => '完了',
            self::STATUS_FAILED => '失敗',
            default => $this->status,
        };
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        // 削除済みの動画からも分析や知識の根拠を辿れるよう含める。
        return $this->belongsTo(Video::class)->withTrashed();
    }

    /**
     * この分析を表す知識ノード。
     *
     * @return HasOne<KnowledgeNode, $this>
     */
    public function knowledgeNode(): HasOne
    {
        return $this->hasOne(KnowledgeNode::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
