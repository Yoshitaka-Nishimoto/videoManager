<?php

namespace App\Services\YouTube;

/**
 * YouTube の URL（または動画 ID そのもの）から 11 文字の動画 ID を取り出す。
 */
final class YouTubeVideoId
{
    private const ID = '[A-Za-z0-9_-]{11}';

    public static function parse(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('/^'.self::ID.'$/', $input)) {
            return $input;
        }

        $parts = parse_url($input);
        $host = strtolower(preg_replace('/^(www\.|m\.|music\.)/', '', $parts['host'] ?? ''));
        $path = $parts['path'] ?? '';

        if ($host === 'youtu.be') {
            return self::match(ltrim($path, '/'));
        }

        if (! in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            return null;
        }

        if ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);

            return is_string($query['v'] ?? null) ? self::match($query['v']) : null;
        }

        if (preg_match('#^/(shorts|embed|live|v)/([^/?]+)#', $path, $matches)) {
            return self::match($matches[2]);
        }

        return null;
    }

    private static function match(string $candidate): ?string
    {
        return preg_match('/^'.self::ID.'$/', $candidate) ? $candidate : null;
    }
}
