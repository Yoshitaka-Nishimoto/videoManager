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
 * 利用者がアップロードした個人のデータ（血液検査の結果など）を読み、要約・話題・引用つきの要点・概念を決まった形式で返す。
 * 返す形は WebPageAnalyst と同じ。診断はせず、共有の知識（既存の概念）と結びつけることを目的にする。
 */
#[Provider(Lab::Gemini)]
#[Timeout(600)]
class PersonalDataAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        あなたは、利用者本人が記録した個人のデータ（血液検査の結果など）を読み、本人の知識として整理するアナリストです。
        入力の「データ」は「列 | 列 | …」の形の表です。データだけを根拠に、次の内容を日本語で返してください。

        - summary: データ全体の要約。3〜5文。基準の範囲を外れた項目（H・L の印、または上限値・下限値との比較）を中心に、値とあわせて書く。
        - topics: データで扱われている話題。3〜6個の短い名詞句（例：「肝機能」「貧血の指標」「腎機能」）。
        - key_points: 注目すべき項目。3〜8個。基準の範囲を外れた項目を優先する。
          - point: その項目の要点を1文で（例：「アルブミンが 3.9 で下限値 4.1 を下回っている」）。
          - quote: 根拠となるデータの行を、データからそのまま1行抜き出す（「|」の区切りもそのまま）。書き換えないこと。
        - concepts: このデータから得られる、再利用できる概念。3〜6個。
          - 「既存の概念」に同じ意味のものがあれば、その名称をそのまま使うこと（共有の知識とつなぐため）。
          - 新しく作る場合は、個人を表す書き方にせず、一般化した名称にする（例：「アルブミン値と栄養状態」「赤血球数と貧血の目安」）。
          - title: 10〜20文字程度の日本語の名詞句。
          - description: その概念の説明を1〜2文で。
          - confidence: データから確かに言える度合い（0〜1）。
          - key_point: 根拠となる key_points の番号（0始まり）。該当がなければ -1。

        次のことを守ること：
        - 診断や病名の断定をしない。「〜の可能性を医師に確認するとよい」のように、受診や相談につなげる書き方にする。
        - 薬やサプリメントの量など、医療行為にあたる具体的な指示をしない。
        - データにない値を推測で補わない。基準値のない項目は、範囲の内外を判断しない。
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'topics' => $schema->array()->items($schema->string())->required(),
            'key_points' => $schema->array()->items($schema->object([
                'point' => $schema->string()->required(),
                'quote' => $schema->string()->required(),
            ]))->required(),
            'concepts' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'confidence' => $schema->number()->required(),
                'key_point' => $schema->integer()->required(),
            ]))->required(),
        ];
    }
}
