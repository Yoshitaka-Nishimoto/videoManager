<?php

namespace App\Models;

use Database\Factories\ProductionRenderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remotion での書き出し（場面の静止画 still、確認用の preview、完成版の final）。
 *
 * 同じコードと同じ入力なら同じ結果になるため、入力の写し（input_props）とコードの版（code_version）を残す。
 * 書き出しをやり直すときは新しい行を作る。
 */
#[Fillable([
    'video_production_id',
    'production_plan_id',
    'production_scene_id',
    'kind',
    'composition_id',
    'input_props',
    'code_version',
    'remotion_version',
    'codec',
    'image_format',
    'width',
    'height',
    'fps',
    'duration_in_frames',
    'frame',
    'scale',
    'crf',
    'status',
    'progress',
    'stage',
    'rendered_frames',
    'encoded_frames',
    'storage_path',
    'mime_type',
    'size_bytes',
    'render_ms',
    'video_id',
    'error_message',
    'log',
    'requested_by',
    'started_at',
    'completed_at',
])]
class ProductionRender extends Model
{
    /** @use HasFactory<ProductionRenderFactory> */
    use HasFactory;

    public const KIND_STILL = 'still';

    public const KIND_PREVIEW = 'preview';

    public const KIND_FINAL = 'final';

    /** 制作案の全場面を順に並べたコンポジション（preview / final）。 */
    public const COMPOSITION_PRODUCTION = 'Production';

    /** 1 つの場面だけのコンポジション（still）。 */
    public const COMPOSITION_SCENE = 'Scene';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STAGE_BUNDLING = 'bundling';

    public const STAGE_RENDERING = 'rendering';

    public const STAGE_ENCODING = 'encoding';

    public const STAGE_MUXING = 'muxing';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_props' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'fps' => 'integer',
            'duration_in_frames' => 'integer',
            'frame' => 'integer',
            'scale' => 'decimal:2',
            'crf' => 'integer',
            'progress' => 'integer',
            'rendered_frames' => 'integer',
            'encoded_frames' => 'integer',
            'size_bytes' => 'integer',
            'render_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * 書き出しの種類に対応するコンポジションID。
     */
    public static function compositionFor(string $kind): string
    {
        return $kind === self::KIND_STILL ? self::COMPOSITION_SCENE : self::COMPOSITION_PRODUCTION;
    }

    public function isStill(): bool
    {
        return $this->kind === self::KIND_STILL;
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->storage_path !== null;
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_STILL => '静止画',
            self::KIND_PREVIEW => '確認用',
            self::KIND_FINAL => '完成版',
            default => $this->kind,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => '待機中',
            self::STATUS_RUNNING => match ($this->stage) {
                self::STAGE_BUNDLING => '準備中',
                self::STAGE_RENDERING => '描画中',
                self::STAGE_ENCODING => 'エンコード中',
                self::STAGE_MUXING => '仕上げ中',
                default => '実行中',
            },
            self::STATUS_COMPLETED => '完了',
            self::STATUS_FAILED => '失敗',
            default => $this->status,
        };
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
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    /**
     * 静止画を書き出した場面（kind = still のとき）。
     *
     * @return BelongsTo<ProductionScene, $this>
     */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(ProductionScene::class, 'production_scene_id');
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
