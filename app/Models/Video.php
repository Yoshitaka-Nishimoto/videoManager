<?php

namespace App\Models;

use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'source_type',
    'youtube_id',
    'url',
    'storage_path',
    'title',
    'channel_title',
    'description',
    'genre',
    'summary',
    'duration_seconds',
    'published_at',
    'thumbnail_url',
    'view_count',
    'like_count',
    'comment_count',
    'statistics_fetched_at',
    'registered_by',
])]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory, SoftDeletes;

    public const SOURCE_YOUTUBE = 'youtube';

    /** 公式サイト・GitHub などの Web ページ。本文を取得して分析する。 */
    public const SOURCE_WEB = 'web';

    public const SOURCE_RUNWAY = 'runway';

    public const SOURCE_REMOTION = 'remotion';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'published_at' => 'datetime',
            'view_count' => 'integer',
            'like_count' => 'integer',
            'comment_count' => 'integer',
            'statistics_fetched_at' => 'datetime',
        ];
    }

    /**
     * ジャンルの表示名（選択肢は VideoProduction::GENRES）。未設定なら空。
     */
    public function genreLabel(): ?string
    {
        return $this->genre === null ? null : (VideoProduction::GENRES[$this->genre] ?? $this->genre);
    }

    /**
     * 再生回数などの数を「1.2万」「345万」「1.1億」の形で表す。取得できていなければ「—」。
     */
    public static function formatCount(?int $count): string
    {
        return match (true) {
            $count === null => '—',
            $count >= 100_000_000 => self::trimDecimal($count / 100_000_000).'億',
            $count >= 10_000 => self::trimDecimal($count / 10_000).'万',
            default => number_format($count),
        };
    }

    private static function trimDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, $value < 10 ? 1 : 0, '.', ''), '0'), '.');
    }

    /**
     * @return HasMany<VideoAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(VideoAnalysis::class);
    }

    /**
     * @return HasOne<VideoAnalysis, $this>
     */
    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(VideoAnalysis::class)->latestOfMany('version');
    }

    public function isWebPage(): bool
    {
        return $this->source_type === self::SOURCE_WEB;
    }

    /**
     * 情報源の種類の表示名。
     */
    public function sourceLabel(): string
    {
        return $this->isWebPage() ? 'Web' : '動画';
    }

    /**
     * Web ページの引用箇所へ移動する URL（ブラウザのテキストフラグメント）。引用がなければページの URL。
     */
    public function urlForQuote(?string $quote): ?string
    {
        if ($this->url === null || blank($quote)) {
            return $this->url;
        }

        // 長い引用は一致しにくいので、先頭の一部だけで探す。
        $text = Str::limit(preg_replace('/\s+/u', ' ', trim($quote)), 60, '');

        return Str::before($this->url, '#').'#:~:text='.rawurlencode($text);
    }

    /**
     * 動画の指定時刻から再生する URL。
     */
    public function urlAt(?int $seconds): ?string
    {
        if ($this->youtube_id === null) {
            return $this->url;
        }

        return 'https://www.youtube.com/watch?v='.$this->youtube_id.($seconds ? '&t='.$seconds.'s' : '');
    }

    /**
     * 秒数を「1:02:03」「2:03」の形で表す。
     */
    public static function formatSeconds(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
