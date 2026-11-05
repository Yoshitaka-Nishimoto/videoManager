<?php

namespace Tests\Feature;

use App\Services\Ai\AiErrorMessage;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Tests\TestCase;

class AiErrorMessageTest extends TestCase
{
    public function test_the_status_and_its_meaning_follow_the_message(): void
    {
        $this->assertSame(
            'AI provider [gemini] is overloaded.（503：混雑中）',
            AiErrorMessage::for(ProviderOverloadedException::forProvider('gemini', 503, $this->httpError(503))),
        );
        $this->assertSame(
            'Application rate limited by AI provider [gemini].（429：回数制限）',
            AiErrorMessage::for(RateLimitedException::forProvider('gemini', 429, $this->httpError(429))),
        );
    }

    public function test_the_status_is_taken_from_the_code_without_a_response(): void
    {
        $this->assertSame(
            'AI provider [gemini] is overloaded.（503：混雑中）',
            AiErrorMessage::for(ProviderOverloadedException::forProvider('gemini', 503)),
        );
    }

    public function test_other_errors_are_left_as_they_are(): void
    {
        $this->assertSame('AI の応答がありません。', AiErrorMessage::for(new RuntimeException('AI の応答がありません。')));
        $this->assertSame('AI provider [gemini] is overloaded.', AiErrorMessage::for(ProviderOverloadedException::forProvider('gemini')));
    }

    private function httpError(int $status): RequestException
    {
        return new RequestException(new Response(new Psr7Response($status, [], '{}')));
    }
}
