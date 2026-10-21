<?php

namespace App\Models;

use Database\Factories\KnowledgeEdgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'source_node_id',
    'target_node_id',
    'relation_type_id',
    'replaced_by_id',
    'status',
    'confidence',
    'proposed_by',
    'confirmed_by',
    'confirmed_at',
])]
class KnowledgeEdge extends Model
{
    /** @use HasFactory<KnowledgeEdgeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:3',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeNode, $this>
     */
    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class, 'source_node_id');
    }

    /**
     * @return BelongsTo<KnowledgeNode, $this>
     */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class, 'target_node_id');
    }

    /**
     * @return BelongsTo<KnowledgeRelationType, $this>
     */
    public function relationType(): BelongsTo
    {
        return $this->belongsTo(KnowledgeRelationType::class, 'relation_type_id');
    }

    /**
     * この関係を置き換えた、正しい関係。
     *
     * @return BelongsTo<KnowledgeEdge, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /**
     * この関係が置き換えた、誤っていた関係。
     *
     * @return HasMany<KnowledgeEdge, $this>
     */
    public function replaces(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return MorphMany<KnowledgeSource, $this>
     */
    public function sources(): MorphMany
    {
        return $this->morphMany(KnowledgeSource::class, 'sourceable');
    }

    /**
     * @return MorphMany<KnowledgeRevision, $this>
     */
    public function revisions(): MorphMany
    {
        return $this->morphMany(KnowledgeRevision::class, 'revisable');
    }
}
