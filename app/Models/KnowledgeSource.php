<?php

namespace App\Models;

use Database\Factories\KnowledgeSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Ai\Models\ConversationMessage;

#[Fillable([
    'sourceable_type',
    'sourceable_id',
    'video_id',
    'video_analysis_id',
    'agent_conversation_message_id',
    'start_seconds',
    'end_seconds',
    'excerpt',
    'created_by',
])]
class KnowledgeSource extends Model
{
    /** @use HasFactory<KnowledgeSourceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_seconds' => 'integer',
            'end_seconds' => 'integer',
        ];
    }

    /**
     * The node or edge this source supports.
     *
     * @return MorphTo<Model, $this>
     */
    public function sourceable(): MorphTo
    {
        return $this->morphTo();
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
     * @return BelongsTo<VideoAnalysis, $this>
     */
    public function videoAnalysis(): BelongsTo
    {
        return $this->belongsTo(VideoAnalysis::class);
    }

    /**
     * @return BelongsTo<ConversationMessage, $this>
     */
    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'agent_conversation_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
