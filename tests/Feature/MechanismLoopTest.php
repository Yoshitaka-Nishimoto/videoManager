<?php

namespace Tests\Feature;

use App\Actions\Mechanisms\CreateMechanism;
use App\Ai\Agents\MechanismDesigner;
use App\Ai\Agents\MechanismResearcher;
use App\Jobs\RunMechanismPhase;
use App\Models\AiUsageLog;
use App\Models\KnowledgeNode;
use App\Models\Mechanism;
use App\Models\MechanismFinding;
use App\Models\MechanismRound;
use App\Models\MechanismSource;
use App\Models\User;
use App\Services\Mechanisms\MechanismLoop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class MechanismLoopTest extends TestCase
{
    use RefreshDatabase;

    private const GOAL = '登録した YouTube の料理動画から、2 人分の買い物リストを自動で作りたい';

    private const UNDERSTANDING = [
        'title' => '料理動画の買い物リスト',
        'restated_goal' => '料理動画の材料から、2 人分の買い物リストを作る。',
        'done_criteria' => ['2 人分の材料と量が一覧で出る', '動画ごとに作れる', '2 人分の材料と量が一覧で出る'],
        'scope_out' => ['店の値段の比較'],
        'unclear_points' => ['調味料を含めるか', '  '],
        'search_keywords' => ['買い物リスト', '動画の分析'],
    ];

    private const REPORT = [
        'summary' => '目標を言い換え、完了条件を決めました。仕組みはまだ作っていません。',
        'criteria_check' => [
            ['criterion' => '2 人分の材料と量が一覧で出る', 'status' => 'unknown', 'note' => '仕組みをまだ作っていない。'],
            ['criterion' => '動画ごとに作れる', 'status' => 'done', 'note' => '決まった値でない。'],
            ['criterion' => 'AI が足した条件', 'status' => 'met', 'note' => '入力にない。'],
        ],
        'remaining_missing' => ['調味料を含めるか'],
        'next_suggestion' => '知識検索で、動画の分析の結果を使えるか確かめる。',
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
    }

    public function test_one_round_runs_understanding_search_and_report_then_waits_for_continue(): void
    {
        $nodes = $this->knowledge();
        $this->fakeAgents(usedIds: [$nodes['shared']->id, $nodes['others']->id, 999999, $nodes['shared']->id]);
        $this->actingAs($this->owner);

        // キューは sync なので、作るとそのまま ① → ② → ⑥ まで進む。
        Livewire::test('pages::mechanisms.create')
            ->set('goal', self::GOAL)
            ->call('create')
            ->assertHasNoErrors();

        $mechanism = Mechanism::query()->sole();
        $round = $mechanism->rounds()->sole();

        $this->assertSame(MechanismRound::PHASE_DONE, $round->phase);
        $this->assertSame(MechanismRound::STATUS_WAITING, $round->phase_status);
        $this->assertTrue(MechanismLoop::isAwaitingContinue($round));

        // 名前を書かなかったので、AI の付けた名前になる。
        $this->assertSame('料理動画の買い物リスト', $mechanism->title);

        // 重複・空の項目は除く。
        $this->assertSame(['2 人分の材料と量が一覧で出る', '動画ごとに作れる'], $round->understanding['done_criteria']);
        $this->assertSame(['調味料を含めるか'], $round->understanding['unclear_points']);

        // ② 知識検索：本人が見られる、廃止でない、概念・設計判断・実装だけを探す。
        $foundIds = array_column($round->search['found'], 'id');
        $this->assertContains($nodes['shared']->id, $foundIds);
        $this->assertContains($nodes['mine']->id, $foundIds);
        $this->assertNotContains($nodes['others']->id, $foundIds);
        $this->assertNotContains($nodes['deprecated']->id, $foundIds);
        $this->assertNotContains($nodes['video']->id, $foundIds);
        $this->assertNotContains($nodes['unrelated']->id, $foundIds);
        $this->assertSame(['買い物リスト', '動画の分析', '料理動画の材料から、2 人分の買い物リストを作る。', self::GOAL], $round->search['queries']);

        // 使う知識は、渡した一覧にある id だけ（重複も除く）。
        $sources = $round->sources()->get();
        $this->assertSame([$nodes['shared']->id], $sources->pluck('knowledge_node_id')->all());
        $this->assertSame(MechanismSource::TYPE_KNOWLEDGE, $sources->first()->source_type);
        $this->assertSame('買い物リストの作り方', $sources->first()->title);
        $this->assertFalse($round->search['enough']);
        $this->assertSame(['料理 材料 抽出'], $round->search['web_queries']);

        // 完了条件は ① の項目に合わせ、決まった値でない判定は未確認にする。AI が足した条件は捨てる。
        // jsonb はキーの順番を保たないので、キーの順番は比べない。
        $this->assertEquals([
            ['criterion' => '2 人分の材料と量が一覧で出る', 'status' => 'unknown', 'note' => '仕組みをまだ作っていない。'],
            ['criterion' => '動画ごとに作れる', 'status' => 'unknown', 'note' => '決まった値でない。'],
        ], $round->report['criteria_check']);

        MechanismDesigner::assertPromptedTimes(2);
        MechanismResearcher::assertPromptedTimes(1);
        MechanismResearcher::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '買い物リストの作り方')
            && ! str_contains($prompt->prompt, '他人の買い物リスト'));
        MechanismDesigner::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->phase === MechanismRound::PHASE_REPORT
            && str_contains($prompt->prompt, '③ 前提条件の確認')
            && str_contains($prompt->prompt, '"used_knowledge"')
            && str_contains($prompt->prompt, '買い物リストの作り方'));

        $this->assertSame(3, AiUsageLog::query()
            ->where('feature', AiUsageLog::FEATURE_MECHANISM)
            ->where('usable_type', $round->getMorphClass())
            ->where('usable_id', $round->id)
            ->count());

        $this->get(route('mechanisms.show', $mechanism))
            ->assertOk()
            ->assertSee('① 目標の理解')
            ->assertSee('料理動画の材料から、2 人分の買い物リストを作る。')
            ->assertSee('② 知識検索')
            ->assertSee('買い物リストの作り方')
            ->assertSee('知識に足りない理由')
            ->assertSee('⑥ 結果報告')
            ->assertSee('まだ回しますか？')
            ->assertSee('目標（前の周のまま引き継ぎます）')
            ->assertSee('次の周への入力');
    }

    public function test_answering_yes_starts_the_next_round_with_the_new_input_and_keeps_the_goal(): void
    {
        $this->fakeAgents();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);

        $this->actingAs($this->owner);
        Livewire::test('pages::mechanisms.show', ['mechanism' => $mechanism])
            ->call('continueLoop')
            ->assertHasErrors('nextInstruction')
            ->set('nextInstruction', '調味料も含める。分量は 2 人分のまま。')
            ->call('continueLoop')
            ->assertHasNoErrors()
            ->assertSet('nextInstruction', '')
            ->assertSee('2 周目を始めました。')
            ->assertSee('調味料も含める。分量は 2 人分のまま。');

        $mechanism->refresh();
        $this->assertSame(self::GOAL, $mechanism->goal);
        $this->assertSame(2, $mechanism->current_round);

        [$first, $second] = $mechanism->rounds()->get()->all();
        $this->assertTrue($first->continue);
        $this->assertSame(MechanismRound::STATUS_DONE, $first->phase_status);
        $this->assertSame(2, $second->number);
        $this->assertSame('調味料も含める。分量は 2 人分のまま。', $second->instruction);
        $this->assertTrue(MechanismLoop::isAwaitingContinue($second));

        // 2 周目の ① には、目標・この周への入力・前の周の要点を渡す。② の検索語にも入力を使う。
        MechanismDesigner::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->phase === MechanismRound::PHASE_UNDERSTAND
            && str_contains($prompt->prompt, '"previous_rounds"')
            && str_contains($prompt->prompt, '調味料も含める。分量は 2 人分のまま。')
            && str_contains($prompt->prompt, '知識検索で、動画の分析の結果を使えるか確かめる。'));
        $this->assertContains('調味料も含める。分量は 2 人分のまま。', $second->search['queries']);
    }

    public function test_answers_to_unclear_points_are_kept_and_given_to_the_next_round(): void
    {
        $this->fakeAgents();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);

        $this->actingAs($this->owner);
        Livewire::test('pages::mechanisms.show', ['mechanism' => $mechanism])
            // 結果報告の「まだ足りない情報」が、1 つずつ回答欄付きで出る。
            ->assertSee('Q1. 調味料を含めるか')
            ->set('answers.0', '塩・醤油などの調味料も含める')
            ->call('continueLoop')
            ->assertHasNoErrors()
            ->assertSee('A. 塩・醤油などの調味料も含める');

        [$first, $second] = $mechanism->rounds()->get()->all();

        // 回答は、質問が出た周の不足情報として残す。
        $finding = $first->findings()->sole();
        $this->assertSame(MechanismFinding::KIND_MISSING, $finding->kind);
        $this->assertSame('調味料を含めるか', $finding->question);
        $this->assertSame('塩・醤油などの調味料も含める', $finding->answer);
        $this->assertNotNull($finding->answered_at);

        $this->assertNull($second->instruction);
        $this->assertSame([['question' => '調味料を含めるか', 'answer' => '塩・醤油などの調味料も含める']], $second->receivedAnswers());

        // 次の周の AI に「利用者の回答」として渡し、知識検索の検索語にも使う。
        MechanismDesigner::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->phase === MechanismRound::PHASE_UNDERSTAND
            && str_contains($prompt->prompt, '"answers"')
            && str_contains($prompt->prompt, '塩・醤油などの調味料も含める'));
        MechanismResearcher::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '塩・醤油などの調味料も含める'));
        $this->assertContains('塩・醤油などの調味料も含める', $second->search['queries']);
    }

    public function test_a_completed_mechanism_can_be_reopened_and_continued(): void
    {
        $this->fakeAgents();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);
        app(MechanismLoop::class)->finish($mechanism);

        $this->actingAs($this->owner);
        Livewire::test('pages::mechanisms.show', ['mechanism' => $mechanism->fresh()])
            ->assertDontSee('まだ回しますか？')
            ->call('reopen')
            ->assertSee('まだ回しますか？')
            ->assertSee('Q1. 調味料を含めるか')
            ->set('answers.0', '含めない')
            ->call('continueLoop')
            ->assertHasNoErrors();

        $mechanism->refresh();
        $this->assertSame(Mechanism::STATUS_ACTIVE, $mechanism->status);
        $this->assertSame(2, $mechanism->current_round);
        $this->assertTrue($mechanism->rounds()->where('number', 1)->sole()->continue);
    }

    public function test_the_next_round_cannot_go_past_the_limit(): void
    {
        $this->fakeAgents();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);
        $mechanism->update(['current_round' => Mechanism::MAX_ROUNDS]);

        $this->actingAs($this->owner);
        Livewire::test('pages::mechanisms.show', ['mechanism' => $mechanism])
            ->assertSee('周の上限')
            ->set('nextInstruction', 'もう 1 周')
            ->call('continueLoop')
            ->assertHasErrors('nextInstruction');

        $this->assertSame(1, $mechanism->rounds()->count());
    }

    public function test_answering_no_completes_the_mechanism(): void
    {
        $this->fakeAgents();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);

        $this->actingAs($this->owner);
        Livewire::test('pages::mechanisms.show', ['mechanism' => $mechanism])
            ->call('finish')
            ->assertSee('完了')
            ->assertDontSee('まだ回しますか？');

        $this->assertSame(Mechanism::STATUS_COMPLETED, $mechanism->fresh()->status);
        $round = $mechanism->rounds()->sole();
        $this->assertFalse($round->continue);
        $this->assertSame(MechanismRound::STATUS_DONE, $round->phase_status);
    }

    public function test_a_title_written_by_the_user_is_kept(): void
    {
        $this->fakeAgents();

        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL, 'title' => '自分で付けた名前'], $this->owner);

        $this->assertSame('自分で付けた名前', $mechanism->fresh()->title);
    }

    public function test_retrying_the_search_does_not_duplicate_sources(): void
    {
        $nodes = $this->knowledge();
        $this->fakeAgents(usedIds: [$nodes['shared']->id]);
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);
        $round = $mechanism->rounds()->sole();

        $round->update(['phase' => MechanismRound::PHASE_SEARCH, 'phase_status' => MechanismRound::STATUS_FAILED]);
        app(MechanismLoop::class)->retry($round);

        $this->assertSame(1, $round->sources()->count());
        $this->assertTrue(MechanismLoop::isAwaitingContinue($round->fresh()));
    }

    public function test_a_failed_phase_is_shown_and_can_be_retried(): void
    {
        Queue::fake();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);
        $round = $mechanism->rounds()->sole();

        $job = new RunMechanismPhase($round, MechanismRound::PHASE_UNDERSTAND);
        $job->failed(ProviderOverloadedException::forProvider('gemini'));

        $round->refresh();
        $this->assertSame(MechanismRound::STATUS_FAILED, $round->phase_status);
        $this->assertStringContainsString('gemini', $round->error);

        $this->actingAs($this->owner);
        Livewire::test('pages::mechanisms.show', ['mechanism' => $mechanism])
            ->assertSee('この段階からやり直す')
            ->call('retry');

        $this->assertSame(MechanismRound::STATUS_QUEUED, $round->fresh()->phase_status);
        Queue::assertPushed(RunMechanismPhase::class, 2);
    }

    public function test_a_stale_job_does_nothing(): void
    {
        Queue::fake();
        MechanismDesigner::fake()->preventStrayPrompts();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);
        $round = $mechanism->rounds()->sole();

        // すでに別の段階に進んでいる周に、古い段階のジョブが来た。
        $round->update(['phase' => MechanismRound::PHASE_REPORT]);
        (new RunMechanismPhase($round, MechanismRound::PHASE_UNDERSTAND))->handle(app(MechanismLoop::class));

        MechanismDesigner::assertNeverPrompted();
        $this->assertSame(MechanismRound::PHASE_REPORT, $round->fresh()->phase);
    }

    public function test_phases_not_handled_by_the_designer_cannot_be_asked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MechanismDesigner(MechanismRound::PHASE_SEARCH);
    }

    public function test_others_cannot_see_or_continue_a_mechanism(): void
    {
        $this->fakeAgents();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => self::GOAL], $this->owner);

        $this->actingAs(User::factory()->create());
        Livewire::test('pages::mechanisms.show', ['mechanism' => $mechanism])->assertNotFound();

        $this->assertSame(Mechanism::STATUS_ACTIVE, $mechanism->fresh()->status);
        $this->assertSame(1, $mechanism->rounds()->count());
    }

    /**
     * @return array<string, KnowledgeNode>
     */
    private function knowledge(): array
    {
        return [
            'shared' => KnowledgeNode::factory()->confirmed()->create(['title' => '買い物リストの作り方', 'description' => '材料をまとめる。']),
            'mine' => KnowledgeNode::factory()->decision()->create(['title' => '動画の分析から材料を取る', 'owner_id' => $this->owner->id]),
            'others' => KnowledgeNode::factory()->confirmed()->create(['title' => '他人の買い物リスト', 'owner_id' => User::factory()->create()->id]),
            'deprecated' => KnowledgeNode::factory()->create(['title' => '古い買い物リスト', 'status' => KnowledgeNode::STATUS_DEPRECATED]),
            'video' => KnowledgeNode::factory()->forVideo()->create(['title' => '買い物リストの動画']),
            'unrelated' => KnowledgeNode::factory()->confirmed()->create(['title' => '血圧の測り方', 'description' => '朝と夜に測る。']),
        ];
    }

    /**
     * @param  list<int>  $usedIds  ② で AI が「使う」と返す知識の id
     */
    private function fakeAgents(array $usedIds = []): void
    {
        MechanismDesigner::fake(fn (string $prompt) => str_contains($prompt, '"skipped_phases"')
            ? self::REPORT
            : (str_contains($prompt, '"goal"') ? self::UNDERSTANDING : throw new RuntimeException('想定外の入力')));

        MechanismResearcher::fake(fn () => [
            'used_nodes' => array_map(fn (int $id) => ['id' => $id, 'reason' => '材料をまとめるのに使う。'], $usedIds),
            'enough' => false,
            'why_not_enough' => '知識に足りない理由：動画から材料を取り出す方法がない。',
            'web_queries' => ['料理 材料 抽出', ''],
        ]);
    }
}
