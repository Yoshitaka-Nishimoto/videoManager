<?php

namespace App\Services\Analysis;

use App\Models\Video;
use App\Services\AiUsage\AiUsageRecorder;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * API キーなしで分析の流れを確認するためのダミー AI。
 *
 * 動画 ID から決まった組み合わせを選ぶので、同じ動画は何度分析しても同じ結果になる。
 */
class DummyVideoAnalyzer implements VideoAnalyzer
{
    public const MODEL = 'dummy';

    private const TOPICS = [
        '動画生成', '画像処理', 'プロンプト設計', '字幕編集', 'データベース設計',
        'ナレッジグラフ', '非同期処理', 'AIエージェント', 'UI設計', 'テスト自動化',
    ];

    private const CONCEPTS = [
        '入力画像の品質確認' => '生成前に入力画像の解像度・明るさ・顔の写り方を確認し、失敗を防ぐ。',
        '顔の向きの制御' => '写真から動画を作るときに、顔の角度や視線を指示で調整する。',
        '口の動きの同期' => '台詞の音声と口の動きを合わせ、自然に話しているように見せる。',
        '字幕のタイミング調整' => '音声に合わせて字幕の表示・非表示の時刻を調整する。',
        'BGMの音量調整' => '話し声を邪魔しないように、BGM の音量を場面ごとに下げる。',
        '候補と確定の区別' => 'AI の提案をすぐに確定せず、人が確認してから採用する。',
        '根拠への逆引き' => '知識から元の動画・分析・会話へ戻れるようにする。',
        '分析の版管理' => '再分析しても古い結果を上書きせず、版として残す。',
        'ジョブの再試行' => '時間のかかる処理が失敗したとき、キューで自動的にやり直す。',
        'テンプレートによる動画組み立て' => '決まった構成のテンプレートに素材を流し込んで動画を作る。',
    ];

    /** 各段階の待ち時間（秒）。画面で進捗の変化を確認できるようにする。 */
    private const STEP_SECONDS = 2;

    public function __construct(private readonly AiUsageRecorder $usage) {}

    public function analyze(Video $video, callable $reportProgress): AnalysisResult
    {
        $seed = crc32($video->youtube_id ?? (string) $video->id);
        $duration = max(60, $video->duration_seconds ?? 600);

        $this->step($reportProgress, 20);
        $topics = $this->pick(self::TOPICS, 3, $seed);

        $this->step($reportProgress, 45);
        $keyPoints = collect([0.1, 0.35, 0.6, 0.85])
            ->map(fn (float $ratio, int $index) => [
                'start_seconds' => $start = (int) ($duration * $ratio),
                'end_seconds' => min($duration, $start + 30),
                'point' => sprintf('%s の説明（要点%d）', $topics[$index % count($topics)], $index + 1),
            ])
            ->all();

        $this->step($reportProgress, 70);
        $concepts = collect($this->pick(array_keys(self::CONCEPTS), 3, $seed >> 3))
            ->map(fn (string $title, int $index) => [
                'title' => $title,
                'description' => self::CONCEPTS[$title],
                'confidence' => round(0.6 + (($seed >> $index) % 35) / 100, 3),
                'key_point' => $index % count($keyPoints),
            ])
            ->all();

        $summary = sprintf(
            '「%s」は、%sについて解説した動画です。特に「%s」と「%s」が重要な概念として扱われています。',
            Str::limit($video->title, 60),
            implode('・', $topics),
            $concepts[0]['title'],
            $concepts[1]['title'],
        );

        // 本物の AI を呼んだ場合と同じく使用量を記録する（費用は 0）。トークン数は動画の長さからの目安。
        $this->usage->record(
            provider: 'dummy',
            model: self::MODEL,
            inputTokens: 500 + intdiv($duration, 2),
            outputTokens: mb_strlen($summary) * 2,
        );

        return new AnalysisResult(
            summary: $summary,
            topics: $topics,
            keyPoints: $keyPoints,
            concepts: $concepts,
            model: self::MODEL,
            prompt: 'ダミー分析（AI は呼び出していません）',
        );
    }

    /**
     * @param  callable(int): void  $reportProgress
     */
    private function step(callable $reportProgress, int $progress): void
    {
        Sleep::for(self::STEP_SECONDS)->seconds();
        $reportProgress($progress);
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function pick(array $items, int $count, int $seed): array
    {
        return collect($items)
            ->sortBy(fn (string $item) => crc32($item.$seed))
            ->take($count)
            ->values()
            ->all();
    }
}
