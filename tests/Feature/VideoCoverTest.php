<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VideoCoverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['remotion.still_server.url' => 'http://host.docker.internal:3399', 'remotion.still_server.token' => 'secret']);
        $this->user = User::factory()->create();
    }

    public function test_a_cover_is_drawn_on_windows_and_saved(): void
    {
        $video = Video::factory()->webPage()->create(['title' => 'ChatGPT', 'genre' => 'investment']);
        VideoAnalysis::factory()->completed()->for($video)->create(['content' => ['topics' => ['銘柄検索', 'チャート', '', '比較', '手数料', '積立', '税金']]]);
        Http::fake(['host.docker.internal:3399/still' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png', 'X-Width' => '1920', 'X-Height' => '1080'])]);

        Livewire::actingAs($this->user)
            ->test('pages::videos.show', ['video' => $video])
            ->assertSet('coverHeading', 'ChatGPT')
            ->assertSet('coverSubheading', 'Example Docs')
            ->set('coverHeading', '投資信託のチャートを見る')
            ->set('coverSubheading', '')
            ->call('createCover')
            ->assertHasNoErrors()
            ->assertSee('表紙（静止画）を作り直す');

        $video->refresh();
        $this->assertSame('videos/covers/'.$video->id.'.png', $video->cover_path);
        Storage::disk('local')->assertExists($video->cover_path);
        $this->assertSame('投資信託のチャートを見る', $video->cover_content['heading']);
        $this->assertNull($video->cover_content['subheading']);
        $this->assertSame(['銘柄検索', 'チャート', '比較', '手数料', '積立'], $video->cover_content['topics']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret')
            && $request['inputProps']['scene']['scene_type'] === 'remotion.cover'
            && $request['inputProps']['scene']['content']['cover']['heading'] === '投資信託のチャートを見る'
            && $request['inputProps']['settings']['width'] === 1920);

        // 作り直すときは、前の見出し・副題が入っている。
        Livewire::actingAs($this->user)
            ->test('pages::videos.show', ['video' => $video])
            ->assertSet('coverHeading', '投資信託のチャートを見る')
            ->assertSet('coverSubheading', '');

        $this->actingAs($this->user)->get(route('videos.cover', $video))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->user)->get(route('videos.cover', ['video' => $video, 'download' => 1]))
            ->assertOk()
            ->assertDownload();
    }

    public function test_the_list_shows_the_cover_instead_of_the_thumbnail(): void
    {
        $withCover = Video::factory()->create(['cover_path' => 'videos/covers/1.png', 'cover_created_at' => now()]);
        $withoutCover = Video::factory()->create();

        Livewire::actingAs($this->user)
            ->test('pages::videos.index')
            ->assertSeeHtml('src="'.e($withCover->coverUrl()).'"')
            ->assertDontSeeHtml('src="'.e($withCover->thumbnail_url).'"')
            ->assertSeeHtml('src="'.e($withoutCover->thumbnail_url).'"');
    }

    public function test_an_unreachable_windows_server_is_shown_on_the_page(): void
    {
        $video = Video::factory()->create();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        Livewire::actingAs($this->user)
            ->test('pages::videos.show', ['video' => $video])
            ->call('createCover')
            ->assertHasErrors('cover')
            ->assertSee('still-server-windows.mjs');

        $this->assertNull($video->refresh()->cover_path);
    }

    public function test_the_heading_is_required(): void
    {
        $video = Video::factory()->create();
        Http::fake();

        Livewire::actingAs($this->user)
            ->test('pages::videos.show', ['video' => $video])
            ->set('coverHeading', '')
            ->call('createCover')
            ->assertHasErrors(['coverHeading' => 'required']);

        Http::assertNothingSent();
    }

    public function test_a_cover_that_was_not_made_is_not_found(): void
    {
        $video = Video::factory()->create();

        $this->actingAs($this->user)->get(route('videos.cover', $video))->assertNotFound();
    }

    public function test_others_cannot_see_the_cover_of_a_personal_source(): void
    {
        $owner = User::factory()->create();
        $video = Video::factory()->personalFile($owner)->create(['cover_path' => 'videos/covers/1.png']);
        Storage::disk('local')->put('videos/covers/1.png', 'PNGDATA');

        $this->actingAs($this->user)->get(route('videos.cover', $video))->assertNotFound();
        $this->actingAs($owner)->get(route('videos.cover', $video))->assertOk();
    }
}
