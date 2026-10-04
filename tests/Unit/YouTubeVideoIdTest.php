<?php

namespace Tests\Unit;

use App\Services\YouTube\YouTubeVideoId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YouTubeVideoIdTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function urls(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch with extra params' => ['https://youtube.com/watch?list=PL1&v=dQw4w9WgXcQ&t=42s', 'dQw4w9WgXcQ'],
            'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short link' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'live' => ['https://www.youtube.com/live/dQw4w9WgXcQ?feature=share', 'dQw4w9WgXcQ'],
            'bare id' => ['  dQw4w9WgXcQ ', 'dQw4w9WgXcQ'],
            'other site' => ['https://example.com/watch?v=dQw4w9WgXcQ', null],
            'look-alike host' => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ', null],
            'channel page' => ['https://www.youtube.com/@channel', null],
            'id too short' => ['https://youtu.be/abc', null],
            'not a url' => ['動画のURL', null],
        ];
    }

    #[DataProvider('urls')]
    public function test_it_extracts_the_video_id(string $input, ?string $expected): void
    {
        $this->assertSame($expected, YouTubeVideoId::parse($input));
    }
}
