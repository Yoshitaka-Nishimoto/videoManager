<?php

namespace App\Actions\Videos;

use App\Jobs\AnalyzeVideo;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Support\Facades\DB;

/**
 * 新しい版の分析を作り、分析ジョブをキューに入れる。
 */
class RequestVideoAnalysis
{
    public function handle(Video $video, User $user): VideoAnalysis
    {
        $analysis = DB::transaction(function () use ($video, $user) {
            // 同じ動画への同時リクエストで版番号が重ならないよう、動画の行をロックする。
            Video::query()->whereKey($video->id)->lockForUpdate()->first();

            $inProgress = $video->analyses()
                ->whereIn('status', [VideoAnalysis::STATUS_QUEUED, VideoAnalysis::STATUS_RUNNING])
                ->exists();

            if ($inProgress) {
                throw new AnalysisAlreadyRunningException('この動画は分析中です。完了してから再度お試しください。');
            }

            // (video_id, version) は削除済みの版とも重複できないため、削除済みも含めて次の版を決める。
            return $video->analyses()->create([
                'version' => ($video->analyses()->withTrashed()->max('version') ?? 0) + 1,
                'status' => VideoAnalysis::STATUS_QUEUED,
                'requested_by' => $user->id,
            ]);
        });

        AnalyzeVideo::dispatch($analysis)->afterCommit();

        return $analysis;
    }
}
