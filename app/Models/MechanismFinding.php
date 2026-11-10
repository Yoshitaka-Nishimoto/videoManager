<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ③ 前提条件の確認で出た、前提条件・不足情報・判断点の 1 項目。
 * 前提条件には根拠（知識・Web・利用者の回答）が必要で、根拠を示せないものは不足情報にする（plotdesign.md の「勝手に想像しない決まり」）。
 */
#[Fillable([
    'mechanism_round_id',
    'kind',
    'content',
    'basis',
    'knowledge_node_id',
    'mechanism_source_id',
    'question',
    'candidates',
    'recommended',
    'answer',
    'tentative',
    'answered_at',
])]
class MechanismFinding extends Model
{
    public const KIND_PREMISE = 'premise';

    public const KIND_MISSING = 'missing';

    public const KIND_DECISION = 'decision';

    public const KIND_LABELS = [
        self::KIND_PREMISE => '前提条件',
        self::KIND_MISSING => '不足情報',
        self::KIND_DECISION => '判断点',
    ];

    public const BASIS_KNOWLEDGE = 'knowledge';

    public const BASIS_WEB = 'web';

    public const BASIS_USER = 'user';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'recommended' => 'integer',
            'tentative' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MechanismRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(MechanismRound::class, 'mechanism_round_id');
    }

    /**
     * @return BelongsTo<KnowledgeNode, $this>
     */
    public function knowledgeNode(): BelongsTo
    {
        return $this->belongsTo(KnowledgeNode::class);
    }

    /**
     * @return BelongsTo<MechanismSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(MechanismSource::class, 'mechanism_source_id');
    }
}
