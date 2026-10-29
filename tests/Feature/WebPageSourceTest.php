<?php

namespace Tests\Feature;

use App\Ai\Agents\WebPageAnalyst;
use App\Jobs\AnalyzeVideo;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Video;
use App\Services\Analysis\GeminiWebPageAnalyzer;
use App\Services\Web\WebPageClient;
use App\Services\Web\WebPageException;
use Database\Seeders\KnowledgeRelationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Tests\TestCase;

class WebPageSourceTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = <<<'HTML'
        <html><head>
            <title>Guide - Example</title>
            <meta property="og:title" content="入門ガイド">
            <meta property="og:site_name" content="Example Docs">
            <meta property="og:description" content="使い方の説明">
            <meta property="og:image" content="/images/og.png">
        </head><body>
            <nav>メニュー</nav>
            <main>
                <h1>入門ガイド</h1>
                <p>生成の前に入力画像の解像度を確認すると、失敗を減らせます。</p>
                <ul><li>解像度は 1280px 以上</li><li>顔が正面を向いている</li></ul>
                <script>console.log('除く')</script>
            </main>
            <footer>著作権表示</footer>
        </body></html>
        HTML;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->seed(KnowledgeRelationTypeSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_a_web_page_is_registered_with_its_metadata_and_a_source_node(): void
    {
        Http::fake(['example.com/*' => Http::response(self::PAGE, 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        Livewire::test('pages::videos.index')
            ->set('url', 'HTTPS://Example.com/docs/guide/#install')
            ->call('register')
            ->assertHasNoErrors();

        $video = Video::sole();
        $this->assertSame(Video::SOURCE_WEB, $video->source_type);
        $this->assertSame('https://example.com/docs/guide', $video->url);
        $this->assertSame('入門ガイド', $video->title);
        $this->assertSame('Example Docs', $video->channel_title);
        $this->assertSame('https://example.com/images/og.png', $video->thumbnail_url);
        $this->assertSame('Web', KnowledgeNode::where('video_id', $video->id)->sole()->typeLabel());

        // 同じページは取り直さない。
        Livewire::test('pages::videos.index')
            ->set('url', 'https://example.com/docs/guide')
            ->call('register')
            ->assertRedirect(route('videos.show', $video));
        Http::assertSentCount(1);
    }

    public function test_pages_that_need_a_login_and_pdfs_are_rejected(): void
    {
        Http::fake([
            'example.com/private' => Http::response('', 403),
            'example.com/paper.pdf' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
        ]);

        Livewire::test('pages::videos.index')->set('url', 'https://example.com/private')->call('register')->assertHasErrors('url')->assertSee('ログインが必要');
        Livewire::test('pages::videos.index')->set('url', 'https://example.com/paper.pdf')->call('register')->assertHasErrors('url')->assertSee('PDF');

        $this->assertSame(0, Video::count());
    }

    public function test_the_main_text_keeps_headings_and_drops_navigation_and_scripts(): void
    {
        Http::fake(['example.com/*' => Http::response(self::PAGE, 200, ['Content-Type' => 'text/html'])]);

        $content = app(WebPageClient::class)->content('https://example.com/docs/guide');

        $this->assertStringContainsString('# 入門ガイド', $content->text);
        $this->assertStringContainsString('- 解像度は 1280px 以上', $content->text);
        $this->assertStringNotContainsString('メニュー', $content->text);
        $this->assertStringNotContainsString('console.log', $content->text);
        $this->assertStringNotContainsString('著作権表示', $content->text);
        $this->assertSame('https://example.com/docs/guide', $content->sourceUrl);
    }

    public function test_a_shift_jis_page_is_converted_to_utf8(): void
    {
        $html = mb_convert_encoding('<html><body><main><p>'.str_repeat('日本語の本文です。', 10).'</p></main></body></html>', 'SJIS-win', 'UTF-8');
        Http::fake(['example.jp/*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=Shift_JIS'])]);

        $this->assertStringContainsString('日本語の本文です。', app(WebPageClient::class)->content('https://example.jp/page')->text);
    }

    public function test_a_github_repository_is_read_from_its_readme(): void
    {
        Http::fake(['api.github.com/repos/laravel/boost/readme' => Http::response("# Laravel Boost\n\nAI エージェント向けの道具をまとめたパッケージ。", 200, ['Content-Type' => 'text/plain'])]);

        $content = app(WebPageClient::class)->content('https://github.com/laravel/boost');

        $this->assertStringContainsString('# Laravel Boost', $content->text);
        $this->assertSame('https://api.github.com/repos/laravel/boost/readme', $content->sourceUrl);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Accept', 'application/vnd.github.raw'));
    }

    public function test_a_page_without_readable_text_cannot_be_analysed(): void
    {
        Http::fake(['example.com/*' => Http::response('<html><body><div id="app"></div></body></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(WebPageException::class);

        app(WebPageClient::class)->content('https://example.com/spa');
    }

    public function test_gemini_reads_the_fetched_text_and_only_keeps_quotes_found_in_it(): void
    {
        Http::fake(['example.com/*' => Http::response(self::PAGE, 200, ['Content-Type' => 'text/html'])]);
        $video = Video::factory()->webPage()->create(['title' => '入門ガイド']);

        WebPageAnalyst::fake([[
            'summary' => '入力画像の確認方法を説明している。',
            'topics' => ['入力画像'],
            'key_points' => [
                ['point' => '解像度を確認する', 'quote' => '生成の前に入力画像の解像度を確認すると、失敗を減らせます。'],
                ['point' => '本文にない引用', 'quote' => 'どこにも書かれていない文'],
            ],
            'concepts' => [['title' => '入力画像の品質確認', 'description' => '生成前に確認する。', 'confidence' => 0.9, 'key_point' => 0]],
        ]]);

        $result = app(GeminiWebPageAnalyzer::class)->analyze($video, fn () => null);

        WebPageAnalyst::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('生成の前に入力画像の解像度を確認すると') && $prompt->contains('サイト：Example Docs'));
        $this->assertNull($result->keyPoints[0]['start_seconds']);
        $this->assertSame('生成の前に入力画像の解像度を確認すると、失敗を減らせます。', $result->keyPoints[0]['quote']);
        $this->assertNull($result->keyPoints[1]['quote']);
        $this->assertStringContainsString('# 入門ガイド', $result->source->text);
        $this->assertStringNotContainsString('生成の前に', $result->prompt);
    }

    public function test_the_analysis_stores_the_text_it_read_and_links_concepts_to_the_quote(): void
    {
        config(['services.video_analyzer.driver' => 'gemini']);
        Http::fake(['example.com/*' => Http::response(self::PAGE, 200, ['Content-Type' => 'text/html'])]);
        $video = Video::factory()->webPage()->create();
        WebPageAnalyst::fake([[
            'summary' => '入力画像の確認方法を説明している。',
            'topics' => ['入力画像'],
            'key_points' => [['point' => '解像度を確認する', 'quote' => '解像度は 1280px 以上']],
            'concepts' => [['title' => '入力画像の品質確認', 'description' => '生成前に確認する。', 'confidence' => 0.9, 'key_point' => 0]],
        ]]);

        Queue::fake();
        Livewire::test('pages::videos.show', ['video' => $video])->call('analyze');
        Queue::pushed(AnalyzeVideo::class)->each(fn (AnalyzeVideo $job) => app()->call([$job, 'handle']));

        $analysis = $video->analyses()->sole();
        $this->assertSame('https://example.com/docs/guide', $analysis->source_url);
        $this->assertStringContainsString('顔が正面を向いている', $analysis->source_text);
        $this->assertNotNull($analysis->source_fetched_at);

        $source = KnowledgeSource::sole();
        $this->assertNull($source->start_seconds);
        $this->assertSame("解像度を確認する\n「解像度は 1280px 以上」", $source->excerpt);

        Livewire::test('pages::videos.show', ['video' => $video])
            ->assertSee('分析に使った本文')
            ->assertSee('「解像度は 1280px 以上」')
            ->assertSee('https://example.com/docs/guide#:~:text='.rawurlencode('解像度は 1280px 以上'), escape: false)
            ->assertDontSee('再生時間');
    }

    public function test_the_dummy_analyzer_gives_web_pages_no_timestamps(): void
    {
        $video = Video::factory()->webPage()->create();

        Queue::fake();
        Livewire::test('pages::videos.show', ['video' => $video])->call('analyze');
        Queue::pushed(AnalyzeVideo::class)->each(fn (AnalyzeVideo $job) => app()->call([$job, 'handle']));

        $analysis = $video->analyses()->sole();
        $this->assertNull($analysis->content['key_points'][0]['start_seconds']);
        $this->assertNull($analysis->source_text);
        $this->assertTrue(KnowledgeSource::query()->whereNull('start_seconds')->exists());
    }
}
