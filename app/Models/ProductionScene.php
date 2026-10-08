<?php

namespace App\Models;

use Database\Factories\ProductionSceneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 制作案の場面。content の形式は public_docs/scene_content.md を参照。
 *
 * Runway の場面は、生成依頼（runway_tasks）を複数持ち、そのうち 1 つの生成物を selected_output_id で採用する。
 */
#[Fillable([
    'production_plan_id',
    'scene_key',
    'track',
    'position',
    'scene_type',
    'title',
    'duration_seconds',
    'content',
    'prompt_text',
    'generation_options',
    'narration',
    'caption',
    'transition',
    'estimated_cost',
    'status',
    'selected_output_id',
    'preview_path',
])]
class ProductionScene extends Model
{
    /** @use HasFactory<ProductionSceneFactory> */
    use HasFactory;

    public const TRACK_VIDEO = 'video';

    public const TRACK_MATERIAL = 'material';

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /**
     * 場面の種類キーと、置ける track。public_docs/video_capabilities.md の一覧と合わせる。
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'remotion.title' => self::TRACK_VIDEO,
        'remotion.text' => self::TRACK_VIDEO,
        'remotion.diagram' => self::TRACK_VIDEO,
        'remotion.chart' => self::TRACK_VIDEO,
        'remotion.code' => self::TRACK_VIDEO,
        'remotion.image' => self::TRACK_VIDEO,
        'remotion.clip' => self::TRACK_VIDEO,
        'remotion.split' => self::TRACK_VIDEO,
        'runway.image_to_video' => self::TRACK_VIDEO,
        'runway.text_to_video' => self::TRACK_VIDEO,
        'runway.video_to_video' => self::TRACK_VIDEO,
        'runway.avatar' => self::TRACK_VIDEO,
        'runway.image' => self::TRACK_MATERIAL,
        'runway.sound' => self::TRACK_MATERIAL,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'duration_seconds' => 'decimal:2',
            'content' => 'json:unicode',
            'generation_options' => 'json:unicode',
            'estimated_cost' => 'decimal:4',
        ];
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '未生成',
            self::STATUS_GENERATING => '生成中',
            self::STATUS_READY => '準備完了',
            self::STATUS_FAILED => '失敗',
            default => $this->status,
        };
    }

    /**
     * Runway で生成する場面か。
     */
    public function isRunway(): bool
    {
        return str_starts_with($this->scene_type, 'runway.');
    }

    /**
     * 種類キーから Runway の依頼の種類を取り出す（runway.image_to_video → image_to_video）。
     */
    public function runwayTaskType(): ?string
    {
        return $this->isRunway() ? substr($this->scene_type, strlen('runway.')) : null;
    }

    /**
     * @return BelongsTo<ProductionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    /**
     * この場面の生成依頼（やり直しを含む）。
     *
     * @return HasMany<RunwayTask, $this>
     */
    public function runwayTasks(): HasMany
    {
        return $this->hasMany(RunwayTask::class);
    }

    /**
     * 採用した生成物。
     *
     * @return BelongsTo<RunwayTaskOutput, $this>
     */
    public function selectedOutput(): BelongsTo
    {
        return $this->belongsTo(RunwayTaskOutput::class, 'selected_output_id');
    }

    /**
     * この場面の確認用の静止画の書き出し（やり直しを含む）。
     *
     * @return HasMany<ProductionRender, $this>
     */
    public function renders(): HasMany
    {
        return $this->hasMany(ProductionRender::class);
    }
}
