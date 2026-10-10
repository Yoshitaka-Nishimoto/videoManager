<?php

namespace App\Services\Script;

/**
 * ナレーションの文字数から場面の長さを決める（scene_content.md：1 秒あたり 6〜7 文字）。
 */
final class NarrationTiming
{
    /** 1 秒あたりに読む文字数。 */
    public const CHARACTERS_PER_SECOND = 6.5;

    /** 読み終えてから次の場面に移るまでの間（秒）。 */
    public const PAUSE_SECONDS = 0.5;

    /** 1 つの場面の最短の長さ（秒）。 */
    public const MIN_SECONDS = 2.0;

    /**
     * ナレーションがあれば、読み上げに必要な長さ（0.5 秒単位で切り上げ）。なければ今の長さのまま。
     */
    public static function seconds(?string $narration, float $current): float
    {
        $characters = mb_strlen(preg_replace('/\s+/u', '', (string) $narration));

        if ($characters === 0) {
            return $current;
        }

        $reading = ceil($characters / self::CHARACTERS_PER_SECOND * 2) / 2;

        return max(self::MIN_SECONDS, $reading + self::PAUSE_SECONDS);
    }

    /**
     * 場面の長さに収まるナレーションの文字数の目安（AI への指示に使う）。
     */
    public static function characterBudget(float $seconds): int
    {
        return max(10, (int) floor(($seconds - self::PAUSE_SECONDS) * self::CHARACTERS_PER_SECOND));
    }
}
