<?php

namespace Tests\Feature;

use App\Services\Ai\GeminiModels;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\TestCase;

class GeminiModelsTest extends TestCase
{
    public function test_a_retired_model_is_skipped_for_the_next_one(): void
    {
        $tried = [];

        [$result, $model] = (new GeminiModels('retired', ['busy', 'working']))->attempt(function (?string $model) use (&$tried) {
            $tried[] = $model;

            return match ($model) {
                'retired' => throw $this->httpError(404, 'This model is no longer available to new users.'),
                'busy' => throw RateLimitedException::forProvider('gemini'),
                default => 'ok',
            };
        }, purpose: 'テスト');

        $this->assertSame(['ok', 'working'], [$result, $model]);
        $this->assertSame(['retired', 'busy', 'working'], $tried);
    }

    public function test_other_http_errors_are_not_retried_with_another_model(): void
    {
        $tried = [];

        try {
            (new GeminiModels('first', ['second']))->attempt(function (?string $model) use (&$tried) {
                $tried[] = $model;

                throw $this->httpError(400, 'Invalid request');
            }, purpose: 'テスト');
            $this->fail('例外が投げられませんでした。');
        } catch (RequestException $e) {
            $this->assertSame(400, $e->response->status());
        }

        $this->assertSame(['first'], $tried);
    }

    public function test_the_last_error_is_thrown_when_no_model_works(): void
    {
        $this->expectException(RequestException::class);

        (new GeminiModels('first', ['second']))->attempt(fn () => throw $this->httpError(404, 'gone'), purpose: 'テスト');
    }

    public function test_the_default_fallbacks_do_not_include_retired_models(): void
    {
        $this->assertNotContains('gemini-2.5-flash', config('services.video_analyzer.gemini_fallback_models'));
    }

    private function httpError(int $status, string $message): RequestException
    {
        return new RequestException(new Response(new Psr7Response($status, [], json_encode(['error' => ['message' => $message]]))));
    }
}
