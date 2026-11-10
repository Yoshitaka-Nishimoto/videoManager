<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 仕組みのループの 1 周。① 目標の理解 → ② 知識検索 → ③ 前提条件の確認 → ④ 質問か候補確認 → ⑤ 仕組みのプログラム作成 → ⑥ 結果報告。
 */
#[Fillable([
    'mechanism_id',
    'number',
    'phase',
    'phase_status',
    'instruction',
    'understanding',
    'search',
    'design',
    'program',
    'report',
    'continue',
    'error',
])]
class MechanismRound extends Model
{
    public const PHASE_UNDERSTAND = 'understand';

    public const PHASE_SEARCH = 'search';

    public const PHASE_PREMISE = 'premise';

    public const PHASE_CONFIRM = 'confirm';

    public const PHASE_BUILD = 'build';

    public const PHASE_REPORT = 'report';

    public const PHASE_DONE = 'done';

    /** 段階（ループの順）と表示名。 */
    public const PHASE_LABELS = [
        self::PHASE_UNDERSTAND => '① 目標の理解',
        self::PHASE_SEARCH => '② 知識検索',
        self::PHASE_PREMISE => '③ 前提条件の確認',
        self::PHASE_CONFIRM => '④ 質問か候補確認',
        self::PHASE_BUILD => '⑤ 仕組みのプログラム作成',
        self::PHASE_REPORT => '⑥ 結果報告',
        self::PHASE_DONE => '周の終わり',
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DONE = 'done';

    public const STATUS_LABELS = [
        self::STATUS_QUEUED => '待機中',
        self::STATUS_RUNNING => '実行中',
        self::STATUS_WAITING => '回答待ち',
        self::STATUS_FAILED => '失敗',
        self::STATUS_DONE => '完了',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'understanding' => 'array',
            'search' => 'array',
            'design' => 'array',
            'program' => 'array',
            'report' => 'array',
            'continue' => 'boolean',
        ];
    }

    public function phaseLabel(): string
    {
        return self::PHASE_LABELS[$this->phase] ?? $this->phase;
    }

    public function phaseStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->phase_status] ?? $this->phase_status;
    }

    /**
     * @return BelongsTo<Mechanism, $this>
     */
    public function mechanism(): BelongsTo
    {
        return $this->belongsTo(Mechanism::class);
    }

    /**
     * @return HasMany<MechanismFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(MechanismFinding::class);
    }

    /**
     * @return HasMany<MechanismSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(MechanismSource::class);
    }

    /**
     * この周で出た不足情報のうち、利用者が答えたもの（次の周の始めに答える）。
     *
     * @return HasMany<MechanismFinding, $this>
     */
    public function answeredQuestions(): HasMany
    {
        return $this->findings()
            ->where('kind', MechanismFinding::KIND_MISSING)
            ->whereNotNull('answer')
            ->orderBy('id');
    }

    /**
     * この周の始めに受け取った回答（前の周で出た不足情報への答え）。1 周目は空。
     *
     * @return list<array{question: string, answer: string}>
     */
    public function receivedAnswers(): array
    {
        if ($this->number <= 1) {
            return [];
        }

        $previous = self::query()
            ->where('mechanism_id', $this->mechanism_id)
            ->where('number', $this->number - 1)
            ->first();

        return $previous === null ? [] : $previous->answeredQuestions()->get()
            ->map(fn (MechanismFinding $finding) => ['question' => (string) $finding->question, 'answer' => (string) $finding->answer])
            ->all();
    }
}
