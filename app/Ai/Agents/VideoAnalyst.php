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
 * 添付された YouTube 動画を見て、要約・話題・時刻つきの要点・概念を決まった形式で返す。
 */
#[Provider(Lab::Gemini)]
#[Timeout(600)]
class VideoAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        あなたは動画の内容を正確に読み取り、知識として整理するアナリストです。
        添付された動画の映像と音声を実際に確認し、次の内容を日本語で返してください。

        - summary: 動画全体の要約。3〜5文。動画で実際に述べられている内容だけを書く。
        - topics: 動画で扱われている話題。3〜6個の短い名詞句。
        - key_points: 重要な場面。3〜8個。start_seconds と end_seconds は動画の該当時刻（秒）で、動画の長さを超えないこと。point はその場面の要点を1文で。
        - concepts: 動画から得られる、再利用できる概念・手法・考え方。3〜6個。
          技術の動画なら設計上の考え方、健康の動画なら習慣や指標、投資の動画なら投資手法・指標・リスクの考え方などを取り出す。
          - title: 10〜20文字程度の名詞句（例：「入力画像の品質確認」「長期分散投資」）。
          - description: その概念の説明を1〜2文で。
          - confidence: 動画の内容から確かに言える度合い（0〜1）。
          - key_point: 根拠となる key_points の番号（0始まり）。該当がなければ -1。
          - 「既存の概念」に同じ意味のものがあれば、その名称をそのまま使うこと。

        投資の動画では、次のことも守ること：
        - 利回り・価格・金利などの数値は、動画で述べられた時点や前提とあわせて書く。
        - 話し手の意見・予想と、制度や仕組みなどの事実を区別して書く（例：「話し手は〜と予想している」）。
        - 動画でリスクや注意点が述べられていれば、key_points か concepts に含める。
        - 特定の銘柄の売買を勧める書き方をしない。

        推測で内容を補わないこと。動画を確認できない場合は、summary にその旨を書き、他は空にすること。
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'topics' => $schema->array()->items($schema->string())->required(),
            'key_points' => $schema->array()->items($schema->object([
                'start_seconds' => $schema->integer()->required(),
                'end_seconds' => $schema->integer()->required(),
                'point' => $schema->string()->required(),
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
