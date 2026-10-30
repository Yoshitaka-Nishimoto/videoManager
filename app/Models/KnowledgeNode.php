<?php

namespace App\Models;

use App\Jobs\EmbedKnowledgeNodes;
use App\Models\Concerns\HasOwner;
use Database\Factories\KnowledgeNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
    'embedding_model',
    'embedding_hash',
    'embedded_at',
    'proposed_by',
    'confirmed_by',
    'confirmed_at',
    'owner_id',
])]
class KnowledgeNode extends Model
{
    /** @use HasFactory<KnowledgeNodeFactory> */
    use HasFactory, HasOwner;

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
     * 名称・説明が変わったら、埋め込みを作り直す。
     */
    protected static function booted(): void
    {
        // 個人の情報源の動画・分析ノードは、情報源の持ち主だけが見られるようにする。
        static::creating(function (KnowledgeNode $node) {
            if ($node->owner_id !== null) {
                return;
            }

            $videoId = $node->video_id
                ?? ($node->video_analysis_id !== null ? VideoAnalysis::withTrashed()->whereKey($node->video_analysis_id)->value('video_id') : null);

            if ($videoId !== null) {
                $node->owner_id = Video::withoutGlobalScopes()->whereKey($videoId)->value('owner_id');
            }
        });

        // wasRecentlyCreated は作成後の保存でも true のままなので、作成と更新を分けて判定する。
        static::created(fn (KnowledgeNode $node) => EmbedKnowledgeNodes::dispatchFor([$node->id]));
        static::updated(function (KnowledgeNode $node) {
            if ($node->wasChanged(['title', 'description'])) {
                EmbedKnowledgeNodes::dispatchFor([$node->id]);
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
            'embedding' => AsVector::class,
            'embedded_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            // 動画と Web ページの両方を含む。個々のノードは typeLabel() で「動画」「Web」に分けて表示する。
            self::TYPE_VIDEO => '情報源',
            self::TYPE_ANALYSIS => '分析',
            self::TYPE_CONCEPT => '概念',
            self::TYPE_DECISION => '設計判断',
            self::TYPE_IMPLEMENTATION => '実装',
        ];
    }

    public static function typeLabelFor(string $type): string
    {
        return self::typeLabels()[$type] ?? $type;
    }

    /**
     * このノードの種類の表示名。情報源のノードは、動画か Web ページかで分ける。
     */
    public function typeLabel(): string
    {
        return $this->node_type === self::TYPE_VIDEO && $this->video !== null
            ? $this->video->sourceLabel()
            : self::typeLabelFor($this->node_type);
    }

    /**
     * 選んだジャンルの知識に絞る。動画・分析ノードはその動画、概念などは出典（自身と廃止していない関係）の動画、
     * 設計判断は作った制作のジャンルで判定する。
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function inGenre(Builder $query, string $genre): void
    {
        $inGenre = fn ($q) => $q->where('genre', $genre);

        $query->where(fn ($q) => $q
            ->whereHas('video', $inGenre)
            ->orWhereHas('videoAnalysis.video', $inGenre)
            ->orWhereHas('sources.video', $inGenre)
            ->orWhereHas('outgoingEdges', fn ($edges) => $edges
                ->where('status', '!=', self::STATUS_DEPRECATED)
                ->whereHas('sources.video', $inGenre))
            ->orWhereHas('productions', $inGenre));
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

    /**
     * この実装ノードに対応するコードの場所。
     *
     * @return HasMany<KnowledgeCodeLink, $this>
     */
    public function codeLinks(): HasMany
    {
        return $this->hasMany(KnowledgeCodeLink::class);
    }

    /**
     * この設計判断から作る動画の制作。
     *
     * @return HasMany<VideoProduction, $this>
     */
    public function productions(): HasMany
    {
        return $this->hasMany(VideoProduction::class, 'decision_node_id');
    }
}
