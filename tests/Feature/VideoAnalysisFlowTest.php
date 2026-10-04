<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideo;
use App\Models\AiUsageLog;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use App\Services\Analysis\DummyVideoAnalyzer;
use App\Services\Analysis\VideoAnalyzer;
use App\Services\Knowledge\KnowledgeCurator;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class VideoAnalysisFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Video $video;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->seed(KnowledgeRelationTypeSeeder::class);

        $this->user = User::factory()->create();
        $this->video = Video::factory()->create(['duration_seconds' => 600]);
        $this->actingAs($this->user);
    }

    public function test_pressing_analyze_queues_a_new_analysis_version(): void
    {
        Queue::fake();

        Livewire::test('pages::videos.show', ['video' => $this->video])
            ->call('analyze')
            ->assertHasNoErrors()
            ->assertSee('待機中');

        $analysis = $this->video->analyses()->sole();
        $this->assertSame(1, $analysis->version);
        $this->assertSame(VideoAnalysis::STATUS_QUEUED, $analysis->status);
        $this->assertSame($this->user->id, $analysis->requested_by);
        Queue::assertPushed(AnalyzeVideo::class, fn (AnalyzeVideo $job) => $job->analysis->is($analysis));
    }

    public function test_a_second_request_is_rejected_while_analysis_is_in_progress(): void
    {
        Queue::fake();
        VideoAnalysis::factory()->for($this->video)->running()->create();

        Livewire::test('pages::videos.show', ['video' => $this->video])
            ->call('analyze')
            ->assertHasErrors('analysis');

        $this->assertSame(1, $this->video->analyses()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_job_stores_the_result_and_creates_knowledge_candidates_with_sources(): void
    {
        $analysis = $this->runAnalysis();

        $this->assertSame(VideoAnalysis::STATUS_COMPLETED, $analysis->status);
        $this->assertSame(100, $analysis->progress);
        $this->assertSame(DummyVideoAnalyzer::MODEL, $analysis->model);
        $this->assertCount(3, $analysis->content['concepts']);
        $this->assertSame($analysis->summary, $this->video->refresh()->summary);

        $videoNode = KnowledgeNode::where('video_id', $this->video->id)->sole();
        $analysisNode = $analysis->knowledgeNode;
        $this->assertTrue($analysisNode->outgoingEdges()->where('target_node_id', $videoNode->id)->where('status', KnowledgeNode::STATUS_CONFIRMED)->exists());

        $candidates = $analysisNode->incomingEdges()->with('sourceNode', 'sources')->get();
        $this->assertCount(3, $candidates);
        $candidates->each(function (KnowledgeEdge $edge) use ($analysis) {
            $this->assertSame(KnowledgeNode::STATUS_CANDIDATE, $edge->status);
            $this->assertSame(KnowledgeNode::STATUS_CANDIDATE, $edge->sourceNode->status);
            $this->assertSame(KnowledgeNode::PROPOSED_BY_AI, $edge->sourceNode->proposed_by);

            $source = $edge->sources->sole();
            $this->assertSame($analysis->id, $source->video_analysis_id);
            $this->assertNotNull($source->start_seconds);
        });
    }

    public function test_reanalysis_reuses_existing_concept_nodes(): void
    {
        $this->runAnalysis();
        $this->runAnalysis();

        $this->assertSame([1, 2], $this->video->analyses()->orderBy('version')->pluck('version')->all());
        $this->assertSame(3, KnowledgeNode::where('node_type', KnowledgeNode::TYPE_CONCEPT)->count());
        $this->assertSame(1, KnowledgeNode::where('node_type', KnowledgeNode::TYPE_VIDEO)->count());
    }

    public function test_the_analysis_records_ai_usage_linked_to_the_analysis(): void
    {
        $analysis = $this->runAnalysis();

        $log = AiUsageLog::sole();
        $this->assertSame(AiUsageLog::FEATURE_ANALYSIS, $log->feature);
        $this->assertTrue($log->usable->is($analysis));
        $this->assertSame(DummyVideoAnalyzer::MODEL, $log->model);
        $this->assertGreaterThan(0, $log->input_tokens);
        $this->assertSame('0.000000', $log->estimated_cost);
    }

    public function test_a_concept_extracted_again_after_merging_uses_the_surviving_node(): void
    {
        $first = $this->runAnalysis();
        $title = $first->content['concepts'][0];
        $merged = KnowledgeNode::where('title', $title)->sole();
        $survivor = KnowledgeNode::factory()->create(['title' => '統合先の概念']);
        app(KnowledgeCurator::class)->mergeNode($merged, $survivor, $this->user);

        $second = $this->runAnalysis();

        $this->assertSame(1, KnowledgeNode::where('title', $title)->count());
        $this->assertTrue($second->knowledgeNode->incomingEdges()->where('source_node_id', $survivor->id)->exists());
    }

    public function test_the_page_shows_the_summary_key_points_and_candidates(): void
    {
        $analysis = $this->runAnalysis();
        $concept = $analysis->content['concepts'][0];

        Livewire::test('pages::videos.show', ['video' => $this->video])
            ->assertSee('完了')
            ->assertSee($analysis->summary)
            ->assertSee('1:00〜1:30')
            ->assertSee($concept)
            ->assertSee('候補');
    }

    public function test_a_failing_analyzer_marks_the_analysis_as_failed(): void
    {
        $this->app->bind(VideoAnalyzer::class, fn () => new class implements VideoAnalyzer
        {
            public function analyze(Video $video, callable $reportProgress): never
            {
                throw new RuntimeException('AI の応答がありません。');
            }
        });

        $this->runAnalysis();

        $analysis = $this->video->analyses()->sole();
        $this->assertSame(VideoAnalysis::STATUS_FAILED, $analysis->status);
        $this->assertSame('AI の応答がありません。', $analysis->error_message);
        $this->assertSame(0, KnowledgeNode::where('node_type', KnowledgeNode::TYPE_CONCEPT)->count());
    }

    /**
     * 画面から分析を依頼し、キューのジョブをその場で実行する。
     */
    private function runAnalysis(): VideoAnalysis
    {
        Queue::fake();

        Livewire::test('pages::videos.show', ['video' => $this->video])->call('analyze');

        Queue::pushed(AnalyzeVideo::class)->each(function (AnalyzeVideo $job) {
            try {
                app()->call([$job, 'handle']);
            } catch (RuntimeException $e) {
                $job->failed($e);
            }
        });

        return $this->video->analyses()->orderByDesc('version')->first()->refresh();
    }
}
