<?php

namespace Tests\Feature;

use App\Actions\Productions\CreateProduction;
use App\Actions\Productions\SaveSceneScripts;
use App\Ai\Agents\ScriptWriterAgent;
use App\Jobs\GenerateScriptCandidates;
use App\Models\AiUsageLog;
use App\Models\KnowledgeNode;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Services\Ai\GeminiModels;
use App\Services\AiUsage\AiUsageRecorder;
use App\Services\Script\GeminiScriptWriter;
use App\Services\Script\NarrationTiming;
use App\Services\Script\ScriptWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_candidates_queues_one_generation_per_plan(): void
    {
        Queue::fake();
        [$plan] = $this->createProduction();

        $this->page($plan)->call('requestScript')->assertHasNoErrors()->assertSee('① 順番待ち');
        $this->page($plan)->call('requestScript')->assertHasErrors('script');

        $this->assertSame(ProductionPlan::SCRIPT_GENERATING, $plan->refresh()->script_status);
        Queue::assertPushed(GenerateScriptCandidates::class, 1);
    }

    public function test_gemini_candidates_follow_the_scenes_of_the_plan(): void
    {
        [$plan] = $this->createProduction();
        ScriptWriterAgent::fake([['candidates' => [
            ['label' => '結論から', 'summary' => '結論を先に言う。', 'scenes' => [
                $this->script('s1', '入力画像を確かめると、生成の失敗が減ります。', heading: '入力画像を確かめる'),
                $this->script('s2', '見るのは三つです。', items: ['解像度', '顔の向き', '明るさ', '', 'x', 'y', 'z']),
                $this->script('s3', '合格した画像だけを渡します。', labels: ['受け取る', '確認', '生成']),
                $this->script('s9', '存在しない場面'),
            ]],
            ['label' => '問いかけ', 'summary' => '', 'scenes' => []],
            ['label' => '具体例', 'summary' => '', 'scenes' => []],
        ]]]);

        $plan->update(['script_status' => ProductionPlan::SCRIPT_GENERATING, 'script_requested_at' => now()]);
        (new GenerateScriptCandidates($plan))->handle(new GeminiScriptWriter(new GeminiModels('model-a')), app(AiUsageRecorder::class));

        $plan->refresh();
        $this->assertSame(ProductionPlan::SCRIPT_READY, $plan->script_status);
        $this->assertCount(3, $plan->script_candidates);
        $first = $plan->script_candidates[0];
        $this->assertSame(['s1', 's2', 's3', 's4'], array_column($first['scenes'], 'key'));
        $this->assertSame(['解像度', '顔の向き', '明るさ', 'x', 'y', 'z'], $first['scenes'][1]['items']); // 空は捨て、6 個まで
        $this->assertSame('', $plan->script_candidates[1]['scenes'][0]['narration']); // 足りない場面は空
        ScriptWriterAgent::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, '"narration_characters"')
            && str_contains($prompt->prompt, '実務者') && str_contains($prompt->prompt, 'テンポよく'));
        $this->assertSame(AiUsageLog::FEATURE_SCRIPT, AiUsageLog::sole()->feature);
        $this->assertTrue(AiUsageLog::sole()->usable->is($plan));
    }

    public function test_an_overloaded_model_falls_back_to_the_next_one(): void
    {
        [$plan] = $this->createProduction();
        ScriptWriterAgent::fake(fn ($prompt, $attachments, $provider, string $model) => $model === 'model-b'
            ? ['candidates' => [['label' => '案', 'summary' => '', 'scenes' => []]]]
            : throw ProviderOverloadedException::forProvider('gemini'));

        $draft = (new GeminiScriptWriter(new GeminiModels('model-a', ['model-b'])))->write($plan);

        $this->assertCount(1, $draft->candidates);
        ScriptWriterAgent::assertPromptedTimes(2);
    }

    public function test_a_failed_generation_is_shown_and_can_be_retried(): void
    {
        Queue::fake();
        [$plan] = $this->createProduction();
        $plan->update(['script_status' => ProductionPlan::SCRIPT_GENERATING]);

        (new GenerateScriptCandidates($plan))->failed(ProviderOverloadedException::forProvider('gemini'));

        $this->page($plan)->assertSee('台本の候補を作れませんでした')->call('requestScript')->assertHasNoErrors();
        Queue::assertPushed(GenerateScriptCandidates::class);
    }

    public function test_the_dummy_writer_makes_three_candidates_without_an_api(): void
    {
        config(['services.script_writer.driver' => 'dummy']);
        [$plan] = $this->createProduction();

        app(ScriptWriter::class)->write($plan);
        $plan->update(['script_status' => ProductionPlan::SCRIPT_GENERATING, 'script_requested_at' => now()]);
        (new GenerateScriptCandidates($plan))->handle(app(ScriptWriter::class), app(AiUsageRecorder::class));

        $this->assertSame(['結論から', '問いかけから', '具体例から'], array_column($plan->refresh()->script_candidates, 'label'));
    }

    public function test_choosing_a_candidate_fills_the_scenes_and_fits_the_length_to_the_narration(): void
    {
        [$plan] = $this->createProduction();
        $plan->update(['script_status' => ProductionPlan::SCRIPT_READY, 'script_candidates' => [
            ['label' => '案1', 'summary' => '', 'scenes' => [$this->script('s1', 'あ')]],
            ['label' => '案2', 'summary' => '', 'scenes' => [
                $this->script('s1', str_repeat('あ', 26), caption: '字幕です', heading: '新しい見出し'),
                $this->script('s2', 'いいい', items: ['項目1', '項目2']),
                $this->script('s3', '', labels: ['箱A', '', '箱C']),
                $this->script('s4', ''),
            ]],
        ]]);

        $this->page($plan)->set('candidateIndex', 1)->call('applyCandidate')->assertHasNoErrors()
            ->assertSet('scripts.'.$plan->videoScenes[0]->id.'.caption', '字幕です');

        $scenes = $plan->refresh()->videoScenes;
        $this->assertSame(1, $plan->script_selected);
        $this->assertSame([str_repeat('あ', 26), '字幕です', '新しい見出し'], [$scenes[0]->narration, $scenes[0]->caption, $scenes[0]->content['title']['heading']]);
        $this->assertSame('4.50', $scenes[0]->duration_seconds); // 26 文字 ÷ 6.5 = 4 秒 + 間 0.5 秒
        $this->assertSame('2.00', $scenes[1]->duration_seconds); // 短いナレーションは最短の 2 秒
        $this->assertSame(['項目1', '項目2'], $scenes[1]->content['text']['items']);
        $this->assertSame(['箱A', '顔の向きを確認する', '箱C'], array_column($scenes[2]->content['diagram']['nodes'], 'label')); // 空の名前は今のまま
        $this->assertSame('18.00', $scenes[2]->duration_seconds); // ナレーションがなければ長さは変えない
    }

    public function test_edited_narration_and_captions_are_saved_with_new_lengths(): void
    {
        [$plan] = $this->createProduction();
        $scene = $plan->videoScenes[0];

        $this->page($plan)
            ->set("scripts.{$scene->id}.narration", str_repeat('あ', 13))
            ->set("scripts.{$scene->id}.caption", '  ')
            ->call('saveScripts')
            ->assertHasNoErrors()
            ->assertSee('台本を保存し');

        $scene->refresh();
        $this->assertSame([str_repeat('あ', 13), null, '2.50'], [$scene->narration, $scene->caption, $scene->duration_seconds]);
    }

    public function test_a_confirmed_plan_cannot_be_rewritten(): void
    {
        Queue::fake();
        [$plan] = $this->createProduction();
        $plan->update(['status' => ProductionPlan::STATUS_CONFIRMED, 'script_candidates' => [['label' => '案', 'summary' => '', 'scenes' => []]]]);
        $scene = $plan->videoScenes[0];

        $this->page($plan)
            ->assertDontSee('台本の候補を作る')
            ->call('requestScript')->assertHasErrors('script')
            ->call('applyCandidate')->assertHasErrors('script')
            ->set("scripts.{$scene->id}.narration", '書き換え')
            ->call('saveScripts')->assertHasErrors('script');

        $this->assertNull($scene->refresh()->narration);
        Queue::assertNothingPushed();
    }

    public function test_scripts_of_another_plan_are_not_changed(): void
    {
        [$plan] = $this->createProduction();
        [$other] = $this->createProduction();
        $otherScene = $other->videoScenes[0];

        app(SaveSceneScripts::class)->handle($plan, [$otherScene->id => ['narration' => '他の制作案', 'caption' => null]]);

        $this->assertNull($otherScene->refresh()->narration);
    }

    public function test_narration_timing_rounds_up_to_half_seconds(): void
    {
        $this->assertSame(7.0, NarrationTiming::seconds('　'.str_repeat('あ', 40).' ', 3.0)); // 空白は数えない：40 ÷ 6.5 = 6.15 → 6.5 + 0.5
        $this->assertSame(3.0, NarrationTiming::seconds(null, 3.0));
        $this->assertSame(16, NarrationTiming::characterBudget(3.0));
    }

    /**
     * @return array{ProductionPlan, User}
     */
    private function createProduction(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $decision = KnowledgeNode::factory()->decision()->create(['title' => '生成の前に入力画像を確認する', 'description' => '解像度を確認する。顔の向きを確認する。明るさを確認する。']);

        $production = app(CreateProduction::class)->handle($decision, [
            'genre' => 'ai', 'structure' => 'explain', 'audience' => 'practitioner', 'duration' => 60,
            'tone' => 'upbeat', 'ratio' => '1280:720', 'title' => '入力画像の確認', 'brief' => $decision->description,
        ], $user);

        return [$production->plans()->sole(), $user];
    }

    private function page(ProductionPlan $plan): Testable
    {
        return Livewire::test('pages::productions.show', ['production' => $plan->production]);
    }

    /**
     * @param  list<string>  $items
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private function script(string $key, string $narration, string $caption = '', string $heading = '', array $items = [], array $labels = []): array
    {
        return ['key' => $key, 'narration' => $narration, 'caption' => $caption, 'heading' => $heading, 'subheading' => '', 'items' => $items, 'labels' => $labels];
    }

    public function test_progress_shows_waiting_then_running_with_elapsed_time(): void
    {
        Queue::fake();
        [$plan] = $this->createProduction();
        $this->travelTo(now()->startOfMinute());

        $this->page($plan)->call('requestScript');
        $this->travel(25)->seconds();

        $this->page($plan)
            ->assertSee('① 順番待ち')
            ->assertSee('順番待ち：25 秒経過')
            ->assertSee('キューのワーカーが動いていない可能性があります');

        $plan->refresh()->update(['script_started_at' => now()]);
        $this->travel(12)->seconds();

        $this->page($plan)
            ->assertSee('Gemini が作成中：12 秒経過')
            ->assertDontSee('キューのワーカーが動いていない可能性があります');
    }

    public function test_cancelling_skips_the_queued_job_without_calling_gemini(): void
    {
        Queue::fake();
        [$plan] = $this->createProduction();
        ScriptWriterAgent::fake();
        $this->page($plan)->call('requestScript');
        $job = Queue::pushed(GenerateScriptCandidates::class)->first();

        $this->page($plan)->call('cancelScript')->assertSee('台本の候補作りを止めました')->assertSee('台本の候補を作る（Gemini）');
        $job->handle(new GeminiScriptWriter(new GeminiModels('model-a')), app(AiUsageRecorder::class));

        $this->assertNull($plan->refresh()->script_status);
        ScriptWriterAgent::assertNeverPrompted();
    }

    public function test_an_old_job_does_not_overwrite_a_newer_request(): void
    {
        Queue::fake();
        [$plan] = $this->createProduction();
        $this->page($plan)->call('requestScript');
        $old = Queue::pushed(GenerateScriptCandidates::class)->first();
        $this->page($plan)->call('cancelScript');
        $this->travel(5)->seconds();
        $this->page($plan)->call('requestScript');
        ScriptWriterAgent::fake();

        $old->handle(new GeminiScriptWriter(new GeminiModels('model-a')), app(AiUsageRecorder::class));
        $old->failed(new \RuntimeException('古い依頼の失敗'));

        $this->assertSame(ProductionPlan::SCRIPT_GENERATING, $plan->refresh()->script_status);
        $this->assertNull($plan->script_error);
        ScriptWriterAgent::assertNeverPrompted();
    }

    public function test_a_confirmed_plan_explains_where_to_write_the_script(): void
    {
        [$plan] = $this->createProduction();
        $plan->update(['status' => ProductionPlan::STATUS_CONFIRMED]);

        $this->page($plan)->assertSee('この版は確認済みのため、台本は直せません');
    }
}
