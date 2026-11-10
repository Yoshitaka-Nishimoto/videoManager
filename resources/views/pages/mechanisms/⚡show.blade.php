<?php

use App\Models\Mechanism;
use App\Models\MechanismRound;
use App\Services\Mechanisms\MechanismLoop;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('仕組みの詳細')] class extends Component
{
    public Mechanism $mechanism;

    /** はっきりしない点（MechanismLoop::openQuestions の番号）ごとの回答。空なら答えない。 */
    public array $answers = [];

    /** 次の周への入力（目標は前の周のまま変えず、回答とこれを足して次の周を回す）。 */
    public string $nextInstruction = '';

    /**
     * 本人の仕組みでなければ、あることも知らせない（404）。
     */
    public function mount(): void
    {
        $this->authorizeOwner();
    }

    /**
     * @return Collection<int, MechanismRound> 古い順
     */
    #[Computed]
    public function rounds(): Collection
    {
        return $this->mechanism->rounds()->with('sources')->get();
    }

    #[Computed]
    public function round(): ?MechanismRound
    {
        return $this->rounds->last();
    }

    /**
     * AI の段階が待機中・実行中なら、画面を 2 秒ごとに更新する。
     */
    #[Computed]
    public function isBusy(): bool
    {
        return in_array($this->round?->phase_status, [MechanismRound::STATUS_QUEUED, MechanismRound::STATUS_RUNNING], true);
    }

    #[Computed]
    public function isAwaitingContinue(): bool
    {
        return $this->mechanism->status === Mechanism::STATUS_ACTIVE
            && $this->round !== null
            && MechanismLoop::isAwaitingContinue($this->round);
    }

    public function retry(MechanismLoop $loop): void
    {
        $this->authorizeOwner();

        if ($this->round?->phase_status === MechanismRound::STATUS_FAILED) {
            $loop->retry($this->round);
        }

        $this->refreshRounds();
    }

    /**
     * 「まだ回しますか？」に「はい」。次の周への入力を付けて、次の周を始める。
     */
    public function continueLoop(MechanismLoop $loop): void
    {
        $this->authorizeOwner();

        if (! $this->isAwaitingContinue) {
            return;
        }

        if (! MechanismLoop::canContinue($this->mechanism)) {
            $this->addError('nextInstruction', '周の上限（'.Mechanism::MAX_ROUNDS.' 周）に達しました。目標を分けて、新しい仕組みを作ってください。');

            return;
        }

        $validated = $this->validate([
            'answers' => ['array'],
            'answers.*' => ['nullable', 'string', 'max:2000'],
            'nextInstruction' => ['nullable', 'string', 'max:'.MechanismLoop::INSTRUCTION_MAX_LENGTH],
        ], attributes: ['answers.*' => '回答', 'nextInstruction' => '次の周への入力']);

        $hasAnswer = collect($validated['answers'] ?? [])->contains(fn ($answer) => trim((string) $answer) !== '');
        if (! $hasAnswer && trim((string) $validated['nextInstruction']) === '') {
            $this->addError('nextInstruction', 'はっきりしない点に 1 つ以上答えるか、次の周への入力を書いてください。');

            return;
        }

        $next = $loop->nextRound($this->mechanism, $validated['nextInstruction'], $validated['answers'] ?? []);

        $this->reset('answers', 'nextInstruction');
        session()->flash('status', "{$next->number} 周目を始めました。");
        $this->mechanism->refresh();
        $this->refreshRounds();
    }

    /**
     * 「まだ回しますか？」に「いいえ」。
     */
    public function finish(MechanismLoop $loop): void
    {
        $this->authorizeOwner();

        if ($this->isAwaitingContinue) {
            $loop->finish($this->mechanism);
            session()->flash('status', '仕組みを完了にしました。');
        }

        $this->mechanism->refresh();
        $this->refreshRounds();
    }

    /**
     * 「いいえ」で完了にした仕組みを再開し、「まだ回しますか？」に戻す。
     */
    public function reopen(MechanismLoop $loop): void
    {
        $this->authorizeOwner();

        if ($this->round !== null && MechanismLoop::canReopen($this->mechanism, $this->round)) {
            $loop->reopen($this->mechanism);
            session()->flash('status', '仕組みを再開しました。はっきりしない点に答えて、次の周を回せます。');
        }

        $this->mechanism->refresh();
        $this->refreshRounds();
    }

    private function refreshRounds(): void
    {
        unset($this->rounds, $this->round, $this->isBusy, $this->isAwaitingContinue);
    }

    private function authorizeOwner(): void
    {
        abort_unless($this->mechanism->owner_id === auth()->id(), 404);
    }
};
?>

@php
    $muted = 'text-[#706f6c] dark:text-[#A1A09A]';
    $card = 'space-y-3 rounded-sm border border-[#19140035] p-4 dark:border-[#3E3E3A]';
    $button = 'rounded-sm bg-[#1b1b18] px-4 py-2 text-sm text-white hover:bg-black disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]';
    $buttonOutline = 'rounded-sm border border-[#1b1b18] px-4 py-2 text-sm hover:bg-[#1b1b18] hover:text-white disabled:cursor-not-allowed disabled:opacity-40 dark:border-[#EDEDEC] dark:hover:bg-[#EDEDEC] dark:hover:text-[#1b1b18]';
    $input = 'w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]';
    $round = $this->round;
    $previousRounds = $this->rounds->slice(0, -1);
    $phaseOrder = array_keys(MechanismRound::PHASE_LABELS);
    $currentIndex = $round ? array_search($round->phase, $phaseOrder, true) : false;
@endphp

<div class="max-w-3xl space-y-6" @if ($this->isBusy) wire:poll.2s @endif>
    <div class="flex gap-4">
        <a href="{{ route('productions.index') }}" wire:navigate class="text-sm {{ $muted }} hover:underline">← 制作一覧</a>
        <a href="{{ route('mechanisms.index') }}" wire:navigate class="text-sm {{ $muted }} hover:underline">仕組みの一覧</a>
    </div>

    @if (session('status'))
        <p class="rounded-sm bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">{{ session('status') }}</p>
    @endif

    <div class="flex items-start justify-between gap-4">
        <h1 class="text-xl font-semibold">仕組み：{{ $mechanism->title }}</h1>
        <p class="shrink-0 text-sm {{ $muted }}">
            {{ $mechanism->current_round }} 周目
            @if ($mechanism->status !== Mechanism::STATUS_ACTIVE)
                / {{ $mechanism->statusLabel() }}
            @elseif ($round)
                / {{ $round->phaseLabel() }}（{{ $round->phaseStatusLabel() }}）
            @endif
        </p>
    </div>

    <section class="{{ $card }}">
        <h2 class="font-semibold">目標</h2>
        <p class="whitespace-pre-wrap text-sm">{{ $mechanism->goal }}</p>
    </section>

    {{-- 前の周（閉じておく） --}}
    @foreach ($previousRounds as $previous)
        <details wire:key="round-{{ $previous->id }}" class="{{ $card }}">
            <summary class="cursor-pointer font-semibold">
                {{ $previous->number }} 周目
                <span class="text-sm font-normal {{ $muted }}">{{ Str::limit($previous->report['summary'] ?? '', 60) }}</span>
            </summary>
            <div class="mt-3">
                <x-mechanisms.round :round="$previous" />
            </div>
        </details>
    @endforeach

    @if ($round)
        <h2 class="text-lg font-semibold">{{ $round->number }} 周目</h2>

        {{-- 進み具合 --}}
        <section class="{{ $card }}">
            <h3 class="font-semibold">ループ</h3>
            <ol class="space-y-1 text-sm">
                @foreach (MechanismRound::PHASE_LABELS as $phase => $label)
                    @continue($phase === MechanismRound::PHASE_DONE)
                    @php
                        $index = array_search($phase, $phaseOrder, true);
                        $skipped = in_array($phase, MechanismLoop::SKIPPED_PHASES, true);
                        $isCurrent = $round->phase === $phase;
                    @endphp
                    <li wire:key="phase-{{ $phase }}" @class(['font-semibold' => $isCurrent, $muted => ! $isCurrent])>
                        {{ $label }}
                        @if ($skipped)
                            <span class="text-xs">（準備中のため飛ばします）</span>
                        @elseif ($isCurrent)
                            ← {{ $round->phaseStatusLabel() }}
                            @if ($this->isBusy)
                                <x-ai-thinking class="ml-2" />
                            @endif
                        @elseif ($currentIndex !== false && $index < $currentIndex)
                            <span class="text-xs">✓</span>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if ($round->phase_status === MechanismRound::STATUS_FAILED)
                <div class="space-y-2 rounded-sm bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
                    <p>{{ $round->phaseLabel() }}に失敗しました：{{ $round->error }}</p>
                    <button type="button" wire:click="retry" wire:loading.attr="disabled" class="{{ $button }}">この段階からやり直す</button>
                </div>
            @endif
        </section>

        <div wire:key="round-{{ $round->id }}">
            <x-mechanisms.round :round="$round" />
        </div>
    @endif

    {{-- まだ回すか --}}
    @if ($this->isAwaitingContinue)
        <section class="{{ $card }}">
            <h2 class="font-semibold">まだ回しますか？</h2>

            <div class="space-y-1 text-sm">
                <p class="{{ $muted }}">目標（前の周のまま引き継ぎます）</p>
                <p class="whitespace-pre-wrap rounded-sm bg-[#f5f5f3] px-3 py-2 dark:bg-[#1f1f1d]">{{ $mechanism->goal }}</p>
            </div>

            @if (MechanismLoop::canContinue($mechanism))
                @php $questions = MechanismLoop::openQuestions($round); @endphp
                @if ($questions !== [])
                    <div class="space-y-3">
                        <p class="text-sm {{ $muted }}">はっきりしない点に答えてください（分からない・決めていない項目は空のままで構いません。次の周でも、はっきりしない点として残ります）</p>
                        @foreach ($questions as $index => $question)
                            <label wire:key="question-{{ $round->id }}-{{ $index }}" class="block space-y-1 text-sm">
                                <span>Q{{ $index + 1 }}. {{ $question }}</span>
                                <textarea wire:model="answers.{{ $index }}" rows="2" class="{{ $input }}"></textarea>
                            </label>
                            @error('answers.'.$index) <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        @endforeach
                    </div>
                @endif

                <label class="block space-y-1 text-sm">
                    <span class="{{ $muted }}">次の周への入力（任意。変えたい条件、次に詳しく考えたいことなど）</span>
                    <textarea wire:model="nextInstruction" rows="3" class="{{ $input }}" placeholder="{{ $round->report['next_suggestion'] ?? '' }}"></textarea>
                </label>
                @error('nextInstruction') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @else
                <p class="text-sm text-red-700 dark:text-red-400">周の上限（{{ Mechanism::MAX_ROUNDS }} 周）に達しました。続けるときは、目標を分けて新しい仕組みを作ってください。</p>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="continueLoop" wire:loading.attr="disabled" @disabled(! MechanismLoop::canContinue($mechanism)) class="{{ $buttonOutline }}">はい（次の周へ）</button>
                <button type="button" wire:click="finish" wire:loading.attr="disabled" class="{{ $button }}">いいえ（完了）</button>
            </div>
        </section>
    @elseif ($round && MechanismLoop::canReopen($mechanism, $round))
        <section class="{{ $card }}">
            <h2 class="font-semibold">完了しました</h2>
            <p class="text-sm {{ $muted }}">はっきりしない点に答えて、もう一度回すときは再開してください。</p>
            <button type="button" wire:click="reopen" wire:loading.attr="disabled" class="{{ $buttonOutline }}">再開する（次の周を回す）</button>
        </section>
    @endif
</div>
