<?php

namespace App\Models\Concerns;

use App\Models\Scopes\VisibleToCurrentUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 持ち主（owner_id）のあるデータ。空なら全員で共有、値があればその利用者だけが見られる。
 */
trait HasOwner
{
    public static function bootHasOwner(): void
    {
        static::addGlobalScope(new VisibleToCurrentUser);
    }

    /**
     * 指定した利用者から見えるもの（共有と、その利用者のもの）に絞る。null なら共有のものだけ。
     * ログイン中の利用者による絞り込み（VisibleToCurrentUser）は外す。
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, ?int $userId): void
    {
        $column = $query->qualifyColumn('owner_id');

        $query->withoutGlobalScope(VisibleToCurrentUser::class)
            ->where(fn (Builder $q) => $q->whereNull($column)->when($userId !== null, fn (Builder $q) => $q->orWhere($column, $userId)));
    }

    public function isPersonal(): bool
    {
        return $this->owner_id !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
