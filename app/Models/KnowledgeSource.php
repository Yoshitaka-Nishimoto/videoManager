<?php

namespace App\Models;

use App\Models\Concerns\HasOwner;
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
    'owner_id',
])]
class KnowledgeSource extends Model
{
    /** @use HasFactory<KnowledgeSourceFactory> */
    use HasFactory, HasOwner;

    /**
     * 個人の情報源の出典、または個人のノード・関係の出典は、その持ち主だけが見られるようにする。
     */
    protected static function booted(): void
    {
        static::creating(function (KnowledgeSource $source) {
            $source->owner_id ??= match (true) {
                $source->video_id !== null && ($owner = Video::withoutGlobalScopes()->whereKey($source->video_id)->value('owner_id')) !== null => $owner,
                $source->sourceable_type === (new KnowledgeNode)->getMorphClass() => KnowledgeNode::withoutGlobalScopes()->whereKey($source->sourceable_id)->value('owner_id'),
                $source->sourceable_type === (new KnowledgeEdge)->getMorphClass() => KnowledgeEdge::withoutGlobalScopes()->whereKey($source->sourceable_id)->value('owner_id'),
                default => null,
            };
        });
    }

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
        // 削除済みの分析からも知識の根拠を辿れるよう含める。
        return $this->belongsTo(VideoAnalysis::class)->withTrashed();
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
