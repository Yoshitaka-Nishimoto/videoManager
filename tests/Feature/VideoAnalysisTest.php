<?php

namespace Tests\Feature;

use App\Actions\Videos\RequestVideoAnalysis;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_a_video_with_analyses_cannot_be_force_deleted(): void
    {
        $analysis = VideoAnalysis::factory()->create();

        $this->expectException(QueryException::class);

        $analysis->video->forceDelete();
    }

    public function test_deleted_analyses_are_hidden_but_still_reachable_from_knowledge(): void
    {
        $analysis = VideoAnalysis::factory()->completed()->create();
        $source = KnowledgeSource::factory()->create(['video_analysis_id' => $analysis->id]);

        $analysis->delete();

        $this->assertSoftDeleted($analysis);
        $this->assertSame(0, $analysis->video->analyses()->count());
        $this->assertTrue($source->refresh()->videoAnalysis->is($analysis));
    }

    public function test_a_new_version_follows_deleted_versions(): void
    {
        Queue::fake();
        $video = Video::factory()->create();
        VideoAnalysis::factory()->for($video)->completed()->create(['version' => 1])->delete();

        $analysis = app(RequestVideoAnalysis::class)->handle($video, User::factory()->create());

        $this->assertSame(2, $analysis->version);
    }
}
