<?php

namespace App\Models;

use Database\Factories\KnowledgeRelationTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'key',
    'label',
    'inverse_label',
    'description',
    'allow_cycle',
    'allowed_source_types',
    'allowed_target_types',
])]
class KnowledgeRelationType extends Model
{
    /** @use HasFactory<KnowledgeRelationTypeFactory> */
    use HasFactory;

    /** 設計判断 → 着想元とする → 概念 */
    public const KEY_INSPIRED_BY = 'inspired_by';

    /** 制作した動画 → 実現する → 設計判断 */
    public const KEY_REALIZES = 'realizes';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_cycle' => 'boolean',
            'allowed_source_types' => 'json:unicode',
            'allowed_target_types' => 'json:unicode',
        ];
    }

    /**
     * @return HasMany<KnowledgeEdge, $this>
     */
    public function edges(): HasMany
    {
        return $this->hasMany(KnowledgeEdge::class, 'relation_type_id');
    }
}
