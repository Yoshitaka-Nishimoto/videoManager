<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['path', 'language', 'status', 'reason', 'diff', 'ast_errors', 'symbols_added', 'symbols_removed', 'tool_call_id'])]
class CodeChange extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'ast_errors' => 'array',
            'symbols_added' => 'array',
            'symbols_removed' => 'array',
        ];
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
