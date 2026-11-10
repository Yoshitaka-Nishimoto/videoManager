<?php

use App\Models\Mechanism;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('仕組み')] class extends Component
{
    use WithPagination;

    /**
     * 本人の仕組みだけ（個人の知識を使うことがあるため）。
     *
     * @return LengthAwarePaginator<int, Mechanism>
     */
    #[Computed]
    public function mechanisms(): LengthAwarePaginator
    {
        return Mechanism::query()
            ->ownedBy(auth()->user())
            ->with('latestRound')
            ->latest('id')
            ->paginate(20);
    }
};
?>

<div class="space-y-6">
    <div>
        <a href="{{ route('productions.index') }}" wire:navigate class="text-sm text-[#706f6c] hover:underline dark:text-[#A1A09A]">← 制作一覧</a>
    </div>

    <div class="flex items-center justify-between gap-4">
        <h1 class="text-xl font-semibold">仕組み</h1>
        <a href="{{ route('mechanisms.create') }}" wire:navigate class="rounded-sm bg-[#1b1b18] px-4 py-2 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]">仕組みを作る</a>
    </div>

    @if ($this->mechanisms->isEmpty())
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">まだ仕組みがありません。「仕組みを作る」から、目標を書いて作れます。</p>
    @else
        <table class="w-full text-left text-sm">
            <thead class="border-b border-[#19140035] text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                <tr>
                    <th class="py-2 pr-4 font-normal">名前</th>
                    <th class="py-2 pr-4 font-normal">目標</th>
                    <th class="py-2 pr-4 font-normal">周</th>
                    <th class="py-2 pr-4 font-normal">今の段階</th>
                    <th class="py-2 font-normal">状態</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->mechanisms as $mechanism)
                    <tr wire:key="mechanism-{{ $mechanism->id }}" class="border-b border-[#19140015] align-top dark:border-[#3E3E3A]">
                        <td class="py-2 pr-4">
                            <a href="{{ route('mechanisms.show', $mechanism) }}" wire:navigate class="font-medium hover:underline">{{ $mechanism->title }}</a>
                        </td>
                        <td class="py-2 pr-4">{{ Str::limit($mechanism->goal, 60) }}</td>
                        <td class="py-2 pr-4">{{ $mechanism->current_round }} 周目</td>
                        <td class="py-2 pr-4">
                            @if ($mechanism->latestRound)
                                {{ $mechanism->latestRound->phaseLabel() }}（{{ $mechanism->latestRound->phaseStatusLabel() }}）
                            @else
                                —
                            @endif
                        </td>
                        <td class="py-2">{{ $mechanism->statusLabel() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $this->mechanisms->links() }}
    @endif
</div>
