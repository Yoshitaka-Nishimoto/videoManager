<?php

use App\Actions\Mechanisms\CreateMechanism;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('仕組みを作る')] class extends Component
{
    /** 目標・質問。 */
    public string $goal = '';

    /** 仕組みの名前（空なら目標の先頭を仮の名前にする）。 */
    public string $title = '';

    public function create(CreateMechanism $create): void
    {
        $validated = $this->validate([
            'goal' => ['required', 'string', 'min:10', 'max:4000'],
            'title' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'goal' => '目標',
            'title' => '仕組みの名前',
        ]);

        $mechanism = $create->handle($validated, auth()->user());

        session()->flash('status', '仕組みを作りました。1 周目の「① 目標の理解」から始めます。');
        $this->redirectRoute('mechanisms.show', $mechanism, navigate: true);
    }
};
?>

@php
    $muted = 'text-[#706f6c] dark:text-[#A1A09A]';
    $card = 'space-y-3 rounded-sm border border-[#19140035] p-4 dark:border-[#3E3E3A]';
    $input = 'w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]';
    $label = 'block space-y-1 text-sm';
@endphp

<div class="max-w-3xl space-y-6">
    <div class="flex gap-4">
        <a href="{{ route('productions.index') }}" wire:navigate class="text-sm {{ $muted }} hover:underline">← 制作一覧</a>
        <a href="{{ route('mechanisms.index') }}" wire:navigate class="text-sm {{ $muted }} hover:underline">仕組みの一覧</a>
    </div>

    <h1 class="text-xl font-semibold">仕組みを作る</h1>

    <p class="text-sm {{ $muted }}">
        目標を書くと、AI がプロジェクト内の知識（足りなければ Web）をもとに、前提条件・不足情報・判断点を確かめながら仕組みを設計します。
        足りない情報は AI が想像で補わず、質問するか、候補を 2〜3 個出します。
    </p>

    <form wire:submit="create" class="space-y-6">
        <section class="{{ $card }}">
            <label class="{{ $label }}">
                <span class="{{ $muted }}">目標・質問（何ができれば達成か、分かっている条件があれば書いてください）</span>
                <textarea wire:model="goal" rows="6" class="{{ $input }}" placeholder="例：登録した YouTube の料理動画から、2 人分の買い物リストを自動で作りたい"></textarea>
            </label>
            @error('goal') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            <label class="{{ $label }}">
                <span class="{{ $muted }}">仕組みの名前（任意。空なら目標の先頭を仮の名前にします）</span>
                <input type="text" wire:model="title" class="{{ $input }}">
            </label>
            @error('title') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        </section>

        <section class="{{ $card }}">
            <h2 class="font-semibold">進め方</h2>
            <ol class="list-decimal space-y-1 pl-5 text-sm {{ $muted }}">
                <li>目標の理解（言い換えと完了条件）</li>
                <li>知識検索（足りなければ Web も検索）</li>
                <li>前提条件の確認（前提条件・不足情報・判断点）</li>
                <li>質問か候補確認（あなたが答える・選ぶ）</li>
                <li>仕組みのプログラム作成（案として表示。自動では反映しません）</li>
                <li>結果報告 → 「まだ回しますか？」</li>
            </ol>
        </section>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-sm bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]">仕組みを作る</button>
            <p class="text-xs {{ $muted }}">仕組みはあなただけが見られます。</p>
        </div>
    </form>
</div>
