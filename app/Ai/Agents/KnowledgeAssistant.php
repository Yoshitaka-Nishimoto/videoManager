<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * 知識一覧の「確認済み」から、利用者の質問に答える。確認済みの概念と本人の個人のデータ（KnowledgeChatContext）だけを根拠に、
 * 回答と、おすすめの概念（id と理由）を返す。会話は agent_conversations に本人の会話として残す（RemembersConversations）。
 *
 * 根拠の一覧は会話の履歴に入れず、指示に毎回入れる（履歴が一覧の写しで膨らまないように、また最新の確認済みを使うため）。
 */
#[Provider(Lab::Gemini)]
#[Timeout(300)]
class KnowledgeAssistant implements Agent, Conversational, HasStructuredOutput
{
    use Promptable, RemembersConversations;

    /**
     * @param  string  $context  確認済みの概念と本人の個人のデータ（KnowledgeChatContext::build）
     */
    public function __construct(private readonly string $context) {}

    public function instructions(): Stringable|string
    {
        return <<<TXT
        あなたは、利用者が確認した知識（概念）を案内するアシスタントです。
        下の「確認済みの概念」と「本人の個人のデータ」だけを根拠に、利用者の質問に日本語で答えてください。

        - answer: 質問への回答。2〜6文。個人のデータに触れるときは、どの項目（値）にもとづくかを書く。
        - concepts: 質問に関係する、おすすめの概念。0〜6個。関係の強い順。
          - id: 「確認済みの概念」の id。一覧にない id は使わないこと。
          - reason: その概念をすすめる理由を1文で（個人のデータのどの項目と関係するか、など）。

        次のことを守ること：
        - 一覧にない知識を作らない。関係する概念がなければ、concepts を空にして、answer でそう伝える。
        - 診断や病名の断定をしない。「〜の可能性を医師に確認するとよい」のように、受診や相談につなげる書き方にする。
        - 薬やサプリメントの量など、医療行為にあたる具体的な指示をしない。

        {$this->context}
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'answer' => $schema->string()->required(),
            'concepts' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required(),
                'reason' => $schema->string()->required(),
            ]))->required(),
        ];
    }

    /**
     * 履歴は直近の 20 件だけ渡す（根拠の一覧は毎回の指示に入っているため）。
     */
    protected function maxConversationMessages(): int
    {
        return 20;
    }
}
