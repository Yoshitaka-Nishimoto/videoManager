<?php

namespace App\Actions\Productions;

use App\Models\ProductionRender;
use Illuminate\Support\Facades\DB;

/**
 * 書き出しを取り消す。待機中・実行中（WSL が落ちて止まったままのものを含む）の書き出しを「失敗（取り消し）」にする。
 *
 * キューに残ったジョブは動き出したときに取り消しを確かめて何もせずに終わり、
 * 実行中のジョブは書き出しのプロセスを止める（RenderProduction）。
 */
class CancelProductionRender
{
    public const MESSAGE = '取り消しました。';

    public function handle(ProductionRender $render): void
    {
        DB::transaction(function () use ($render) {
            $locked = ProductionRender::query()->whereKey($render->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isInProgress()) {
                return;
            }

            $locked->update([
                'status' => ProductionRender::STATUS_FAILED,
                'stage' => null,
                'error_message' => self::MESSAGE,
                'completed_at' => now(),
            ]);
            $render->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
