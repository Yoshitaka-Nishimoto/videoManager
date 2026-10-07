<?php

namespace App\Models;

use Database\Factories\VideoProductionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 設計判断ノードから 1 本の動画を作る単位。
 */
#[Fillable([
    'decision_node_id',
    'title',
    'brief',
    'ratio',
    'target_duration_seconds',
    'status',
    'created_by',
])]
class VideoProduction extends Model
{
    /** @use HasFactory<VideoProductionFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABANDONED = 'abandoned';

    /** 選べる画面の比率（Runway の出力の比率に合わせる）。 */
    public const RATIOS = ['1280:720', '720:1280', '960:960'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeNode, $this>
     */
    public function decisionNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class, 'decision_node_id');
    }

    /**
     * @return HasMany<ProductionAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(ProductionAsset::class);
    }

    /**
     * @return HasMany<ProductionPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(ProductionPlan::class);
    }

    /**
     * 採用済みの制作案（制作ごとに 1 つ）。
     *
     * @return HasOne<ProductionPlan, $this>
     */
    public function confirmedPlan(): HasOne
    {
        return $this->hasOne(ProductionPlan::class)->where('status', ProductionPlan::STATUS_CONFIRMED);
    }

    /**
     * @return HasOne<ProductionPlan, $this>
     */
    public function latestPlan(): HasOne
    {
        return $this->hasOne(ProductionPlan::class)->latestOfMany('version');
    }

    /**
     * @return HasMany<ProductionRender, $this>
     */
    public function renders(): HasMany
    {
        return $this->hasMany(ProductionRender::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
