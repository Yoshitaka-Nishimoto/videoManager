<?php

namespace App\Services\Analysis;

use App\Models\Video;

/**
 * 情報源の種類で分析を振り分ける。YouTube の動画は動画として、Web ページは本文を取得して、
 * 個人のファイルはデータを文章にして分析する。
 */
class SourceTypeAnalyzer implements VideoAnalyzer
{
    public function __construct(
        private readonly VideoAnalyzer $video,
        private readonly VideoAnalyzer $webPage,
        private readonly VideoAnalyzer $file,
    ) {}

    public function analyze(Video $video, callable $reportProgress): AnalysisResult
    {
        $analyzer = match (true) {
            $video->isWebPage() => $this->webPage,
            $video->isFile() => $this->file,
            default => $this->video,
        };

        return $analyzer->analyze($video, $reportProgress);
    }
}
