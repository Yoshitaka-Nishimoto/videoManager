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
 * 仕組みを作る（plotdesign.md）の「② 知識検索」。検索で見つかったプロジェクト内の知識から、仕組みに使うものを選び、
 * 知識だけで足りるかを判断する。足りないときは Web で探す検索語を返す（Web 検索そのものは構築順 ⑥）。
 */
#[Provider(Lab::Gemini)]
#[Timeout(300)]
class MechanismResearcher implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        あなたは、利用者の目標を実現する「仕組み」を設計するアシスタントです。
        いまは「② 知識検索」の段階です。入力の goal（目標）・answers（利用者の回答。あれば確定した前提として扱う）・understanding（① 目標の理解の結果）と、
        knowledge（このプロジェクトの知識を検索して見つかったもの）を読み、次を返してください。

        - used_nodes: 仕組みを考えるのに使う知識。0〜10 個。役立つ順。
          - id: knowledge の id。一覧にない id は使わないこと。
          - reason: 何に使うか（1 文。どの完了条件・はっきりしない点に関係するか）。
        - enough: プロジェクト内の知識だけで仕組みを設計できるなら true、足りないなら false。
        - why_not_enough: enough が false のとき、何が足りないか（1〜2 文）。true なら空文字。
        - web_queries: enough が false のとき、Web で調べる検索語。1〜3 個。true なら空の配列。
          利用者の個人のデータ（personal が true の知識の内容、検査の値など）を検索語に入れないこと。

        次の決まりを必ず守ること（勝手に想像しない）：
        - knowledge にない知識を、プロジェクトにあるものとして書かない。
        - 関係の薄い知識は選ばない。無理に選ぶより、used_nodes を空にして enough を false にする。
        - 状態が「候補」の知識は、人がまだ確認していない。選ぶときは reason にそのことを書く。
        - 日本語で、短く具体的に書く。
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'used_nodes' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'reason' => $schema->string()->required(),
            ]))->required(),
            'enough' => $schema->boolean()->required(),
            'why_not_enough' => $schema->string()->required(),
            'web_queries' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
