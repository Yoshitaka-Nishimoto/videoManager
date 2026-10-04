<?php

namespace App\Models;

use Database\Factories\KnowledgeNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'node_type',
    'title',
    'description',
    'status',
    'video_id',
    'video_analysis_id',
    'merged_into_id',
    'embedding',
    'proposed_by',
    'confirmed_by',
    'confirmed_at',
])]
class KnowledgeNode extends Model
{
    /** @use HasFactory<KnowledgeNodeFactory> */
    use HasFactory;

    public const TYPE_VIDEO = 'video';

    public const TYPE_ANALYSIS = 'analysis';

    public const TYPE_CONCEPT = 'concept';

    public const TYPE_DECISION = 'decision';

    public const TYPE_IMPLEMENTATION = 'implementation';

    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DEPRECATED = 'deprecated';

    public const PROPOSED_BY_AI = 'ai';

    public const PROPOSED_BY_HUMAN = 'human';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => AsVector::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public static function statusLabelFor(string $status): string
    {
        return match ($status) {
            self::STATUS_CANDIDATE => '候補',
            self::STATUS_CONFIRMED => '確認済み',
            self::STATUS_DEPRECATED => '廃止',
            default => $status,
        };
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /**
     * @return BelongsTo<VideoAnalysis, $this>
     */
    public function videoAnalysis(): BelongsTo
    {
        return $this->belongsTo(VideoAnalysis::class);
    }

    /**
     * @return BelongsTo<KnowledgeNode, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Edges that start at this node.
     *
     * @return HasMany<KnowledgeEdge, $this>
     */
    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(KnowledgeEdge::class, 'source_node_id');
    }

    /**
     * Edges that end at this node.
     *
     * @return HasMany<KnowledgeEdge, $this>
     */
    public function incomingEdges(): HasMany
    {
        return $this->hasMany(KnowledgeEdge::class, 'target_node_id');
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
