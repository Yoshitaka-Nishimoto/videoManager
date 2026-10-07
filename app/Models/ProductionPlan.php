<?php

namespace App\Models;

use Database\Factories\ProductionPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 制作案の版。確認済みの版は直接修正せず、based_on_id を付けた新しい版を作る。
 *
 * 提案元（proposed_by）は KnowledgeNode::PROPOSED_BY_AI / PROPOSED_BY_HUMAN を使う。
 */
#[Fillable([
    'video_production_id',
    'version',
    'status',
    'proposed_by',
    'based_on_id',
    'summary',
    'settings',
    'mock_asset_ids',
    'model',
    'prompt',
    'ai_response',
    'estimated_cost',
    'error_message',
    'confirmed_by',
    'confirmed_at',
])]
class ProductionPlan extends Model
{
    /** @use HasFactory<ProductionPlanFactory> */
    use HasFactory;

    public const STATUS_GENERATING = 'generating';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DEPRECATED = 'deprecated';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'settings' => 'json:unicode',
            'mock_asset_ids' => 'array',
            'ai_response' => 'json:unicode',
            'estimated_cost' => 'decimal:4',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * 場面を直接修正できるか。確認済み・廃止の版は新しい版を作って直す。
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_CANDIDATE;
    }

    /**
     * @return BelongsTo<VideoProduction, $this>
     */
    public function production(): BelongsTo
    {
        return $this->belongsTo(VideoProduction::class, 'video_production_id');
    }

    /**
     * @return BelongsTo<ProductionPlan, $this>
     */
    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'based_on_id');
    }

    /**
     * すべての場面（再生する場面と素材）。
     *
     * @return HasMany<ProductionScene, $this>
     */
    public function scenes(): HasMany
    {
        return $this->hasMany(ProductionScene::class)->orderBy('track')->orderBy('position');
    }

    /**
     * 順番に再生する場面。
     *
     * @return HasMany<ProductionScene, $this>
     */
    public function videoScenes(): HasMany
    {
        return $this->hasMany(ProductionScene::class)->where('track', ProductionScene::TRACK_VIDEO)->orderBy('position');
    }

    /**
     * 再生しない素材の生成（静止画・BGM）。
     *
     * @return HasMany<ProductionScene, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(ProductionScene::class)->where('track', ProductionScene::TRACK_MATERIAL)->orderBy('position');
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
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
