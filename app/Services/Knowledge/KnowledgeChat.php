<?php

namespace App\Services\Knowledge;

use App\Ai\Agents\KnowledgeAssistant;
use App\Models\AiUsageLog;
use App\Models\KnowledgeNode;
use App\Models\User;
use App\Services\Ai\TextAi;
use App\Services\AiUsage\AiUsageRecorder;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\AgentResponse;

/**
 * 知識への質問。本人の会話だけを続け、回答のおすすめの概念は、本人が見られる確認済みの概念だけを表示する。
 * AI とモデルは文章の分析と同じ（TextAi。TEXT_AI_PROVIDER=openai なら OpenAI）。
 */
class KnowledgeChat
{
    /** 回答のメッセージの meta に残す、根拠に選んだ概念の類似度（id => 0〜1）のキー。 */
    public const SIMILARITIES_META_KEY = 'concept_similarities';

    public function __construct(
        private readonly KnowledgeChatContext $context,
        private readonly AiUsageRecorder $usage,
        private readonly ConversationStore $store,
    ) {}

    /**
     * 質問して、会話の ID を返す。$conversationId が本人の会話でなければ、新しい会話にする。
     *
     * @param  float  $minSimilarity  根拠の概念を意味の近さで選ぶときの類似度の下限
     */
    public function ask(User $user, ?string $conversationId, string $question, ?string $genre = null, float $minSimilarity = KnowledgeChatContext::MIN_SIMILARITY): string
    {
        if ($conversationId !== null && ! $this->belongsTo($user, $conversationId)) {
            $conversationId = null;
        }

        $context = $this->context->build($user, $genre, $question, $minSimilarity);
        $text = TextAi::fromConfig();

        /** @var AgentResponse $response */
        [$response] = $this->usage->within(AiUsageLog::FEATURE_CHAT, null, fn () => $text->models()->attempt(
            fn (?string $model) => (new KnowledgeAssistant($context['text']))
                ->continueOrStart($conversationId, $user)
                ->prompt($question, provider: $text->provider, model: $model),
            purpose: '質問への回答',
            context: ['user_id' => $user->id],
        ));

        $this->rememberSimilarities($response->conversationId, $context['similarities']);

        return $response->conversationId;
    }

    /**
     * 根拠に選んだ概念の類似度を、回答（会話の最新の assistant のメッセージ）の meta に残す。おすすめの概念の横に表示するため。
     *
     * @param  array<int, float>  $similarities
     */
    private function rememberSimilarities(string $conversationId, array $similarities): void
    {
        $message = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->latest('created_at')
            ->latest('id')
            ->first();

        $message?->forceFill(['meta' => [...($message->meta ?? []), self::SIMILARITIES_META_KEY => $similarities]])->save();
    }

    /**
     * 本人の、この相手との最新の会話。
     */
    public function latestConversationId(User $user): ?string
    {
        return $this->store->latestConversationId(Conversation::participantType($user), $user->getKey(), KnowledgeAssistant::class);
    }

    public function belongsTo(User $user, string $conversationId): bool
    {
        return $this->store->conversationBelongsTo($conversationId, Conversation::participantType($user), $user->getKey());
    }

    /**
     * 会話のやりとり。回答はおすすめの概念（本人が見られる確認済みの概念だけ）を付けて返す。
     * おすすめの概念は、根拠に選んだときの類似度の高い順に並べる（類似度のない概念は、AI が返した順で後ろに）。
     *
     * @return list<array{role: string, text: string, concepts: list<array{node: KnowledgeNode, reason: string, similarity: ?float}>}>
     */
    public function messages(User $user, string $conversationId): array
    {
        if (! $this->belongsTo($user, $conversationId)) {
            return [];
        }

        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ConversationMessage $message) => $message->role === 'user'
                ? ['role' => 'user', 'text' => (string) $message->content, 'concepts' => [], 'similarities' => []]
                : ['role' => 'assistant', ...$this->decodeAnswer((string) $message->content), 'similarities' => (array) ($message->meta[self::SIMILARITIES_META_KEY] ?? [])])
            ->filter(fn (array $message) => $message['text'] !== '' || $message['concepts'] !== [])
            ->values();

        $nodes = $this->visibleConcepts($user, $messages->flatMap(fn (array $message) => array_column($message['concepts'], 'id')));

        return $messages
            ->map(fn (array $message) => [
                'role' => $message['role'],
                'text' => $message['text'],
                'concepts' => collect($message['concepts'])
                    ->filter(fn (array $concept) => $nodes->has($concept['id']))
                    ->unique('id')
                    ->map(fn (array $concept) => [
                        'node' => $nodes[$concept['id']],
                        'reason' => $concept['reason'],
                        'similarity' => isset($message['similarities'][$concept['id']]) ? (float) $message['similarities'][$concept['id']] : null,
                    ])
                    ->sortByDesc('similarity')
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * 回答（構造化出力の JSON）を、文章とおすすめの概念に分ける。JSON でなければ、そのまま文章として扱う。
     *
     * @return array{text: string, concepts: list<array{id: int, reason: string}>}
     */
    private function decodeAnswer(string $content): array
    {
        $output = json_decode($content, true);

        if (! is_array($output)) {
            return ['text' => trim($content), 'concepts' => []];
        }

        return [
            'text' => trim((string) ($output['answer'] ?? '')),
            'concepts' => collect($output['concepts'] ?? [])
                ->filter(fn ($concept) => is_array($concept) && is_numeric($concept['id'] ?? null))
                ->map(fn (array $concept) => ['id' => (int) $concept['id'], 'reason' => trim((string) ($concept['reason'] ?? ''))])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, KnowledgeNode>
     */
    private function visibleConcepts(User $user, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return KnowledgeNode::query()
            ->visibleTo($user->id)
            ->where('node_type', KnowledgeNode::TYPE_CONCEPT)
            ->where('status', KnowledgeNode::STATUS_CONFIRMED)
            ->whereIn('id', $ids->unique()->all())
            ->get()
            ->keyBy('id');
    }
}
