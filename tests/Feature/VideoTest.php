<?php

namespace Tests\Feature;

use App\Models\Video;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_casts_duration_and_published_at(): void
    {
        $video = Video::factory()->create([
            'duration_seconds' => '125',
            'published_at' => '2026-01-02 03:04:05',
        ]);

        $video->refresh();

        $this->assertSame(125, $video->duration_seconds);
        $this->assertSame('2026-01-02 03:04:05', $video->published_at->format('Y-m-d H:i:s'));
    }

    public function test_youtube_id_must_be_unique(): void
    {
        Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ']);

        $this->expectException(UniqueConstraintViolationException::class);

        Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ']);
    }

    public function test_produced_videos_do_not_need_a_youtube_id(): void
    {
        Video::factory()->count(2)->create([
            'source_type' => Video::SOURCE_REMOTION,
            'youtube_id' => null,
            'url' => null,
            'thumbnail_url' => null,
            'storage_path' => 'videos/remotion/output.mp4',
        ]);

        $this->assertSame(2, Video::where('source_type', Video::SOURCE_REMOTION)->count());
    }

    public function test_source_type_defaults_to_youtube(): void
    {
        $video = Video::factory()->create();

        $this->assertSame(Video::SOURCE_YOUTUBE, $video->refresh()->source_type);
    }
}
