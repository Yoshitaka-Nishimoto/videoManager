<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * 制作案の場面の並びと制作の意図から、解説動画の台本（ナレーション・字幕・画面の文字）の候補を 3 案作る。
 *
 * 構造化出力で anyOf を使わないよう、場面ごとの項目は 1 つのオブジェクトにまとめる（scene_content.md）。
 */
#[Provider(Lab::Gemini)]
#[Timeout(300)]
class ScriptWriterAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        あなたは短い解説動画の台本を書く構成作家です。
        入力（JSON）には、動画のタイトル・ジャンル・対象者・口調・伝えたいこと・元になった設計判断と、
        場面の並び（key、場面の種類、場面の名前、秒数、ナレーションの文字数の目安）があります。
        場面の並び・種類・数は変えずに、日本語の台本を 3 案作ってください。

        - candidates: 3 案。それぞれ切り口を変える（例：結論から話す／問いかけから入る／具体例から入る）。
          - label: 案の名前（10 文字程度）。
          - summary: 案の概要（1〜2 文）。
          - scenes: 入力の場面と同じ key を、同じ順番ですべて書く。
            - narration: その場面で読み上げる文。文字数は入力の narration_characters 以内。対象者と口調に合わせる。
            - caption: 画面の下に出す字幕。ナレーションの要点を 40 文字以内で。
            - heading: 画面に大きく出す見出し（30 文字以内）。remotion.title / remotion.text の場面で使う。
            - subheading: 見出しの下の副題（40 文字以内、なければ空）。remotion.title の場面で使う。
            - items: 箇条書き（1〜6 個、各 40 文字以内）。remotion.text の場面で使う。他の場面では空。
            - labels: 図の箱の名前（各 15 文字以内）。remotion.diagram の場面で、入力の diagram_boxes と同じ数。他の場面では空。

        守ること：
        - 伝えたいこと・設計判断に書かれていない事実を作らない。
        - 数値・割合・統計を作らない（グラフの数値は人が入れる）。remotion.chart の場面では数値に触れずに説明する。
        - 最後の場面は、全体のまとめにする。
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'candidates' => $schema->array()->items($schema->object([
                'label' => $schema->string()->required(),
                'summary' => $schema->string()->required(),
                'scenes' => $schema->array()->items($schema->object([
                    'key' => $schema->string()->required(),
                    'narration' => $schema->string()->required(),
                    'caption' => $schema->string()->required(),
                    'heading' => $schema->string()->required(),
                    'subheading' => $schema->string()->required(),
                    'items' => $schema->array()->items($schema->string())->required(),
                    'labels' => $schema->array()->items($schema->string())->required(),
                ]))->required(),
            ]))->required(),
        ];
    }
}
