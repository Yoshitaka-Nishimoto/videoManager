<?php

namespace App\Models;

use App\Jobs\EmbedKnowledgeNodes;
use App\Models\Concerns\HasOwner;
use Database\Factories\KnowledgeEdgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

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
    'owner_id',
])]
class KnowledgeEdge extends Model
{
    /** @use HasFactory<KnowledgeEdgeFactory> */
    use HasFactory, HasOwner;

    /**
     * 設計判断・実装の埋め込みには、つながる概念・設計判断の名称を入れるので、関係が変わったら作り直す。
     */
    protected static function booted(): void
    {
        // どちらかの端が個人のノードなら、関係もその持ち主だけが見られるようにする。
        static::creating(function (KnowledgeEdge $edge) {
            $owners = KnowledgeNode::withoutGlobalScopes()
                ->whereKey([$edge->source_node_id, $edge->target_node_id])
                ->whereNotNull('owner_id')
                ->distinct()
                ->pluck('owner_id');

            if ($owners->count() > 1) {
                throw new LogicException('持ち主の違う個人のノードどうしは、関係でつなげません。');
            }

            $edge->owner_id ??= $owners->first();
        });

        static::created(fn (KnowledgeEdge $edge) => EmbedKnowledgeNodes::dispatchFor([$edge->source_node_id]));
        static::updated(function (KnowledgeEdge $edge) {
            if ($edge->wasChanged(['status', 'source_node_id', 'target_node_id'])) {
                EmbedKnowledgeNodes::dispatchFor([$edge->source_node_id, $edge->getOriginal('source_node_id')]);
            }
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
