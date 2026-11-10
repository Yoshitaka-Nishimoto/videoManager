<?php

namespace App\Models;

use Database\Factories\MechanismFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 目標から設計する仕組み（plotdesign.md）。個人の知識を使うことがあるため、作った本人だけが見られる。
 */
#[Fillable([
    'title',
    'goal',
    'status',
    'current_round',
    'agent_conversation_id',
    'decision_node_id',
    'owner_id',
])]
class Mechanism extends Model
{
    /** @use HasFactory<MechanismFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABANDONED = 'abandoned';

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => '進行中',
        self::STATUS_COMPLETED => '完了',
        self::STATUS_ABANDONED => '中止',
    ];

    /** 周の上限。超えるときは目標を分けることを勧める。 */
    public const MAX_ROUNDS = 5;

    /** 名前を書かなかったときに、目標の先頭から取る文字数。 */
    public const TITLE_FROM_GOAL_LENGTH = 40;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_round' => 'integer',
        ];
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('owner_id', $user->id);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<KnowledgeNode, $this>
     */
    public function decisionNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class, 'decision_node_id');
    }

    /**
     * @return HasMany<MechanismRound, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(MechanismRound::class)->orderBy('number');
    }

    /**
     * @return HasOne<MechanismRound, $this>
     */
    public function latestRound(): HasOne
    {
        return $this->hasOne(MechanismRound::class)->ofMany('number', 'max');
    }
}
