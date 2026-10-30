<?php

use App\Models\VideoProduction;
use App\Services\Knowledge\KnowledgeChat;
use App\Services\Knowledge\KnowledgeChatContext;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * 知識一覧の「確認済み」に置く、知識への質問欄。確認済みの概念と本人の個人のデータから答え、おすすめの概念へのリンクを出す。
 */
new class extends Component
{
    /** 知識一覧で選んでいるジャンル。指定があれば、根拠をそのジャンルに絞る。 */
    #[Reactive]
    public string $genre = '';

    #[Locked]
    public ?string $conversationId = null;

    public string $question = '';

    /** 根拠の概念を意味の近さで選ぶときの類似度の下限（KnowledgeChatContext::LOWEST_SIMILARITY〜1）。 */
    public float $minSimilarity = KnowledgeChatContext::MIN_SIMILARITY;

    public function mount(KnowledgeChat $chat): void
    {
        $this->conversationId = $chat->latestConversationId(auth()->user());
    }

    public function ask(KnowledgeChat $chat): void
    {
        $this->validate([
            'question' => ['required', 'string', 'max:2000'],
            'minSimilarity' => ['required', 'numeric', 'between:'.KnowledgeChatContext::LOWEST_SIMILARITY.',1'],
        ], attributes: ['question' => '質問', 'minSimilarity' => '類似度']);

        try {
            $this->conversationId = $chat->ask(auth()->user(), $this->conversationId, trim($this->question), $this->genre ?: null, $this->minSimilarity);
            $this->reset('question');
        } catch (Throwable $e) {
            Log::warning('知識への質問に答えられませんでした。', ['user_id' => auth()->id(), 'error' => $e->getMessage()]);
            $this->addError('question', 'AI から回答を得られませんでした（'.$e->getMessage().'）。時間をおいて、もう一度お試しください。');
        }

        unset($this->messages);
    }

    public function newConversation(): void
    {
        $this->conversationId = null;
        $this->reset('question');
        $this->resetErrorBag();
        unset($this->messages);
    }

    /**
     * @return list<array{role: string, text: string, concepts: list<array{node: \App\Models\KnowledgeNode, reason: string}>}>
     */
    #[Computed]
    public function messages(): array
    {
        return $this->conversationId === null ? [] : app(KnowledgeChat::class)->messages(auth()->user(), $this->conversationId);
    }
};
?>

<section class="space-y-3 rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]">
    <div class="flex items-center gap-3">
        <h2 class="text-sm font-semibold">知識に質問する</h2>
        <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
            確認済みの概念{{ $genre !== '' ? '（'.(VideoProduction::GENRES[$genre] ?? $genre).'）' : '' }}と、あなたの個人のデータから答えます
        </span>
        @if ($conversationId !== null)
            <button type="button" wire:click="newConversation" class="ml-auto whitespace-nowrap rounded-sm border border-[#19140035] px-2 py-0.5 text-xs hover:bg-[#f0f0ec] dark:border-[#3E3E3A] dark:hover:bg-[#1f1f1e]">新しい会話</button>
        @endif
    </div>

    @if ($this->messages !== [])
        <div class="max-h-[32rem] space-y-3 overflow-y-auto text-sm">
            @foreach ($this->messages as $index => $message)
                @if ($message['role'] === 'user')
                    <p wire:key="chat-{{ $index }}" class="ml-auto w-fit max-w-[85%] whitespace-pre-line rounded-lg bg-[#f0f0ec] px-3 py-2 dark:bg-[#1f1f1e]">{{ $message['text'] }}</p>
                @else
                    <div wire:key="chat-{{ $index }}" class="max-w-[95%] space-y-2">
                        <p class="whitespace-pre-line leading-relaxed">{{ $message['text'] }}</p>
                        @if ($message['concepts'] !== [])
                            <ul class="space-y-1 border-l-2 border-green-700 pl-3 text-xs">
                                @foreach ($message['concepts'] as $concept)
                                    <li wire:key="chat-{{ $index }}-concept-{{ $concept['node']->id }}">
                                        <a href="{{ route('knowledge.show', $concept['node']) }}" wire:navigate class="font-medium hover:underline">{{ $concept['node']->title }}</a>
                                        @if ($concept['similarity'] !== null)
                                            <span class="whitespace-nowrap font-mono text-blue-700 dark:text-blue-400" title="根拠に選んだときの、質問・個人のデータとの意味の近さ（0〜1）">:{{ number_format($concept['similarity'], 2) }}</span>
                                        @endif
                                        @if ($concept['node']->isPersonal())
                                            <span class="ml-1 whitespace-nowrap rounded-full bg-amber-100 px-2 py-0.5 text-amber-900 dark:bg-amber-950 dark:text-amber-200">本人だけ</span>
                                        @endif
                                        @if ($concept['reason'] !== '')
                                            <span class="text-[#706f6c] dark:text-[#A1A09A]">— {{ $concept['reason'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    <form wire:submit="ask" class="space-y-2">
        <textarea
            wire:model="question"
            rows="2"
            placeholder="例：血液検査の内容から、おすすめの概念を教えてください"
            aria-label="質問"
            class="w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]"
        ></textarea>
        @error('question')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror
        @error('minSimilarity')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror
        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" wire:target="ask" class="rounded-sm border border-[#1b1b18] bg-[#1b1b18] px-4 py-1 text-sm text-white disabled:opacity-50 dark:border-[#EDEDEC] dark:bg-[#EDEDEC] dark:text-[#1b1b18]">質問する</button>
            <label class="flex items-center gap-1 text-xs text-[#706f6c] dark:text-[#A1A09A]" title="根拠にする概念の、質問との意味の近さ（0〜1）の下限。高いほど関係の深い概念だけに絞ります">
                類似度
                <input
                    type="number"
                    wire:model="minSimilarity"
                    min="{{ KnowledgeChatContext::LOWEST_SIMILARITY }}"
                    max="1"
                    step="0.05"
                    class="w-16 rounded-sm border border-[#19140035] bg-white px-1 py-0.5 text-right text-xs text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]"
                >
                以上
            </label>
            <x-ai-thinking wire:loading.inline-flex wire:target="ask" label="AI が回答を考えています…" />
        </div>
    </form>
</section>
