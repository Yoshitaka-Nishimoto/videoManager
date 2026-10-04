<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'revisable_type',
    'revisable_id',
    'action',
    'before',
    'after',
    'reason',
    'changed_by',
])]
class KnowledgeRevision extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_CONFIRM = 'confirm';

    public const ACTION_DEPRECATE = 'deprecate';

    public const ACTION_MERGE = 'merge';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'json:unicode',
            'after' => 'json:unicode',
        ];
    }

    /**
     * The node or edge that was changed.
     *
     * @return MorphTo<Model, $this>
     */
    public function revisable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
