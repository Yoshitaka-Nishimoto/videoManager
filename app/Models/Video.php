<?php

namespace App\Models;

use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source_type',
    'youtube_id',
    'url',
    'storage_path',
    'title',
    'channel_title',
    'description',
    'summary',
    'duration_seconds',
    'published_at',
    'thumbnail_url',
    'registered_by',
])]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    public const SOURCE_YOUTUBE = 'youtube';

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
        ];
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
