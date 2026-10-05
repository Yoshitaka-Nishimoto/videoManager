<?php

namespace Tests\Feature;

use App\Ai\Agents\VideoAnalyst;
use App\Models\AiUsageLog;
use App\Models\KnowledgeNode;
use App\Models\Video;
use App\Models\VideoAnalysis;
use App\Services\AiUsage\AiUsageRecorder;
use App\Services\Analysis\GeminiVideoAnalyzer;
use App\Services\Analysis\VideoAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class GeminiVideoAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_driver_setting_selects_the_analyzer(): void
    {
        config(['services.video_analyzer.driver' => 'gemini']);

        $this->assertInstanceOf(GeminiVideoAnalyzer::class, app(VideoAnalyzer::class));
    }

    public function test_an_empty_model_setting_falls_back_to_the_sdk_default(): void
    {
        config(['services.video_analyzer.driver' => 'gemini', 'services.video_analyzer.gemini_model' => '']);
        VideoAnalyst::fake([['summary' => '要約', 'topics' => [], 'key_points' => [], 'concepts' => []]]);
        $video = Video::factory()->create();

        app(VideoAnalyzer::class)->analyze($video, fn () => null);

        VideoAnalyst::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model !== '');
    }

    public function test_it_sends_the_youtube_url_and_normalises_the_answer(): void
    {
        KnowledgeNode::factory()->confirmed()->create(['title' => '入力画像の品質確認']);
        $video = Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ', 'duration_seconds' => 300]);

        VideoAnalyst::fake([[
            'summary' => '  写真から動画を作る手順を解説している。  ',
            'topics' => ['動画生成', '', 'プロンプト設計'],
            'key_points' => [
                ['start_seconds' => 30, 'end_seconds' => 60, 'point' => '入力画像を確認する'],
                ['start_seconds' => 280, 'end_seconds' => 999, 'point' => 'まとめ'],
                ['start_seconds' => 10, 'end_seconds' => 20, 'point' => ''],
            ],
            'concepts' => [
                ['title' => '入力画像の品質確認', 'description' => '生成前に確認する。', 'confidence' => 1.4, 'key_point' => 0],
                ['title' => '入力画像の品質確認', 'description' => '重複', 'confidence' => 0.5, 'key_point' => 0],
                ['title' => '動きの指示', 'description' => '動作を文章で指示する。', 'confidence' => 0.8, 'key_point' => 7],
            ],
        ]]);

        $progress = [];
        $result = app(GeminiVideoAnalyzer::class)->analyze($video, function (int $value) use (&$progress) {
            $progress[] = $value;
        });

        VideoAnalyst::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('入力画像の品質確認')
            && $prompt->attachments->first() instanceof RemoteVideo
            && $prompt->attachments->first()->url === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertSame('写真から動画を作る手順を解説している。', $result->summary);
        $this->assertSame(['動画生成', 'プロンプト設計'], $result->topics);
        $this->assertCount(2, $result->keyPoints);
        $this->assertSame(300, $result->keyPoints[1]['end_seconds']);
        $this->assertCount(2, $result->concepts);
        $this->assertSame(1.0, $result->concepts[0]['confidence']);
        $this->assertNull($result->concepts[1]['key_point']);
        $this->assertSame([15, 90], $progress);
    }

    public function test_gemini_usage_is_recorded_against_the_analysis(): void
    {
        $analysis = VideoAnalysis::factory()->create();
        VideoAnalyst::fake([['summary' => '要約', 'topics' => [], 'key_points' => [], 'concepts' => []]]);
        $recorder = app(AiUsageRecorder::class);

        $recorder->within(AiUsageLog::FEATURE_ANALYSIS, $analysis, fn () => app(GeminiVideoAnalyzer::class)->analyze($analysis->video, fn () => null));

        $log = AiUsageLog::sole();
        $this->assertSame(AiUsageLog::FEATURE_ANALYSIS, $log->feature);
        $this->assertTrue($log->usable->is($analysis));
    }

    public function test_only_youtube_videos_can_be_analysed(): void
    {
        VideoAnalyst::fake();
        $video = Video::factory()->create(['source_type' => Video::SOURCE_REMOTION, 'youtube_id' => null]);

        $this->expectException(InvalidArgumentException::class);

        app(GeminiVideoAnalyzer::class)->analyze($video, fn () => null);
    }
}
