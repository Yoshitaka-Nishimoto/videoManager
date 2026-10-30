<?php

namespace Tests\Feature;

use App\Jobs\EmbedKnowledgeNodes;
use App\Models\AiUsageLog;
use App\Models\KnowledgeCodeLink;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use App\Services\Analysis\AnalysisResult;
use App\Services\Knowledge\KnowledgeCandidateWriter;
use App\Services\Knowledge\KnowledgeEmbedder;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class KnowledgeEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeEmbedder $embedder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KnowledgeRelationTypeSeeder::class);
        $this->embedder = app(KnowledgeEmbedder::class);

        // 「品質」を含む文どうし、それ以外どうしが近くなる偽の埋め込み。
        Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn (string $text) => $this->vector(str_contains($text, '品質') ? 0 : 1), $prompt->inputs));
    }

    public function test_decisions_and_implementations_are_described_with_their_linked_knowledge_and_code(): void
    {
        $concept = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認']);
        $decision = KnowledgeNode::factory()->decision()->create(['title' => '生成前に入力画像を検査する', 'description' => null]);
        $implementation = KnowledgeNode::factory()->implementation()->create(['title' => '入力画像の検査', 'description' => '']);
        $this->connect($decision, KnowledgeRelationType::KEY_INSPIRED_BY, $concept);
        $this->connect($implementation, KnowledgeRelationType::KEY_IMPLEMENTS, $decision);
        KnowledgeCodeLink::factory()->for($implementation, 'node')->create(['path' => 'app/Services/Runway/InputImageChecker.php', 'symbol' => 'InputImageChecker::check']);
        KnowledgeCodeLink::factory()->for($implementation, 'node')->create(['path' => 'app/Old.php', 'status' => KnowledgeNode::STATUS_DEPRECATED]);

        $this->assertSame("設計判断：生成前に入力画像を検査する\n着想元の概念：入力画像の品質確認", $this->embedder->text($decision));
        $this->assertSame(
            "実装：入力画像の検査\n実装する設計判断・概念：生成前に入力画像を検査する\nコード：app/Services/Runway/InputImageChecker.php InputImageChecker::check",
            $this->embedder->text($implementation),
        );
    }

    public function test_a_video_is_described_with_the_summary_from_the_videos_table(): void
    {
        $video = Video::factory()->create(['title' => 'AI ニュース', 'summary' => '新しいモデルの発表をまとめた。']);
        $node = KnowledgeNode::factory()->forVideo($video)->create(['title' => 'AI ニュース', 'description' => null]);

        $this->assertSame("動画：AI ニュース\n要約：新しいモデルの発表をまとめた。", $this->embedder->text($node->load('video')));
    }

    public function test_embeddings_are_stored_and_only_rebuilt_when_the_text_changes(): void
    {
        $node = KnowledgeNode::factory()->create(['title' => '入力画像の品質確認', 'description' => '解像度を確認する。']);
        $retired = KnowledgeNode::factory()->create(['status' => KnowledgeNode::STATUS_DEPRECATED]);

        $this->assertSame(1, $this->embedder->embed(KnowledgeNode::all()));

        $node->refresh();
        $this->assertCount(1536, $node->embedding);
        $this->assertSame('gemini-embedding-2', $node->embedding_model);
        $this->assertSame($this->embedder->hash($node), $node->embedding_hash);
        $this->assertNotNull($node->embedded_at);
        $this->assertNull($retired->refresh()->embedding);

        $this->assertSame(0, $this->embedder->embed(KnowledgeNode::all()));

        $node->update(['description' => '解像度と顔の向きを確認する。']);
        $this->assertTrue($this->embedder->isStale($node));
        $this->assertSame(1, $this->embedder->embed([$node]));
        $this->assertSame(1, $this->embedder->embed([$node], force: true));
    }

    public function test_embedding_calls_are_recorded_as_ai_usage(): void
    {
        KnowledgeNode::factory()->count(2)->create();

        $this->embedder->embed(KnowledgeNode::all());

        $this->assertSame(1, AiUsageLog::where('feature', AiUsageLog::FEATURE_EMBEDDING)->count());
    }

    public function test_duplicate_candidates_are_close_nodes_of_the_same_knowledge_type(): void
    {
        $original = KnowledgeNode::factory()->create(['title' => '画像品質確認', 'description' => null]);
        $duplicate = KnowledgeNode::factory()->create(['title' => '入力画像の品質検査', 'description' => null]);
        KnowledgeNode::factory()->create(['title' => 'モデル階層による役割分担', 'description' => null]);
        KnowledgeNode::factory()->decision()->create(['title' => '品質を確認してから生成する', 'description' => null]);
        $analysis = KnowledgeNode::factory()->forAnalysis()->create(['title' => '品質の動画の分析', 'description' => '品質の話']);
        KnowledgeNode::factory()->forAnalysis()->create(['title' => '品質の動画の分析（第2版）', 'description' => '品質の話']);
        $this->embedder->embed(KnowledgeNode::query()->with('video')->get());

        $candidates = $this->embedder->duplicateCandidates($original->refresh());

        $this->assertSame([$duplicate->id], $candidates->pluck('id')->all());
        $this->assertEqualsWithDelta(1.0, (float) $candidates->first()->similarity, 0.001);
        $this->assertTrue($this->embedder->duplicateCandidates($analysis->refresh())->isEmpty());
    }

    public function test_searching_finds_nodes_of_any_type_by_meaning(): void
    {
        KnowledgeNode::factory()->create(['title' => '入力画像の品質確認', 'description' => null]);
        KnowledgeNode::factory()->forAnalysis()->create(['title' => '画質の動画の分析', 'description' => '品質の話']);
        KnowledgeNode::factory()->create(['title' => 'モデル階層による役割分担', 'description' => null]);
        $this->embedder->embed(KnowledgeNode::query()->with('video')->get());

        $titles = $this->embedder->search('品質をどう確かめるか')->pluck('title')->sort()->values()->all();

        $this->assertSame(['入力画像の品質確認', '画質の動画の分析'], $titles);
    }

    public function test_the_knowledge_list_can_search_by_meaning(): void
    {
        config(['services.knowledge_embeddings.enabled' => true]);
        Queue::fake();
        $user = User::factory()->create();
        KnowledgeNode::factory()->confirmed()->create(['title' => '入力画像の品質確認', 'description' => null]);
        KnowledgeNode::factory()->confirmed()->create(['title' => 'モデル階層による役割分担', 'description' => null]);
        KnowledgeNode::factory()->confirmed()->create(['title' => '他人の品質の記録', 'description' => null, 'owner_id' => User::factory()->create()->id]);
        KnowledgeNode::factory()->create(['title' => '候補の品質チェック', 'description' => null]);
        $this->embedder->embed(KnowledgeNode::withoutGlobalScopes()->get());

        $this->actingAs($user);

        // 名称の文字では一致しない。
        Livewire::test('pages::knowledge.index', ['status' => KnowledgeNode::STATUS_CONFIRMED])
            ->set('q', '画質をどう確かめるか')
            ->assertSee('該当するノードはありません。');

        Livewire::test('pages::knowledge.index', ['status' => KnowledgeNode::STATUS_CONFIRMED])
            ->set('semantic', true)
            ->set('q', '品質をどう確かめるか')
            ->assertSee('入力画像の品質確認')
            ->assertSee('類似度 1.00')
            ->assertDontSee('モデル階層による役割分担')
            ->assertDontSee('他人の品質の記録')
            ->assertDontSee('候補の品質チェック');
    }

    public function test_the_knowledge_list_falls_back_to_the_title_when_the_embedding_fails(): void
    {
        config(['services.knowledge_embeddings.enabled' => true]);
        Queue::fake();
        Embeddings::fake(fn () => throw new RuntimeException('クレジットが残っていません'));
        KnowledgeNode::factory()->confirmed()->create(['title' => '入力画像の品質確認']);

        $this->actingAs(User::factory()->create());

        Livewire::test('pages::knowledge.index', ['status' => KnowledgeNode::STATUS_CONFIRMED])
            ->set('semantic', true)
            ->set('q', '品質')
            ->assertSee('意味で検索できなかったため、名称で検索しています')
            ->assertSee('入力画像の品質確認');
    }

    public function test_the_command_embeds_stale_nodes_lists_short_descriptions_and_shows_duplicates(): void
    {
        config(['services.knowledge_embeddings.enabled' => true]);
        Queue::fake();
        KnowledgeNode::factory()->create(['title' => '画像品質確認', 'description' => null]);
        KnowledgeNode::factory()->create(['title' => '入力画像の品質検査', 'description' => null]);
        KnowledgeNode::factory()->decision()->create(['title' => '説明のない設計判断', 'description' => null]);

        $this->artisan('knowledge:embed', ['--dry-run' => true])
            ->expectsOutputToContain('説明のない設計判断')
            ->expectsOutputToContain('埋め込みを作る対象：3 件')
            ->assertSuccessful();
        $this->assertSame(0, KnowledgeNode::whereNotNull('embedding')->count());

        $this->artisan('knowledge:embed', ['--duplicates' => true])
            ->expectsOutputToContain('埋め込みを 3 件作りました')
            ->expectsOutputToContain('入力画像の品質検査')
            ->assertSuccessful();

        $this->artisan('knowledge:embed')->expectsOutputToContain('埋め込みを 0 件作りました')->assertSuccessful();
    }

    public function test_the_command_refuses_to_run_when_embeddings_are_disabled(): void
    {
        KnowledgeNode::factory()->create();

        $this->artisan('knowledge:embed')->assertFailed();
    }

    public function test_changes_to_nodes_relations_and_code_queue_a_rebuild_only_when_enabled(): void
    {
        Queue::fake();
        $implementation = KnowledgeNode::factory()->implementation()->create();
        Queue::assertNothingPushed();

        config(['services.knowledge_embeddings.enabled' => true]);
        $decision = KnowledgeNode::factory()->decision()->create();
        $this->connect($implementation, KnowledgeRelationType::KEY_IMPLEMENTS, $decision);
        KnowledgeCodeLink::factory()->for($implementation, 'node')->create();
        // 作成したのと同じインスタンスで、名称・説明以外を変えても作り直さない。
        $decision->update(['status' => KnowledgeNode::STATUS_CONFIRMED]);
        $implementation->update(['status' => KnowledgeNode::STATUS_CONFIRMED]);

        Queue::assertPushed(EmbedKnowledgeNodes::class, fn ($job) => $job->nodeIds === [$decision->id]);
        Queue::assertPushed(EmbedKnowledgeNodes::class, fn ($job) => $job->nodeIds === [$implementation->id]);
        Queue::assertPushed(EmbedKnowledgeNodes::class, 3);
    }

    public function test_an_analysis_updates_the_description_of_the_video_node(): void
    {
        $analysis = VideoAnalysis::factory()->create();
        $analysis->video->update(['summary' => null]);
        $videoNode = app(KnowledgeCandidateWriter::class)->videoNode($analysis->video);
        $this->assertNull($videoNode->description);

        app(KnowledgeCandidateWriter::class)->writeAnalysis($analysis, new AnalysisResult('新しいモデルの発表をまとめた。', [], [], [], 'dummy'));

        $this->assertSame('新しいモデルの発表をまとめた。', $videoNode->refresh()->description);
    }

    private function connect(KnowledgeNode $source, string $relation, KnowledgeNode $target): void
    {
        KnowledgeEdge::factory()->create([
            'source_node_id' => $source->id,
            'target_node_id' => $target->id,
            'relation_type_id' => KnowledgeRelationType::where('key', $relation)->value('id'),
        ]);
    }

    /**
     * 軸 $axis の方向を向いた 1536 次元のベクトル。
     *
     * @return list<float>
     */
    private function vector(int $axis): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[$axis] = 1.0;

        return $vector;
    }
}
