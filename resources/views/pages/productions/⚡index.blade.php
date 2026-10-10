<?php

use App\Models\VideoProduction;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('制作')] class extends Component
{
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, VideoProduction>
     */
    #[Computed]
    public function productions(): LengthAwarePaginator
    {
        return VideoProduction::query()
            ->with(['latestPlan', 'decisionNode'])
            ->withCount('renders')
            ->latest('id')
            ->paginate(20);
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-xl font-semibold">制作</h1>
        <a href="{{ route('productions.create') }}" wire:navigate class="rounded-sm bg-[#1b1b18] px-4 py-2 text-sm text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1b1b18]">制作を作る</a>
    </div>

    @if ($this->productions->isEmpty())
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">まだ制作がありません。「制作を作る」から、設計判断をもとに作れます。</p>
    @else
        <table class="w-full text-left text-sm">
            <thead class="border-b border-[#19140035] text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                <tr>
                    <th class="py-2 pr-4 font-normal">タイトル</th>
                    <th class="py-2 pr-4 font-normal">ジャンル</th>
                    <th class="py-2 pr-4 font-normal">設計判断</th>
                    <th class="py-2 pr-4 font-normal">比率</th>
                    <th class="py-2 pr-4 font-normal">最新の制作案</th>
                    <th class="py-2 font-normal">書き出し</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->productions as $production)
                    <tr wire:key="production-{{ $production->id }}" class="border-b border-[#19140015] align-top dark:border-[#3E3E3A]">
                        <td class="py-2 pr-4">
                            <a href="{{ route('productions.show', $production) }}" wire:navigate class="font-medium hover:underline">{{ $production->title }}</a>
                        </td>
                        <td class="py-2 pr-4">{{ $production->genreLabel() }}</td>
                        <td class="py-2 pr-4">{{ $production->decisionNode?->title ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $production->ratio }}</td>
                        <td class="py-2 pr-4">{{ $production->latestPlan ? "第{$production->latestPlan->version}版（{$production->latestPlan->statusLabel()}）" : '—' }}</td>
                        <td class="py-2">{{ $production->renders_count }} 件</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $this->productions->links() }}
    @endif
</div>
