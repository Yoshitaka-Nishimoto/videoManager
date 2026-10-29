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
 * 公式サイト・GitHub などの Web ページの本文を読み、要約・話題・引用つきの要点・概念を決まった形式で返す。
 * 本文はこちらで取得してプロンプトに入れる（何を読んで分析したかを残すため）。
 */
#[Provider(Lab::Gemini)]
#[Timeout(600)]
class WebPageAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        あなたは Web ページ（公式サイト、ドキュメント、GitHub の README など）の内容を正確に読み取り、知識として整理するアナリストです。
        入力の「本文」だけを根拠に、次の内容を日本語で返してください。

        - summary: ページ全体の要約。3〜5文。本文に書かれている内容だけを書く。
        - topics: ページで扱われている話題。3〜6個の短い名詞句。
        - key_points: 重要な箇所。3〜8個。
          - point: その箇所の要点を1文で（日本語）。
          - quote: 根拠となる本文の一節を、本文からそのまま（原文の言語のまま、1文程度、200文字以内で）抜き出す。言い換えたり訳したりしないこと。
        - concepts: ページから得られる、再利用できる概念・手法・考え方。3〜6個。
          技術のページなら設計上の考え方や使い方、健康のページなら習慣や指標、投資のページなら制度・指標・リスクの考え方などを取り出す。
          - title: 10〜20文字程度の日本語の名詞句（例：「入力画像の品質確認」「長期分散投資」）。
          - description: その概念の説明を1〜2文で。
          - confidence: 本文から確かに言える度合い（0〜1）。
          - key_point: 根拠となる key_points の番号（0始まり）。該当がなければ -1。
          - 「既存の概念」に同じ意味のものがあれば、その名称をそのまま使うこと。

        投資のページでは、次のことも守ること：
        - 利回り・価格・金利などの数値は、本文で述べられた時点や前提とあわせて書く。
        - 筆者の意見・予想と、制度や仕組みなどの事実を区別して書く（例：「筆者は〜と予想している」）。
        - 本文でリスクや注意点が述べられていれば、key_points か concepts に含める。
        - 特定の銘柄の売買を勧める書き方をしない。

        本文にない内容を推測で補わないこと。本文が空、またはログイン画面・エラー画面などで内容がない場合は、summary にその旨を書き、他は空にすること。
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
