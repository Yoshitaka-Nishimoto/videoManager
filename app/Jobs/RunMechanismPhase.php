<?php

namespace App\Jobs;

use App\Models\MechanismRound;
use App\Services\Ai\AiErrorMessage;
use App\Services\Mechanisms\MechanismLoop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * 仕組みの周の 1 段階を AI で実行する（plotdesign.md）。終わったら MechanismLoop が次の段階のジョブを入れる。
 */
class RunMechanismPhase implements ShouldQueue
{
    use Queueable;

    /** AI の呼び出しは費用がかかるため自動ではやり直さない。失敗したら画面からやり直す。 */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public MechanismRound $round, public string $phase) {}

    public function handle(MechanismLoop $loop): void
    {
        $loop->run($this->round, $this->phase);
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception !== null ? AiErrorMessage::for($exception) : '段階を実行できませんでした。';

        app(MechanismLoop::class)->fail($this->round, $this->phase, Str::limit($message, 2000));
    }
}
