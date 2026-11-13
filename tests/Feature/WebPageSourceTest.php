<?php

namespace Tests\Feature;

use App\Ai\Agents\WebPageAnalyst;
use App\Jobs\AnalyzeVideo;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
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

    private const CONVERSATION = <<<'TXT'
        あなた:
        Remotion で動画に字幕を付けるにはどうすればいいですか？

        ChatGPT:
        @remotion/captions の parseSrt で SRT を読み込み、現在のフレームに合う字幕だけを表示します。
        TXT;

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

    public function test_pdfs_and_missing_pages_are_rejected(): void
    {
        Http::fake([
            'example.com/missing' => Http::response('', 404),
            'example.com/paper.pdf' => Http::response('%PDF', 200, ['Content-Type' => 'application/pdf']),
        ]);

        Livewire::test('pages::videos.index')->set('url', 'https://example.com/missing')->call('register')->assertHasErrors('url')->assertSee('404');
        Livewire::test('pages::videos.index')->set('url', 'https://example.com/paper.pdf')->call('register')->assertHasErrors('url')->assertSee('PDF');

        $this->assertSame(0, Video::count());
    }

    public function test_a_page_that_needs_a_login_is_registered_with_its_host_so_its_text_can_be_pasted(): void
    {
        Http::fake(['claude.ai/*' => Http::response('', 403)]);

        Livewire::test('pages::videos.index')
            ->set('url', 'https://claude.ai/chat/abc')
            ->call('register')
            ->assertHasNoErrors();

        $video = Video::sole();
        $this->assertSame('claude.ai', $video->title);
        $this->assertSame('claude.ai', $video->channel_title);
        $this->assertTrue(KnowledgeNode::where('video_id', $video->id)->exists());

        Livewire::test('pages::videos.show', ['video' => $video])->assertSee('本文を貼り付ける');
    }

    public function test_pasted_text_and_a_corrected_title_are_saved_and_the_source_node_follows_the_title(): void
    {
        Http::fake(['chatgpt.com/*' => Http::response('<html><head><title>ChatGPT</title></head><body></body></html>', 200, ['Content-Type' => 'text/html'])]);
        Livewire::test('pages::videos.index')->set('url', 'https://chatgpt.com/c/abc')->call('register');
        $video = Video::sole();
        $this->assertSame('ChatGPT', $video->title);

        Livewire::test('pages::videos.show', ['video' => $video])
            ->set('title', 'Remotion の字幕の付け方（ChatGPT）')
            ->set('pastedText', '短い')
            ->call('savePastedText')
            ->assertHasErrors('pastedText')
            ->set('pastedText', self::CONVERSATION)
            ->call('savePastedText')
            ->assertHasNoErrors()
            ->assertSet('pastedText', '')
            ->assertSee('貼り付けた本文で分析します');

        $video->refresh();
        $this->assertTrue($video->hasPastedText());
        $this->assertNotNull($video->pasted_text_updated_at);
        $this->assertSame('Remotion の字幕の付け方（ChatGPT）', $video->title);
        $node = KnowledgeNode::where('video_id', $video->id)->sole();
        $this->assertSame('Remotion の字幕の付け方（ChatGPT）', $node->title);
        $this->assertSame('情報源の題名を修正', $node->revisions()->latest('id')->first()->reason);

        // 本文を空のまま保存しても、保存済みの本文は消えない。
        Livewire::test('pages::videos.show', ['video' => $video])->call('savePastedText')->assertHasNoErrors();
        $this->assertTrue($video->refresh()->hasPastedText());

        Livewire::test('pages::videos.show', ['video' => $video])->call('clearPastedText');
        $this->assertFalse($video->refresh()->hasPastedText());
        $this->assertNull($video->pasted_text_updated_at);
    }

    public function test_image_names_in_pasted_text_are_shown_as_the_images_of_the_original_page(): void
    {
        Http::fake(['example.com/help/order/detail.html' => Http::response(<<<'HTML'
            <html><body>
                <img src="/help/images/logo.svg" alt="楽天証券">
                <img alt="詳細を見る①.png" src="https://example.com/help/d32d.png">
                <img alt="" src="image/%E8%A9%B3%E7%B4%B0%E2%91%A1.png">
                <img alt="本文にない画像.png" src="/help/other.png">
            </body></html>
            HTML, 200, ['Content-Type' => 'text/html'])]);
        $video = Video::factory()->webPage('https://example.com/help/order/detail.html')->create();
        $text = "ファンドの詳細情報を確認するには、詳細画面を開きます。\n\n詳細を見る①.png\nタブを切り替えると各カテゴリの情報を確認できます。\n詳細②.png\n楽天証券";

        Livewire::test('pages::videos.show', ['video' => $video])
            ->set('pastedText', $text)
            ->call('savePastedText')
            ->assertHasNoErrors()
            ->assertSee('本文中の画像 2 枚')
            ->assertSee('<img src="https://example.com/help/d32d.png" alt="詳細を見る①.png"', escape: false)
            ->assertSee('<img src="https://example.com/help/order/image/%E8%A9%B3%E7%B4%B0%E2%91%A1.png" alt="詳細②.png"', escape: false)
            ->assertDontSee('logo.svg')
            ->assertSee('タブを切り替えると');

        $this->assertSame([
            '詳細を見る①.png' => 'https://example.com/help/d32d.png',
            '詳細②.png' => 'https://example.com/help/order/image/%E8%A9%B3%E7%B4%B0%E2%91%A1.png',
        ], $video->refresh()->pasted_images);
    }

    public function test_pasted_text_is_saved_without_images_when_the_page_cannot_be_opened(): void
    {
        Http::fake(['chatgpt.com/*' => Http::response('', 403)]);
        $video = Video::factory()->webPage('https://chatgpt.com/c/abc')->create();

        Livewire::test('pages::videos.show', ['video' => $video])
            ->set('pastedText', self::CONVERSATION."\nscreenshot.png")
            ->call('savePastedText')
            ->assertHasNoErrors();

        $video->refresh();
        $this->assertTrue($video->hasPastedText());
        $this->assertNull($video->pasted_images);
    }

    public function test_the_analysis_uses_the_pasted_text_without_fetching_the_page(): void
    {
        config(['services.video_analyzer.driver' => 'gemini']);
        Http::fake();
        $video = Video::factory()->webPage('https://chatgpt.com/c/abc')->create([
            'title' => 'Remotion の字幕',
            'pasted_text' => self::CONVERSATION,
            'pasted_text_updated_at' => now(),
        ]);
        WebPageAnalyst::fake([[
            'summary' => '利用者が字幕の付け方を尋ね、AI は SRT を読み込む方法を説明している。',
            'topics' => ['字幕'],
            'key_points' => [['point' => 'SRT を読み込んで表示する', 'quote' => '@remotion/captions の parseSrt で SRT を読み込み']],
            'concepts' => [['title' => '字幕ファイルの読み込み', 'description' => 'SRT を読み込んで表示する。', 'confidence' => 0.6, 'key_point' => 0]],
        ]]);

        Queue::fake();
        Livewire::test('pages::videos.show', ['video' => $video])->call('analyze');
        Queue::pushed(AnalyzeVideo::class)->each(fn (AnalyzeVideo $job) => app()->call([$job, 'handle']));

        Http::assertNothingSent();
        WebPageAnalyst::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('貼り付けた文章') && $prompt->contains('parseSrt'));

        $analysis = $video->analyses()->sole();
        $this->assertSame(VideoAnalysis::STATUS_COMPLETED, $analysis->status);
        $this->assertNull($analysis->source_url);
        $this->assertStringContainsString('parseSrt', $analysis->source_text);
        $this->assertSame('@remotion/captions の parseSrt で SRT を読み込み', $analysis->content['key_points'][0]['quote']);

        Livewire::test('pages::videos.show', ['video' => $video])->assertSee('貼り付けた本文を使用');
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
