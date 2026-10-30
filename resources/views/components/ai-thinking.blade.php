{{--
    AI（Gemini・OpenAI）が考えている間の表示。3 つの点が順に跳ねる。
    応答を待つ操作では wire:loading.inline-flex と wire:target を付けて使う（止まると Livewire が隠す）。
    キューのジョブを待つ画面では、処理中の間だけ描画する（wire:poll で状態が変わると消える）。
--}}
@props(['label' => 'AI が考えています'])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 text-xs text-[#706f6c] dark:text-[#A1A09A]']) }} role="status" aria-live="polite">
    <span class="flex items-end gap-1" aria-hidden="true">
        <span class="size-1.5 animate-bounce rounded-full bg-current"></span>
        <span class="size-1.5 animate-bounce rounded-full bg-current [animation-delay:150ms]"></span>
        <span class="size-1.5 animate-bounce rounded-full bg-current [animation-delay:300ms]"></span>
    </span>
    {{ $label }}
</span>
