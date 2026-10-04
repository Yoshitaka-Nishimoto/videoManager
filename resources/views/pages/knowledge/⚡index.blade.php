<?php

use App\Models\KnowledgeNode;
use App\Services\Knowledge\KnowledgeCurator;
use App\Services\Knowledge\KnowledgeRuleViolation;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('知識')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = KnowledgeNode::STATUS_CANDIDATE;

    #[Url]
    public string $type = '';

    #[Url]
    public string $q = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'type', 'q'], true)) {
            $this->resetPage();
        }
    }

    public function confirm(KnowledgeNode $node, KnowledgeCurator $curator): void
    {
        $this->run(fn () => $curator->confirmNode($node, auth()->user(), '一覧から採用'));
    }

    public function deprecate(KnowledgeNode $node, KnowledgeCurator $curator): void
    {
        $this->run(fn () => $curator->deprecateNode($node, auth()->user(), '一覧から廃止'));
    }

    /**
     * @return LengthAwarePaginator<int, KnowledgeNode>
     */
    #[Computed]
    public function nodes(): LengthAwarePaginator
    {
        return KnowledgeNode::query()
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->type !== '', fn ($query) => $query->where('node_type', $this->type))
            ->when($this->q !== '', fn ($query) => $query->whereLike('title', '%'.$this->q.'%'))
            ->withCount(['outgoingEdges', 'incomingEdges', 'sources'])
            ->orderByRaw("case status when 'candidate' then 0 when 'confirmed' then 1 else 2 end")
            ->latest('id')
            ->paginate(20);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        return KnowledgeNode::query()
            ->when($this->type !== '', fn ($query) => $query->where('node_type', $this->type))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    private function run(callable $action): void
    {
        try {
            $action();
        } catch (KnowledgeRuleViolation $e) {
            $this->addError('action', $e->getMessage());
        }

        unset($this->nodes, $this->statusCounts);
    }
};
?>

<div class="space-y-6">
    <h1 class="text-xl font-semibold">知識</h1>

    <div class="flex flex-wrap items-center gap-2 text-sm">
        @foreach (['candidate' => '候補', 'confirmed' => '確認済み', 'deprecated' => '廃止', '' => 'すべて'] as $value => $label)
            <button
                type="button"
                wire:key="status-{{ $value ?: 'all' }}"
                wire:click="$set('status', '{{ $value }}')"
                @class([
                    'rounded-full border px-3 py-1',
                    'border-[#1b1b18] bg-[#1b1b18] text-white dark:border-[#EDEDEC] dark:bg-[#EDEDEC] dark:text-[#1b1b18]' => $status === $value,
                    'border-[#19140035] dark:border-[#3E3E3A]' => $status !== $value,
                ])
            >
                {{ $label }}
                @if ($value !== '')
                    <span class="ml-1 text-xs opacity-70">{{ $this->statusCounts[$value] ?? 0 }}</span>
                @endif
            </button>
        @endforeach

        <select wire:model.live="type" aria-label="ノードの種類" class="ml-auto rounded-sm border border-[#19140035] bg-white px-2 py-1 dark:border-[#3E3E3A] dark:bg-[#161615]">
            <option value="">すべての種類</option>
            @foreach (KnowledgeNode::typeLabels() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <input
            type="search"
            wire:model.live.debounce.300ms="q"
            placeholder="名称で検索"
            aria-label="名称で検索"
            class="w-full rounded-sm border border-[#19140035] bg-white px-3 py-1 sm:w-56 dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
    </div>

    @error('action')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @if ($this->nodes->isEmpty())
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">該当するノードはありません。</p>
    @else
        <ul class="divide-y divide-[#19140015] border-y border-[#19140015] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]">
            @foreach ($this->nodes as $node)
                <li wire:key="node-{{ $node->id }}" class="flex flex-wrap items-center gap-3 py-3 text-sm">
                    <span class="w-16 shrink-0 text-xs text-[#706f6c] dark:text-[#A1A09A]">{{ KnowledgeNode::typeLabelFor($node->node_type) }}</span>
                    <a href="{{ route('knowledge.show', $node) }}" wire:navigate class="min-w-0 flex-1 truncate font-medium hover:underline">{{ $node->title }}</a>
                    <x-knowledge.status-badge :status="$node->status" />
                    <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">関係 {{ $node->outgoing_edges_count + $node->incoming_edges_count }} · 出典 {{ $node->sources_count }}</span>
                    @if ($node->status === KnowledgeNode::STATUS_CANDIDATE)
                        <span class="flex gap-2">
                            <button type="button" wire:click="confirm({{ $node->id }})" class="rounded-sm border border-green-700 px-2 py-0.5 text-xs text-green-800 hover:bg-green-50 dark:text-green-300 dark:hover:bg-green-950">採用</button>
                            <button type="button" wire:click="deprecate({{ $node->id }})" class="rounded-sm border border-[#19140035] px-2 py-0.5 text-xs hover:bg-[#f0f0ec] dark:border-[#3E3E3A] dark:hover:bg-[#1f1f1e]">廃止</button>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>

        {{ $this->nodes->links() }}
    @endif
</div>
