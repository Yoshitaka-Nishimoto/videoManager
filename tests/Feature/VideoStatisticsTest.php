<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class VideoStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.youtube.key' => 'test-key']);
        $this->actingAs(User::factory()->create());
    }

    public function test_registering_a_video_stores_its_statistics(): void
    {
        $this->fakeDataApi(['viewCount' => '1234567', 'likeCount' => '8900', 'commentCount' => '120']);

        Livewire::test('pages::videos.index')->set('url', 'https://youtu.be/dQw4w9WgXcQ')->call('register');

        $video = Video::sole();
        $this->assertSame([1234567, 8900, 120], [$video->view_count, $video->like_count, $video->comment_count]);
        $this->assertNotNull($video->statistics_fetched_at);
        Http::assertSent(fn ($request) => str_contains($request['part'], 'statistics'));
    }

    public function test_refreshing_updates_only_the_statistics(): void
    {
        $video = Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ', 'title' => '登録時のタイトル', 'view_count' => 100, 'statistics_fetched_at' => now()->subWeek()]);
        $this->fakeDataApi(['viewCount' => '2500', 'likeCount' => '40', 'commentCount' => '3'], title: '変わったタイトル');

        Livewire::test('pages::videos.show', ['video' => $video])
            ->call('refreshStatistics')
            ->assertHasNoErrors()
            ->assertSee('再生 2,500 回');

        $video->refresh();
        $this->assertSame(2500, $video->view_count);
        $this->assertSame('登録時のタイトル', $video->title);
        $this->assertTrue($video->statistics_fetched_at->isToday());
    }

    public function test_hidden_likes_and_disabled_comments_are_left_empty(): void
    {
        $video = Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ', 'like_count' => 10, 'comment_count' => 5]);
        $this->fakeDataApi(['viewCount' => '300']);

        Livewire::test('pages::videos.show', ['video' => $video])->call('refreshStatistics');

        $video->refresh();
        $this->assertSame(300, $video->view_count);
        $this->assertNull($video->like_count);
        $this->assertNull($video->comment_count);
    }

    public function test_statistics_need_an_api_key(): void
    {
        config(['services.youtube.key' => null]);
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 't', 'author_name' => 'a', 'thumbnail_url' => 'x'])]);
        $video = Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ', 'view_count' => null]);

        Livewire::test('pages::videos.show', ['video' => $video])
            ->call('refreshStatistics')
            ->assertHasErrors('statistics')
            ->assertSee('YOUTUBE_API_KEY');

        $this->assertNull($video->refresh()->statistics_fetched_at);
    }

    public function test_counts_are_shown_in_japanese_units(): void
    {
        $this->assertSame('—', Video::formatCount(null));
        $this->assertSame('9,999', Video::formatCount(9_999));
        $this->assertSame('1.2万', Video::formatCount(12_345));
        $this->assertSame('1万', Video::formatCount(10_000));
        $this->assertSame('345万', Video::formatCount(3_450_000));
        $this->assertSame('1.1億', Video::formatCount(110_000_000));
    }

    /**
     * @param  array<string, string>  $statistics
     */
    private function fakeDataApi(array $statistics, string $title = 'タイトル'): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
                'snippet' => ['title' => $title, 'channelTitle' => 'チャンネル', 'thumbnails' => []],
                'contentDetails' => ['duration' => 'PT5M'],
                'statistics' => $statistics,
            ]]]),
        ]);
    }
}
