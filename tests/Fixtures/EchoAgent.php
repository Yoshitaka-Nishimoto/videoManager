<?php

namespace Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * テスト用の最小限のエージェント。fake() と組み合わせて使う。
 */
class EchoAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return '入力をそのまま返します。';
    }
}
