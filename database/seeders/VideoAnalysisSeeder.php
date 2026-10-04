<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\Seeder;

class VideoAnalysisSeeder extends Seeder
{
    private const TOPICS = [
        '動画生成', '画像処理', 'プロンプト設計', '字幕編集', 'データベース設計',
        'ナレッジグラフ', '非同期処理', 'AIエージェント', 'UI設計', 'テスト自動化',
    ];

    private const CONCEPTS = [
        '入力画像の品質確認', '顔の向きの制御', '口の動きの同期', '字幕のタイミング調整',
        'BGMの音量調整', '候補と確定の区別', '根拠への逆引き', '重複概念の統合',
        '分析の版管理', '類似検索', 'ジョブの再試行', 'テンプレートによる動画組み立て',
    ];

    /**
     * Seed one to three analysis versions for every video that has none yet.
     */
    public function run(): void
    {
        $requesterId = User::query()->value('id');

        Video::query()->doesntHave('analyses')->each(function (Video $video) use ($requesterId) {
            $versions = fake()->numberBetween(1, 3);
            $analyzedAt = now()->subDays(fake()->numberBetween(10, 30));

            for ($version = 1; $version <= $versions; $version++) {
                $isLatest = $version === $versions;
                $analyzedAt = $analyzedAt->copy()->addDays(fake()->numberBetween(1, 3));

                $factory = VideoAnalysis::factory()->for($video);

                if ($isLatest && fake()->boolean(15)) {
                    $factory = $factory->failed();
                } elseif ($isLatest && fake()->boolean(10)) {
                    $factory = $factory->running();
                } else {
                    $factory = $factory->completed()->state([
                        'summary' => "「{$video->title}」の要約（第{$version}版）: ".fake()->realText(120),
                        'content' => $this->contentFor($video),
                        'analyzed_at' => $analyzedAt,
                        'started_at' => $analyzedAt->copy()->subMinutes(fake()->numberBetween(1, 10)),
                    ]);
                }

                $factory->create([
                    'version' => $version,
                    'requested_by' => $requesterId,
                ]);
            }

            $video->update([
                'summary' => $video->analyses()
                    ->where('status', VideoAnalysis::STATUS_COMPLETED)
                    ->latest('version')
                    ->value('summary'),
            ]);
        });
    }

    /**
     * Build analysis content whose timestamps fit within the video's duration.
     *
     * @return array<string, mixed>
     */
    private function contentFor(Video $video): array
    {
        $duration = $video->duration_seconds ?? 600;

        $keyPoints = collect(range(1, fake()->numberBetween(2, 4)))
            ->map(fn () => fake()->numberBetween(0, max(0, $duration - 30)))
            ->sort()
            ->values()
            ->map(fn (int $start) => [
                'start_seconds' => $start,
                'end_seconds' => min($duration, $start + fake()->numberBetween(10, 90)),
                'point' => fake()->realText(40),
            ])
            ->all();

        return [
            'topics' => fake()->randomElements(self::TOPICS, fake()->numberBetween(2, 4)),
            'key_points' => $keyPoints,
            'concepts' => fake()->randomElements(self::CONCEPTS, 3),
        ];
    }
}
