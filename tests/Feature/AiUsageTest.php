<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Models\VideoAnalysis;
use App\Services\AiUsage\AiUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\EchoAgent;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_calls_inside_a_scope_are_linked_to_the_feature_and_record(): void
    {
        config(['ai_usage.prices.test-model' => ['input' => 3, 'output' => 15]]);
        $recorder = app(AiUsageRecorder::class);
        $analysis = VideoAnalysis::factory()->create();

        $recorder->within(AiUsageLog::FEATURE_ANALYSIS, $analysis, fn () => $recorder->record('anthropic', 'test-model', 1_000_000, 100_000));
        $outside = $recorder->record('anthropic', 'unknown-model', 10, 10, defaultFeature: 'chat');

        $log = AiUsageLog::where('model', 'test-model')->sole();
        $this->assertSame(AiUsageLog::FEATURE_ANALYSIS, $log->feature);
        $this->assertTrue($log->usable->is($analysis));
        $this->assertSame('4.500000', $log->estimated_cost);

        $this->assertSame('chat', $outside->feature);
        $this->assertNull($outside->usable_type);
        $this->assertNull($outside->estimated_cost);
    }

    public function test_sdk_agent_calls_are_recorded_automatically(): void
    {
        EchoAgent::fake(['こんにちは']);

        (new EchoAgent)->prompt('こんにちは');

        $log = AiUsageLog::sole();
        $this->assertSame('EchoAgent', $log->feature);
        $this->assertTrue($log->succeeded);
    }

    public function test_the_usage_page_summarises_by_model_and_feature(): void
    {
        $this->actingAs(User::factory()->create());
        AiUsageLog::factory()->count(2)->create(['model' => 'claude-sonnet-5-5', 'input_tokens' => 1000, 'output_tokens' => 200]);
        AiUsageLog::factory()->failed()->create(['model' => 'gemini-test', 'feature' => 'chat']);

        $this->get('/ai-usage')
            ->assertOk()
            ->assertSee('3 回')
            ->assertSee('anthropic / claude-sonnet-5-5')
            ->assertSee('anthropic / gemini-test')
            ->assertSee('APIの応答がタイムアウトしました。');
    }
}
