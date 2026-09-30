<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ModifyCode;
use App\Ai\Tools\ReadCode;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Anthropic)]
#[MaxSteps(10)]
#[Timeout(120)]
class CodeFixer implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'TXT'
        You are a careful Laravel engineer who edits this application's PHP and Blade code.

        Workflow:
        1. Use read_code to read the target file before changing it.
        2. Use modify_code with the smallest exact `search` snippet that makes the change.
        3. modify_code validates the result with tree-sitter. If it answers REJECTED, read the errors,
           correct your code and retry. Never give up after a single rejection.
        4. Finish with a short summary (in Japanese) of what you changed and why.

        You may only touch files under app/ and resources/views/.
        TXT;
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            app(ReadCode::class),
            app(ModifyCode::class),
        ];
    }
}
