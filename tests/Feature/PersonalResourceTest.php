<?php

namespace Tests\Feature;

use App\Ai\Agents\PersonalDataAnalyst;
use App\Ai\Agents\WebPageAnalyst;
use App\Jobs\AnalyzeVideo;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Video;
use App\Services\Knowledge\KnowledgeCurator;
use App\Services\Knowledge\KnowledgeGraphExporter;
use App\Services\Knowledge\KnowledgeRuleViolation;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Tests\TestCase;

class PersonalResourceTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = "\u{FEFF}検査項目,結果,上限値,下限値\n材料 静脈血,静脈血,,\nALP(アルカリフォスファターゼ),115 H,113,38\nALB(アルブミン),3.9 L,5.1,4.1\n乳ビ,,,,\n";

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Storage::fake('local');
        $this->seed(KnowledgeRelationTypeSeeder::class);
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    public function test_an_uploaded_csv_is_stored_privately_and_only_its_owner_can_see_it(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::videos.index')
            ->set('file', UploadedFile::fake()->createWithContent('resultBlood4.csv', self::CSV))
            ->call('upload')
            ->assertHasNoErrors();

        $video = Video::sole();
        $this->assertSame(Video::SOURCE_FILE, $video->source_type);
        $this->assertSame($this->owner->id, $video->owner_id);
        $this->assertSame('healthy_longevity', $video->genre);
        $this->assertSame('resultBlood4.csv', $video->title);
        $this->assertStringStartsWith("resources/{$this->owner->id}/", $video->storage_path);
        Storage::disk('local')->assertExists($video->storage_path);
        $this->assertSame($this->owner->id, KnowledgeNode::where('video_id', $video->id)->sole()->owner_id);

        // 同じ中身は二重に登録しない。
        Livewire::test('pages::videos.index')
            ->set('file', UploadedFile::fake()->createWithContent('copy.csv', self::CSV))
            ->call('upload')
            ->assertRedirect(route('videos.show', $video));
        $this->assertSame(1, Video::count());

        $this->get(route('videos.index'))->assertSee('resultBlood4.csv')->assertSee('本人だけ');
        $this->get(route('videos.show', $video))->assertOk();

        $this->actingAs($this->other);
        $this->get(route('videos.index'))->assertDontSee('resultBlood4.csv');
        $this->get(route('videos.show', $video))->assertNotFound();
        $this->get(route('knowledge.index', ['status' => '']))->assertDontSee('resultBlood4.csv');
    }

    public function test_a_file_without_data_rows_is_rejected(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::videos.index')
            ->set('file', UploadedFile::fake()->createWithContent('empty.csv', "検査項目,結果\n乳ビ,\n"))
            ->call('upload')
            ->assertHasErrors('file');

        $this->assertSame(0, Video::count());
    }

    public function test_the_analysis_links_to_shared_concepts_and_keeps_new_concepts_private(): void
    {
        config(['services.video_analyzer.driver' => 'gemini']);
        $video = $this->uploadedFile();

        // 健康寿命の共有の概念と、他の利用者の個人の概念。
        $shared = KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => 'アルブミン値と栄養状態']);
        KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => '他人の個人の概念', 'owner_id' => $this->other->id]);

        PersonalDataAnalyst::fake([[
            'summary' => 'ALP が上限を、アルブミンが下限を外れている。',
            'topics' => ['肝機能', '栄養状態'],
            'key_points' => [
                ['point' => 'アルブミンが下限値を下回っている', 'quote' => 'ALB(アルブミン) | 3.9 L | 5.1 | 4.1'],
                ['point' => 'ALP が上限値を上回っている', 'quote' => 'データにない行'],
            ],
            'concepts' => [
                ['title' => 'アルブミン値と栄養状態', 'description' => '共有の概念と同じ。', 'confidence' => 0.8, 'key_point' => 0],
                ['title' => 'ALP高値の確認', 'description' => '新しい概念。', 'confidence' => 0.7, 'key_point' => 1],
            ],
        ]]);

        $this->actingAs($this->owner);
        Queue::fake();
        Livewire::test('pages::videos.show', ['video' => $video])->call('analyze');
        Queue::pushed(AnalyzeVideo::class)->each(fn (AnalyzeVideo $job) => app()->call([$job, 'handle']));

        PersonalDataAnalyst::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('ALP(アルカリフォスファターゼ) | 115 H | 113 | 38')
            && $prompt->contains('アルブミン値と栄養状態')
            && ! $prompt->contains('他人の個人の概念')
            && ! $prompt->contains('乳ビ')
            && ! $prompt->contains($this->owner->name));

        $analysis = $video->analyses()->sole();
        $this->assertNull($analysis->source_url);
        $this->assertStringContainsString('ALB(アルブミン) | 3.9 L | 5.1 | 4.1', $analysis->source_text);
        $this->assertSame('ALB(アルブミン) | 3.9 L | 5.1 | 4.1', $analysis->content['key_points'][0]['quote']);
        $this->assertNull($analysis->content['key_points'][1]['quote']);

        // 共有の概念は再利用し（共有のまま）、新しい概念・関係・出典は本人だけのもの。
        $this->assertNull($shared->refresh()->owner_id);
        $this->assertSame($this->owner->id, KnowledgeNode::where('title', 'ALP高値の確認')->sole()->owner_id);
        $this->assertSame($this->owner->id, $analysis->knowledgeNode->owner_id);
        $this->assertTrue(KnowledgeEdge::withoutGlobalScopes()->where('source_node_id', $shared->id)->sole()->owner_id === $this->owner->id);
        $this->assertSame(0, KnowledgeSource::withoutGlobalScopes()->whereNull('owner_id')->count());

        Livewire::test('pages::knowledge.show', ['node' => $shared])->assertSee('アルブミンが下限値を下回っている');

        // 他の利用者には、共有の概念は見えるが、個人の関係・出典・新しい概念は見えない。
        $this->actingAs($this->other);
        Livewire::test('pages::knowledge.show', ['node' => $shared])
            ->assertDontSee('アルブミンが下限値を下回っている')
            ->assertDontSee('resultBlood4.csv');
        Livewire::test('pages::knowledge.index', ['status' => ''])
            ->assertSee('アルブミン値と栄養状態')
            ->assertDontSee('ALP高値の確認')
            ->assertDontSee('resultBlood4.csv');
        $this->get(route('knowledge.show', KnowledgeNode::where('title', 'ALP高値の確認')->withoutGlobalScopes()->sole()))->assertNotFound();
    }

    public function test_a_shared_analysis_never_reuses_someone_elses_personal_concept(): void
    {
        config(['services.video_analyzer.driver' => 'gemini']);
        Http::fake(['example.com/*' => Http::response('<html><body><main><p>'.str_repeat('アルブミンは栄養状態の目安になります。', 5).'</p></main></body></html>', 200, ['Content-Type' => 'text/html'])]);
        $video = Video::factory()->webPage()->create();
        $personal = KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => 'アルブミン値と栄養状態', 'owner_id' => $this->owner->id]);

        WebPageAnalyst::fake([[
            'summary' => 'アルブミンの説明。',
            'topics' => ['栄養'],
            'key_points' => [['point' => '栄養状態の目安', 'quote' => 'アルブミンは栄養状態の目安になります。']],
            'concepts' => [['title' => 'アルブミン値と栄養状態', 'description' => '共有の説明。', 'confidence' => 0.9, 'key_point' => 0]],
        ]]);

        Queue::fake();
        $this->actingAs($this->other);
        Livewire::test('pages::videos.show', ['video' => $video])->call('analyze');
        // ジョブはログインなしで動く。
        auth()->forgetGuards();
        Queue::pushed(AnalyzeVideo::class)->each(fn (AnalyzeVideo $job) => app()->call([$job, 'handle']));

        WebPageAnalyst::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('既存の概念：なし'));
        $this->assertSame(0, $personal->outgoingEdges()->count());
        $this->assertNull(KnowledgeNode::query()->where('title', 'アルブミン値と栄養状態')->whereNull('owner_id')->sole()->owner_id);
    }

    public function test_shared_knowledge_cannot_be_merged_into_a_personal_node_and_decisions_stay_private(): void
    {
        $personal = KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => 'ALP高値の確認', 'owner_id' => $this->owner->id]);
        $shared = KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => '肝機能の指標']);
        $curator = app(KnowledgeCurator::class);

        try {
            $curator->mergeNode($shared, $personal, $this->owner);
            $this->fail('共有のノードを個人のノードに統合できてしまった。');
        } catch (KnowledgeRuleViolation) {
            $this->assertSame(KnowledgeNode::STATUS_CANDIDATE, $shared->refresh()->status);
        }

        $decision = $curator->createDecisionFromConcept($personal, '再検査で確認する', null, $this->owner);
        $this->assertSame($this->owner->id, $decision->owner_id);

        $curator->mergeNode($personal, $shared, $this->owner);
        $this->assertSame($shared->id, $personal->refresh()->merged_into_id);
        // 付け替えた関係は、個人のまま。
        $this->assertSame($this->owner->id, KnowledgeEdge::withoutGlobalScopes()->where('target_node_id', $shared->id)->sole()->owner_id);
    }

    public function test_the_shared_graph_export_leaves_out_personal_knowledge(): void
    {
        KnowledgeNode::factory()->create(['title' => '共有の知識']);
        KnowledgeNode::factory()->create(['title' => '個人の知識', 'owner_id' => $this->owner->id]);

        $labels = collect(app(KnowledgeGraphExporter::class)->merge(['nodes' => [], 'links' => []])['graph']['nodes'])->pluck('label')->implode(' ');

        $this->assertStringContainsString('共有の知識', $labels);
        $this->assertStringNotContainsString('個人の知識', $labels);
    }

    private function uploadedFile(): Video
    {
        Storage::disk('local')->put('resources/test/blood.csv', self::CSV);

        return Video::factory()->personalFile($this->owner)->create(['title' => 'resultBlood4.csv']);
    }
}
