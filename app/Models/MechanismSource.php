<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 仕組みの周で使った根拠（プロジェクト内の知識、または Web のページ）。Web の結果は知識には自動で入れない。
 */
#[Fillable([
    'mechanism_round_id',
    'source_type',
    'knowledge_node_id',
    'url',
    'title',
    'excerpt',
    'reason',
    'video_id',
    'fetched_at',
])]
class MechanismSource extends Model
{
    public const TYPE_KNOWLEDGE = 'knowledge';

    public const TYPE_WEB = 'web';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MechanismRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(MechanismRound::class, 'mechanism_round_id');
    }

    /**
     * @return BelongsTo<KnowledgeNode, $this>
     */
    public function knowledgeNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class);
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
