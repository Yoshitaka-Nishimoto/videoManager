<?php

namespace App\Services\Analysis;

use App\Models\Video;

/**
 * 動画を分析し、要約・要点・概念を返す。
 *
 * 現在はダミー実装（DummyVideoAnalyzer）のみ。本物の AI 実装を追加したら AppServiceProvider で差し替える。
 */
interface VideoAnalyzer
{
    /**
     * @param  callable(int): void  $reportProgress  0〜100 の進捗を通知する
     */
    public function analyze(Video $video, callable $reportProgress): AnalysisResult;
}
