<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_video_keeps_every_analysis_version(): void
    {
        $video = Video::factory()->create();
        VideoAnalysis::factory()->for($video)->completed()->create(['version' => 1]);
        VideoAnalysis::factory()->for($video)->create(['version' => 2]);

        $this->assertSame([1, 2], $video->analyses()->orderBy('version')->pluck('version')->all());
    }

    public function test_version_must_be_unique_per_video(): void
    {
        $video = Video::factory()->create();
        VideoAnalysis::factory()->for($video)->create(['version' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        VideoAnalysis::factory()->for($video)->create(['version' => 1]);
    }

    public function test_content_is_cast_to_array(): void
    {
        $analysis = VideoAnalysis::factory()->create(['content' => ['topics' => ['laravel']]]);

        $this->assertSame(['topics' => ['laravel']], $analysis->refresh()->content);
    }

    public function test_a_video_with_analyses_cannot_be_deleted(): void
    {
        $analysis = VideoAnalysis::factory()->create();

        $this->expectException(QueryException::class);

        $analysis->video->delete();
    }
}
