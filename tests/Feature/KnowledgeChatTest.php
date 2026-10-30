<?php

namespace Tests\Feature;

use App\Ai\Agents\KnowledgeAssistant;
use App\Models\AiUsageLog;
use App\Models\KnowledgeNode;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoAnalysis;
use App\Services\Knowledge\KnowledgeChat;
use App\Services\Knowledge\KnowledgeChatContext;
use App\Services\Knowledge\KnowledgeEmbedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgeChatTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.conversations.generate_title' => false]);
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    public function test_the_question_box_is_shown_only_on_the_confirmed_tab(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('knowledge.index', ['status' => KnowledgeNode::STATUS_CONFIRMED]))
            ->assertSee('知識に質問する')
            // 考え中の表示は、質問を送っている間だけ Livewire が表示する（wire:loading）。
            ->assertSee('wire:loading.inline-flex', false)
            ->assertSee('wire:target="ask"', false)
            ->assertSee('AI が回答を考えています…');
        $this->get(route('knowledge.index'))->assertDontSee('知識に質問する');
    }

    public function test_the_answer_recommends_only_confirmed_concepts_the_user_can_see(): void
    {
        $shared = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => 'アルブミン値と栄養状態', 'description' => '栄養状態の目安。']);
        $mine = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => 'ALP高値の確認', 'owner_id' => $this->owner->id]);
        $others = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => '他人の個人の概念', 'owner_id' => $this->other->id]);
        $candidate = KnowledgeNode::factory()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => 'まだ候補の概念']);

        $file = Video::factory()->personalFile($this->owner)->create(['title' => 'resultBlood4.csv']);
        VideoAnalysis::factory()->completed()->for($file)->create([
            'summary' => 'アルブミンが下限を下回っている。',
            'content' => ['key_points' => [['point' => 'アルブミンが低い', 'quote' => 'ALB(アルブミン) | 3.9 L | 5.1 | 4.1']]],
            'source_text' => "検査項目 | 結果\nALB(アルブミン) | 3.9 L | 5.1 | 4.1",
        ]);
        $othersFile = Video::factory()->personalFile($this->other, 'resources/other/blood.csv')->create(['title' => 'other.csv']);
        VideoAnalysis::factory()->completed()->for($othersFile)->create(['summary' => '他人の検査結果の要約。']);

        KnowledgeAssistant::fake([[
            'answer' => 'アルブミンが下限値を下回っているので、栄養状態の概念が参考になります。',
            'concepts' => [
                ['id' => $shared->id, 'reason' => 'アルブミン 3.9 と関係する'],
                ['id' => $mine->id, 'reason' => 'ALP の確認'],
                ['id' => $others->id, 'reason' => '見えてはいけない'],
                ['id' => $candidate->id, 'reason' => '候補は出さない'],
                ['id' => 999999, 'reason' => '存在しない'],
            ],
        ]]);

        $this->actingAs($this->owner);
        Livewire::test('knowledge.chat')
            ->set('question', '血液検査の内容から、おすすめの概念を教えてください')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSee('血液検査の内容から、おすすめの概念を教えてください')
            ->assertSee('栄養状態の概念が参考になります。')
            ->assertSee('アルブミン値と栄養状態')
            ->assertSee('アルブミン 3.9 と関係する')
            ->assertSee('ALP高値の確認')
            ->assertDontSee('他人の個人の概念')
            ->assertDontSee('まだ候補の概念')
            ->assertDontSee('存在しない');

        KnowledgeAssistant::assertPrompted(function (AgentPrompt $prompt) {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'アルブミン値と栄養状態')
                && str_contains($instructions, 'ALB(アルブミン) | 3.9 L | 5.1 | 4.1')
                && ! str_contains($instructions, '他人の個人の概念')
                && ! str_contains($instructions, '他人の検査結果の要約。')
                && ! str_contains($instructions, 'まだ候補の概念')
                && ! str_contains($instructions, $this->owner->name);
        });

        // 会話は本人のものとして残り、開き直すと続きから表示される。
        $this->assertSame(2, ConversationMessage::where('participant_id', $this->owner->id)->count());
        $this->assertSame(AiUsageLog::FEATURE_CHAT, AiUsageLog::sole()->feature);
        Livewire::test('knowledge.chat')->assertSee('栄養状態の概念が参考になります。');

        // 他の利用者には、本人の会話は出ない。
        $this->actingAs($this->other);
        Livewire::test('knowledge.chat')
            ->assertSet('conversationId', null)
            ->assertDontSee('栄養状態の概念が参考になります。');
    }

    public function test_concepts_close_to_the_question_and_the_personal_data_are_chosen_with_pgvector(): void
    {
        config(['services.knowledge_embeddings.enabled' => true]);
        Queue::fake();
        // 「鼻」を含む文は軸 0、「アルブミン」を含む文は軸 1、それ以外は軸 2 を向く偽の埋め込み。
        Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn (string $text) => $this->vector(match (true) {
            str_contains($text, '鼻') => 0,
            str_contains($text, 'アルブミン') => 1,
            default => 2,
        }), $prompt->inputs));

        $nose = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => '鼻呼吸の習慣']);
        $albumin = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => 'アルブミン値と栄養状態']);
        $unrelated = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => 'NISAを活用した負担回避策']);
        app(KnowledgeEmbedder::class)->embed(KnowledgeNode::all());
        $fresh = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => '埋め込みのまだない概念']);

        $file = Video::factory()->personalFile($this->owner)->create(['title' => 'resultBlood4.csv']);
        VideoAnalysis::factory()->completed()->for($file)->create(['summary' => 'アルブミンが下限を下回っている。']);

        $titles = app(KnowledgeChatContext::class)->concepts($this->owner, null, ['鼻詰まりに効くこと', 'アルブミンが下限を下回っている。'])->pluck('title')->all();

        $this->assertContains($nose->title, $titles);
        $this->assertContains($albumin->title, $titles);
        $this->assertContains($fresh->title, $titles);
        $this->assertNotContains($unrelated->title, $titles);

        // 質問すると、質問文と個人のデータの両方から選ばれる。
        KnowledgeAssistant::fake([['answer' => '回答。', 'concepts' => []]]);
        $this->actingAs($this->owner);
        Livewire::test('knowledge.chat')->set('question', '鼻詰まりに効くことは？')->call('ask')->assertHasNoErrors();

        KnowledgeAssistant::assertPrompted(fn (AgentPrompt $prompt) => str_contains((string) $prompt->agent->instructions(), '鼻呼吸の習慣')
            && str_contains((string) $prompt->agent->instructions(), 'アルブミン値と栄養状態')
            && ! str_contains((string) $prompt->agent->instructions(), 'NISAを活用した負担回避策'));
    }

    public function test_the_minimum_similarity_can_be_set_from_the_question_box(): void
    {
        config(['services.knowledge_embeddings.enabled' => true]);
        Queue::fake();
        // 「鼻」を含む文は軸 0、「睡眠」を含む文は軸 0 と類似度 0.65 の向き、それ以外は軸 2 を向く偽の埋め込み。
        Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn (string $text) => match (true) {
            str_contains($text, '鼻') => $this->vector(0),
            str_contains($text, '睡眠') => $this->mixed(0.65),
            default => $this->vector(2),
        }, $prompt->inputs));

        $sleep = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => '睡眠の質の改善']);
        $nose = KnowledgeNode::factory()->confirmed()->create(['node_type' => KnowledgeNode::TYPE_CONCEPT, 'title' => '鼻呼吸の習慣']);
        app(KnowledgeEmbedder::class)->embed(KnowledgeNode::all());

        KnowledgeAssistant::fake([
            ['answer' => '回答 1。', 'concepts' => []],
            // AI は類似度の低い順に返すが、画面では高い順に並べる。
            ['answer' => '回答 2。', 'concepts' => [['id' => $sleep->id, 'reason' => '睡眠の理由'], ['id' => $nose->id, 'reason' => '鼻の理由']]],
        ]);
        $this->actingAs($this->owner);

        // 既定（0.7）では、類似度 0.65 の概念は渡さない。
        Livewire::test('knowledge.chat')
            ->assertSet('minSimilarity', 0.7)
            ->assertSee('類似度')
            ->set('question', '鼻詰まりで目が覚めます')
            ->call('ask')
            ->assertHasNoErrors();
        KnowledgeAssistant::assertPrompted(fn (AgentPrompt $prompt) => ! str_contains((string) $prompt->agent->instructions(), '睡眠の質の改善'));

        // 0.6 にすると渡す。おすすめの概念の横に、根拠に選んだときの類似度を高い順に出す。
        $html = Livewire::test('knowledge.chat')
            ->call('newConversation')
            ->set('minSimilarity', 0.6)
            ->set('question', '鼻詰まりで目が覚めます')
            ->call('ask')
            ->assertHasNoErrors()
            ->html();
        $this->assertMatchesRegularExpression('/鼻呼吸の習慣.*:1\.00.*鼻の理由.*睡眠の質の改善.*:0\.65.*睡眠の理由/su', $html);
        KnowledgeAssistant::assertPrompted(fn (AgentPrompt $prompt) => str_contains((string) $prompt->agent->instructions(), '睡眠の質の改善'));

        // 0.6 未満・1 超は受け付けない（入力欄を空にしたときは、既定の 0.7 のまま）。
        foreach ([0.5, 1.1] as $value) {
            Livewire::test('knowledge.chat')
                ->set('minSimilarity', $value)
                ->set('question', '質問')
                ->call('ask')
                ->assertHasErrors('minSimilarity');
        }
    }

    public function test_a_conversation_of_another_user_is_not_continued(): void
    {
        KnowledgeAssistant::fake([
            ['answer' => '本人への回答。', 'concepts' => []],
            ['answer' => '他の利用者への回答。', 'concepts' => []],
        ]);

        $this->actingAs($this->owner);
        $conversationId = Livewire::test('knowledge.chat')->set('question', '質問')->call('ask')->get('conversationId');

        $this->actingAs($this->other);
        $chat = app(KnowledgeChat::class);
        $this->assertSame([], $chat->messages($this->other, $conversationId));

        $newId = $chat->ask($this->other, $conversationId, '別の質問');
        $this->assertNotSame($conversationId, $newId);
        $this->assertSame(2, ConversationMessage::where('conversation_id', $conversationId)->count());
    }

    /**
     * 軸 $axis の方向を向いた 1536 次元のベクトル。
     *
     * @return list<float>
     */
    private function vector(int $axis): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[$axis] = 1.0;

        return $vector;
    }

    /**
     * 軸 0 の方向との類似度（コサイン）が $similarity になる 1536 次元の単位ベクトル。
     *
     * @return list<float>
     */
    private function mixed(float $similarity): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = $similarity;
        $vector[1] = sqrt(1 - $similarity ** 2);

        return $vector;
    }
}
