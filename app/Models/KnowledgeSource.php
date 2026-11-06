<?php

namespace App\Models;

use App\Jobs\EmbedKnowledgeNodes;
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

        // 概念の埋め込みの元の文には出典の動画のタイトルが入るので、出典が増減したら作り直す（KnowledgeEmbedder::text）。
        static::created(fn (KnowledgeSource $source) => EmbedKnowledgeNodes::dispatchFor([$source->conceptId()]));
        static::deleted(fn (KnowledgeSource $source) => EmbedKnowledgeNodes::dispatchFor([$source->conceptId()]));
    }

    /**
     * この出典で埋め込みの元の文が変わる概念（出典の付いた概念、または出典の付いた関係の接続元の概念）。概念でなければ null。
     */
    private function conceptId(): ?int
    {
        if (! config('services.knowledge_embeddings.enabled')) {
            return null;
        }

        $nodeId = match ($this->sourceable_type) {
            (new KnowledgeNode)->getMorphClass() => $this->sourceable_id,
            (new KnowledgeEdge)->getMorphClass() => KnowledgeEdge::withoutGlobalScopes()->whereKey($this->sourceable_id)->value('source_node_id'),
            default => null,
        };

        return $nodeId !== null && KnowledgeNode::withoutGlobalScopes()->whereKey($nodeId)->where('node_type', KnowledgeNode::TYPE_CONCEPT)->exists()
            ? (int) $nodeId
            : null;
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
