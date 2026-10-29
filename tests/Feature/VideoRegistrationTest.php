<?php

namespace Tests\Feature;

use App\Models\KnowledgeNode;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class VideoRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();

        $this->get('/videos')->assertRedirect('/login');
    }

    public function test_the_genre_is_saved_from_the_detail_page(): void
    {
        $video = Video::factory()->create();

        Livewire::test('pages::videos.show', ['video' => $video])
            ->set('genre', 'investment')
            ->assertHasNoErrors();
        $this->assertSame('投資', $video->refresh()->genreLabel());

        Livewire::test('pages::videos.show', ['video' => $video])
            ->set('genre', 'unknown')
            ->assertHasErrors('genre')
            ->set('genre', '');
        $this->assertNull($video->refresh()->genre);
    }

    public function test_the_genre_is_saved_from_the_video_list(): void
    {
        $video = Video::factory()->create();

        Livewire::test('pages::videos.index')
            ->call('setGenre', $video->id, 'ai')
            ->assertHasNoErrors();
        $this->assertSame('ai', $video->refresh()->genre);

        Livewire::test('pages::videos.index')
            ->call('setGenre', $video->id, 'unknown')
            ->assertHasErrors("genre.{$video->id}")
            ->call('setGenre', $video->id, '');
        $this->assertNull($video->refresh()->genre);
    }

    public function test_the_video_list_can_be_filtered_by_genre(): void
    {
        Video::factory()->create(['title' => 'AIの動画', 'genre' => 'ai']);
        Video::factory()->create(['title' => '投資の動画', 'genre' => 'investment']);
        Video::factory()->create(['title' => '未設定の動画', 'genre' => null]);

        Livewire::test('pages::videos.index')
            ->set('genre', 'investment')
            ->assertSee('投資の動画')
            ->assertDontSee('AIの動画')
            ->assertDontSee('未設定の動画')
            ->set('genre', 'none')
            ->assertSee('未設定の動画')
            ->assertDontSee('投資の動画')
            ->set('genre', 'video_creation')
            ->assertSee('このジャンルの情報源はありません。')
            ->set('genre', '')
            ->assertSee('AIの動画')
            ->assertSee('投資の動画')
            ->assertSee('未設定の動画');
    }

    public function test_the_video_list_renders(): void
    {
        Video::factory()->create(['title' => '登録済みの動画']);

        $this->get('/videos')->assertOk()->assertSee('登録済みの動画');
    }

    public function test_registering_a_url_fetches_details_from_the_data_api_and_creates_a_video_node(): void
    {
        config(['services.youtube.key' => 'test-key']);
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
                'snippet' => [
                    'title' => 'Runway で写真を動かす',
                    'channelTitle' => '動画研究チャンネル',
                    'description' => '説明文',
                    'publishedAt' => '2026-09-01T10:00:00Z',
                    'thumbnails' => ['high' => ['url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg']],
                ],
                'contentDetails' => ['duration' => 'PT1H2M3S'],
            ]]]),
        ]);

        $video = $this->register('https://youtu.be/dQw4w9WgXcQ');

        $this->assertSame('dQw4w9WgXcQ', $video->youtube_id);
        $this->assertSame('Runway で写真を動かす', $video->title);
        $this->assertSame(3723, $video->duration_seconds);
        $this->assertSame('2026-09-01', $video->published_at->format('Y-m-d'));
        $this->assertSame($this->user->id, $video->registered_by);
        $this->assertSame(KnowledgeNode::TYPE_VIDEO, KnowledgeNode::where('video_id', $video->id)->sole()->node_type);

        Http::assertSent(fn ($request) => $request['key'] === 'test-key' && $request['id'] === 'dQw4w9WgXcQ');
    }

    public function test_without_an_api_key_it_falls_back_to_oembed(): void
    {
        config(['services.youtube.key' => null]);
        Http::fake([
            'www.youtube.com/oembed*' => Http::response(['title' => 'oEmbed のタイトル', 'author_name' => 'チャンネル', 'thumbnail_url' => 'https://i.ytimg.com/x.jpg']),
        ]);

        $video = $this->register('https://www.youtube.com/watch?v=dQw4w9WgXcQ');

        $this->assertSame('oEmbed のタイトル', $video->title);
        $this->assertNull($video->duration_seconds);
    }

    public function test_an_already_registered_video_is_not_fetched_again(): void
    {
        Http::fake();
        $existing = Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ']);

        Livewire::test('pages::videos.index')
            ->set('url', 'https://youtu.be/dQw4w9WgXcQ')
            ->call('register')
            ->assertRedirect(route('videos.show', $existing));

        Http::assertNothingSent();
        $this->assertSame(1, Video::count());
    }

    public function test_a_deleted_video_is_restored_when_registered_again(): void
    {
        Http::fake();
        $deleted = Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ']);
        $deleted->delete();

        Livewire::test('pages::videos.index')
            ->set('url', 'https://youtu.be/dQw4w9WgXcQ')
            ->call('register')
            ->assertRedirect(route('videos.show', $deleted));

        Http::assertNothingSent();
        $this->assertNotSoftDeleted($deleted);
        $this->assertSame(1, Video::withTrashed()->count());
    }

    public function test_an_invalid_url_shows_an_error(): void
    {
        Http::fake();

        foreach (['動画のURL', 'ftp://example.com/file', 'https://www.youtube.com/@channel'] as $url) {
            Livewire::test('pages::videos.index')
                ->set('url', $url)
                ->call('register')
                ->assertHasErrors('url');
        }

        Http::assertNothingSent();
        $this->assertSame(0, Video::count());
    }

    public function test_a_missing_video_shows_an_error(): void
    {
        config(['services.youtube.key' => 'test-key']);
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);

        Livewire::test('pages::videos.index')
            ->set('url', 'https://youtu.be/dQw4w9WgXcQ')
            ->call('register')
            ->assertHasErrors('url');

        $this->assertSame(0, Video::count());
    }

    private function register(string $url): Video
    {
        Livewire::test('pages::videos.index')
            ->set('url', $url)
            ->call('register')
            ->assertHasNoErrors();

        return Video::sole();
    }
}
