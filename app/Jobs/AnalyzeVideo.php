<?php

namespace App\Jobs;

use App\Models\AiUsageLog;
use App\Models\VideoAnalysis;
use App\Services\AiUsage\AiUsageRecorder;
use App\Services\Analysis\VideoAnalyzer;
use App\Services\Knowledge\KnowledgeCandidateWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 動画を分析し、結果を video_analyses に保存して、知識ノード・エッジの候補を作る。
 */
class AnalyzeVideo implements ShouldQueue
{
    use Queueable;

    /** AI の呼び出しは費用がかかるため自動ではやり直さない。失敗したら画面から再分析する。 */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public VideoAnalysis $analysis) {}

    public function handle(VideoAnalyzer $analyzer, KnowledgeCandidateWriter $knowledge, AiUsageRecorder $usage): void
    {
        $analysis = $this->analysis;

        $analysis->update([
            'status' => VideoAnalysis::STATUS_RUNNING,
            'progress' => 5,
            'started_at' => now(),
        ]);

        // 分析中の AI 呼び出しは「analysis」機能として、この分析に紐付けて記録する。
        $result = $usage->within(AiUsageLog::FEATURE_ANALYSIS, $analysis, fn () => $analyzer->analyze(
            $analysis->video,
            fn (int $progress) => $analysis->update(['progress' => min($progress, 95)]),
        ));

        DB::transaction(function () use ($analysis, $result, $knowledge) {
            $analysis->update([
                'status' => VideoAnalysis::STATUS_COMPLETED,
                'progress' => 100,
                'summary' => $result->summary,
                'content' => $result->content(),
                'model' => $result->model,
                'prompt' => $result->prompt,
                'analyzed_at' => now(),
            ]);

            $analysis->video->update(['summary' => $result->summary]);

            $knowledge->writeAnalysis($analysis, $result);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->analysis->update([
            'status' => VideoAnalysis::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? '分析に失敗しました。',
        ]);
    }
}
