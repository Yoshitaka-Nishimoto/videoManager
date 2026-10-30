<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * ログイン中の利用者には、共有のもの（owner_id が空）と自分のものだけを見せる。
 *
 * 画面のどの一覧・件数・関連の読み込みからも、他の利用者の個人のデータが漏れないよう、全体にかける。
 * ログインしていない処理（キューのジョブ・コマンド）にはかからないので、そこでは HasOwner::scopeVisibleTo で明示的に絞る。
 */
class VisibleToCurrentUser implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $column = $model->qualifyColumn('owner_id');

        $builder->where(fn (Builder $query) => $query->whereNull($column)->orWhere($column, $user->getAuthIdentifier()));
    }
}
