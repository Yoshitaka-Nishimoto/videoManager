<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class AgentConversationSeeder extends Seeder
{
    /**
     * The agent class recorded on dummy messages (to be implemented later).
     */
    private const AGENT = 'App\\Ai\\Agents\\VideoAnalyst';

    private const MODEL = 'claude-sonnet-5-5';

    private const QUESTIONS = [
        'この動画の要点を3つにまとめてください。',
        '「:concept」について、動画のどこで説明されていますか？',
        '「:concept」をVideoManagerに実装するなら、何から始めればよいですか？',
        'この動画の内容で、Runwayの動画生成に使える点はありますか？',
        '前回の分析と比べて、変わった点を教えてください。',
        '「:concept」と「:other」は同じ概念として扱えますか？',
    ];

    /**
     * Seed conversations about the first ten analysed videos for the first user.
     */
    public function run(): void
    {
        $user = User::query()->firstOr(fn () => User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]));

        Video::query()
            ->whereHas('analyses', fn ($query) => $query->where('status', VideoAnalysis::STATUS_COMPLETED))
            ->with(['analyses' => fn ($query) => $query->where('status', VideoAnalysis::STATUS_COMPLETED)->latest('version')])
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->each(function (Video $video) use ($user) {
                $title = "「{$video->title}」について";

                if (Conversation::query()->where('title', $title)->exists()) {
                    return;
                }

                $this->seedConversation($video, $video->analyses->first(), $title, $user);
            });
    }

    private function seedConversation(Video $video, VideoAnalysis $analysis, string $title, User $user): void
    {
        $startedAt = Carbon::instance($analysis->analyzed_at)->addHours(fake()->numberBetween(1, 48));
        $participant = [
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
        ];

        $conversation = Conversation::query()->create([
            'id' => (string) Str::uuid7($startedAt),
            'title' => $title,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
            ...$participant,
        ]);

        $concepts = $analysis->content['concepts'] ?? ['分析の版管理', '重複概念の統合'];
        $keyPoints = $analysis->content['key_points'] ?? [];
        $sentAt = $startedAt->copy();
        $turns = fake()->numberBetween(1, 3);

        foreach (fake()->randomElements(self::QUESTIONS, $turns) as $turn => $question) {
            $isLastTurn = $turn === $turns - 1;
            $failed = $isLastTurn && fake()->boolean(10);

            [$concept, $other] = fake()->randomElements($concepts, 2);

            $sentAt = $sentAt->copy()->addMinutes(fake()->numberBetween(1, 15));
            $this->storeMessage($conversation, $participant, $sentAt, [
                'role' => 'user',
                'content' => strtr($question, [':concept' => $concept, ':other' => $other]),
                'usage' => [],
                'meta' => [],
                'status' => MessageStatus::Completed,
            ]);

            $sentAt = $sentAt->copy()->addSeconds(fake()->numberBetween(5, 40));
            $this->storeMessage($conversation, $participant, $sentAt, [
                'role' => 'assistant',
                'content' => $failed ? '' : $this->answer($analysis, $keyPoints),
                'usage' => [
                    'input_tokens' => fake()->numberBetween(800, 6000),
                    'output_tokens' => $failed ? 0 : fake()->numberBetween(150, 1200),
                ],
                'meta' => [
                    'provider' => 'anthropic',
                    'model' => self::MODEL,
                    'citations' => [],
                    ...($failed ? ['error' => 'APIの応答がタイムアウトしました。'] : []),
                ],
                'status' => $failed ? MessageStatus::Failed : MessageStatus::Completed,
            ]);
        }

        $conversation->update(['updated_at' => $sentAt]);
    }

    /**
     * @param  array<string, mixed>  $participant
     * @param  array<string, mixed>  $attributes
     */
    private function storeMessage(Conversation $conversation, array $participant, Carbon $sentAt, array $attributes): void
    {
        ConversationMessage::query()->create([
            'id' => (string) Str::uuid7($sentAt),
            'conversation_id' => $conversation->id,
            'agent' => self::AGENT,
            'attachments' => [],
            'steps' => [],
            'created_at' => $sentAt,
            'updated_at' => $sentAt,
            ...$participant,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $keyPoints
     */
    private function answer(VideoAnalysis $analysis, array $keyPoints): string
    {
        $lines = ["第{$analysis->version}版の分析によると、次の箇所が関係します。"];

        foreach (array_slice($keyPoints, 0, 3) as $point) {
            $lines[] = sprintf('- %s〜%s：%s', $this->clock($point['start_seconds']), $this->clock($point['end_seconds']), $point['point']);
        }

        $lines[] = 'この内容を知識ノードの候補として登録できます。';

        return implode("\n", $lines);
    }

    private function clock(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
