<?php

namespace App\Services\Runway;

use InvalidArgumentException;

/**
 * 人物のサンプル画像から画像を作るときの指示文（英語）を、写り方・背景・絵柄の選択肢から組み立てる。
 *
 * Runway は英語の指示の方が精度が高いため、日本語の選択肢も英語の文に置き換える。
 * 参照画像には TAG の名前を付け、指示文の中で @person として呼ぶ。
 */
class PersonImagePrompt
{
    public const TAG = 'person';

    /** Runway の指示文の上限（文字数）。 */
    public const MAX_LENGTH = 1000;

    public const SHOTS = [
        'face' => 'a close-up portrait of the head and shoulders',
        'upper' => 'an upper-body shot from the waist up',
        'full' => 'a full-body shot from head to toe, with the whole figure visible',
    ];

    public const BACKGROUNDS = [
        'blue' => 'a plain solid chroma-key blue background (blue screen), evenly lit, without shadows',
        'green' => 'a plain solid chroma-key green background (green screen), evenly lit, without shadows',
        'white' => 'a plain seamless white studio background',
        'park' => 'a sunny city park with green trees and a walking path',
        'office' => 'a bright modern office',
        'studio' => 'a softly lit photo studio with a gray backdrop',
    ];

    public const STYLES = [
        'photo' => 'a photorealistic photograph with natural lighting',
        'manga' => 'a Japanese manga style illustration with clean black ink line art, cel shading and screentone',
        'anime' => 'a Japanese anime style illustration with vibrant colors and cel shading',
        'illustration' => 'a soft digital illustration with pastel colors',
    ];

    /** 日本語でも指定できるようにする。 */
    private const ALIASES = [
        '顔' => 'face', 'バストアップ' => 'face', '上半身' => 'upper', '全身' => 'full',
        'ブルーバック' => 'blue', 'グリーンバック' => 'green', '白' => 'white', '白背景' => 'white',
        '公園' => 'park', 'オフィス' => 'office', 'スタジオ' => 'studio',
        '写真' => 'photo', '漫画' => 'manga', 'マンガ' => 'manga', 'アニメ' => 'anime', 'イラスト' => 'illustration',
    ];

    /**
     * @param  string  $background  BACKGROUNDS のキー、または背景を説明する自由な文（英語がよい）
     */
    public static function build(string $shot, string $background, string $style, ?string $extra = null): string
    {
        $shotText = self::SHOTS[self::normalize($shot)] ?? throw new InvalidArgumentException(
            "写り方「{$shot}」は使えません（".implode(' / ', array_keys(self::SHOTS)).' / 顔 / 上半身 / 全身）。'
        );
        $styleText = self::STYLES[self::normalize($style)] ?? throw new InvalidArgumentException(
            "絵柄「{$style}」は使えません（".implode(' / ', array_keys(self::STYLES)).' / 写真 / 漫画 / アニメ / イラスト）。'
        );
        $backgroundText = self::BACKGROUNDS[self::normalize($background)] ?? trim($background);

        if ($backgroundText === '') {
            throw new InvalidArgumentException('背景を指定してください。');
        }

        $tag = '@'.self::TAG;
        $prompt = "{$styleText} of {$tag}: {$shotText}, facing the viewer. "
            ."Keep {$tag}'s face, hairstyle and features exactly the same. "
            ."Background: {$backgroundText}. No text, no logos.";

        if (filled($extra)) {
            $prompt .= ' '.trim($extra);
        }

        if (mb_strlen($prompt) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('指示文が長すぎます（'.mb_strlen($prompt).' 文字。上限は '.self::MAX_LENGTH.' 文字）。追加の指示を短くしてください。');
        }

        return ucfirst($prompt);
    }

    /**
     * 選択肢を英語のキーにそろえる（日本語の別名も受け付ける）。
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);

        return self::ALIASES[$value] ?? strtolower($value);
    }
}
