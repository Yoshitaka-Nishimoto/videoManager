<?php

namespace App\Models;

use Database\Factories\KnowledgeCodeLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 実装ノードとコードの場所（パス・シンボル）の対応。
 *
 * 状態（status）と提案元（proposed_by）は KnowledgeNode の定数を使う。
 */
#[Fillable([
    'knowledge_node_id',
    'path',
    'symbol',
    'start_line',
    'end_line',
    'commit_sha',
    'graphify_node_id',
    'note',
    'status',
    'proposed_by',
    'confirmed_by',
    'confirmed_at',
    'missing_since',
    'checked_at',
    'created_by',
])]
class KnowledgeCodeLink extends Model
{
    /** @use HasFactory<KnowledgeCodeLinkFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_line' => 'integer',
            'end_line' => 'integer',
            'confirmed_at' => 'datetime',
            'missing_since' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * graph.json で場所が見つからなくなっているか。
     */
    public function isMissing(): bool
    {
        return $this->missing_since !== null;
    }

    /**
     * @return BelongsTo<KnowledgeNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class, 'knowledge_node_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return MorphMany<KnowledgeRevision, $this>
     */
    public function revisions(): MorphMany
    {
        return $this->morphMany(KnowledgeRevision::class, 'revisable');
    }
}
