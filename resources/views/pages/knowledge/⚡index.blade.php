<?php

use App\Models\KnowledgeNode;
use App\Models\KnowledgeSource;
use App\Models\Video;
use App\Models\VideoProduction;
use App\Services\Knowledge\KnowledgeCurator;
use App\Services\Knowledge\KnowledgeRuleViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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

    /** ジャンル（VideoProduction::GENRES のキー）。出典の動画・制作のジャンルで絞る（whereInGenre）。 */
    #[Url]
    public string $genre = '';

    #[Url]
    public string $q = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'type', 'genre', 'q'], true)) {
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
        $codeLinkMatches = fn ($query) => $query
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->where(fn ($q) => $q->whereLike('path', '%'.trim($this->q).'%')->orWhere('graphify_node_id', trim($this->q)));

        $nodes = KnowledgeNode::query()
            ->when($this->status !== '' && ! $this->searchingCode(), fn ($query) => $query->where('status', $this->status))
            ->when($this->type !== '', fn ($query) => $query->where('node_type', $this->type))
            ->when($this->genre !== '', fn ($query) => $this->whereInGenre($query))
            ->when(trim($this->q) !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereLike('title', '%'.trim($this->q).'%')
                ->orWhereHas('codeLinks', $codeLinkMatches)))
            ->when(trim($this->q) !== '', fn ($query) => $query->with(['codeLinks' => $codeLinkMatches]))
            ->withCount(['outgoingEdges', 'incomingEdges', 'sources'])
            ->orderByRaw("case status when 'candidate' then 0 when 'confirmed' then 1 else 2 end")
            ->latest('id')
            ->paginate(20);

        // 概念は出どころの動画を示すため、自身と抽出元の関係（廃止を除く）の出典を動画付きで読み込む。
        $withVideo = fn ($query) => $query->whereNotNull('video_id')->with('video');
        $nodes->getCollection()
            ->where('node_type', KnowledgeNode::TYPE_CONCEPT)
            ->load([
                'sources' => $withVideo,
                'outgoingEdges' => fn ($query) => $query
                    ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
                    ->with(['sources' => $withVideo]),
            ]);

        return $nodes;
    }

    /**
     * 概念の出どころの動画。同じ動画の出典は最初の一つだけ。
     *
     * @return Collection<int, KnowledgeSource>
     */
    public function videoSourcesFor(KnowledgeNode $node): Collection
    {
        return $node->sources
            ->concat($node->outgoingEdges->flatMap->sources)
            ->filter(fn (KnowledgeSource $source) => $source->video !== null)
            ->sortBy('id')
            ->unique('video_id')
            ->values();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function statusCounts(): array
    {
        return KnowledgeNode::query()
            ->when($this->type !== '', fn ($query) => $query->where('node_type', $this->type))
            ->when($this->genre !== '', fn ($query) => $this->whereInGenre($query))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /**
     * 選んだジャンルの知識に絞る。動画・分析ノードはその動画、概念などは出典（自身と廃止していない関係）の動画、
     * 設計判断は作った制作のジャンルで判定する。
     *
     * @param  Builder<KnowledgeNode>  $query
     */
    private function whereInGenre(Builder $query): void
    {
        $inGenre = fn ($q) => $q->where('genre', $this->genre);

        $query->where(fn ($q) => $q
            ->whereHas('video', $inGenre)
            ->orWhereHas('videoAnalysis.video', $inGenre)
            ->orWhereHas('sources.video', $inGenre)
            ->orWhereHas('outgoingEdges', fn ($edges) => $edges
                ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
                ->whereHas('sources.video', $inGenre))
            ->orWhereHas('productions', $inGenre));
    }

    /**
     * ファイルのパス（graph.html の Source など）で探しているか。実装ノードは確認済みが多いので、状態で絞らずに探す。
     */
    public function searchingCode(): bool
    {
        return str_contains(trim($this->q), '/');
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
        <select wire:model.live="genre" aria-label="ジャンル" class="ml-auto rounded-sm border border-[#19140035] bg-white px-2 py-1 dark:border-[#3E3E3A] dark:bg-[#161615]">
            <option value="">すべてのジャンル</option>
            @foreach (VideoProduction::GENRES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="type" aria-label="ノードの種類" class="rounded-sm border border-[#19140035] bg-white px-2 py-1 dark:border-[#3E3E3A] dark:bg-[#161615]">
            <option value="">すべての種類</option>
            @foreach (KnowledgeNode::typeLabels() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <input
            type="search"
            wire:model.live.debounce.300ms="q"
            placeholder="名称・ファイルのパスで検索"
            aria-label="名称・ファイルのパスで検索"
            class="w-full rounded-sm border border-[#19140035] bg-white px-3 py-1 sm:w-56 dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
    </div>

    @error('action')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @if ($this->searchingCode())
        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">ファイルのパスで検索しているため、状態にかかわらず表示しています。</p>
    @endif

    @if ($this->nodes->isEmpty())
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">該当するノードはありません。</p>
    @else
        <ul class="divide-y divide-[#19140015] border-y border-[#19140015] dark:divide-[#3E3E3A] dark:border-[#3E3E3A]">
            @foreach ($this->nodes as $node)
                <li wire:key="node-{{ $node->id }}" class="space-y-1 py-3 text-sm">
                    {{-- 1 行目は折り返さない。名称だけが縮み、状態・件数・操作は常に右端にそろえる。 --}}
                    <div class="flex items-center gap-3">
                        <span class="w-16 shrink-0 text-xs text-[#706f6c] dark:text-[#A1A09A]">{{ KnowledgeNode::typeLabelFor($node->node_type) }}</span>
                        <a href="{{ route('knowledge.show', $node) }}" wire:navigate class="min-w-0 flex-1 truncate font-medium hover:underline">{{ $node->title }}</a>
                        <span class="flex shrink-0 items-center gap-3">
                            <x-knowledge.status-badge :status="$node->status" />
                            <span class="whitespace-nowrap text-xs text-[#706f6c] dark:text-[#A1A09A]">関係 {{ $node->outgoing_edges_count + $node->incoming_edges_count }} · 出典 {{ $node->sources_count }}</span>
                            @if ($node->status === KnowledgeNode::STATUS_CANDIDATE)
                                <span class="flex gap-2">
                                    <button type="button" wire:click="confirm({{ $node->id }})" class="whitespace-nowrap rounded-sm border border-green-700 px-2 py-0.5 text-xs text-green-800 hover:bg-green-50 dark:text-green-300 dark:hover:bg-green-950">採用</button>
                                    <button type="button" wire:click="deprecate({{ $node->id }})" class="whitespace-nowrap rounded-sm border border-[#19140035] px-2 py-0.5 text-xs hover:bg-[#f0f0ec] dark:border-[#3E3E3A] dark:hover:bg-[#1f1f1e]">廃止</button>
                                </span>
                            @endif
                        </span>
                    </div>
                    @if ($node->relationLoaded('codeLinks') && $node->codeLinks->isNotEmpty())
                        <div class="pl-[4.75rem] font-mono text-xs text-[#706f6c] dark:text-[#A1A09A]">
                            @foreach ($node->codeLinks as $link)
                                <span wire:key="node-{{ $node->id }}-link-{{ $link->id }}" class="block break-all">コード：{{ $link->path }}{{ $link->symbol ? " {$link->symbol}" : '' }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if ($node->node_type === KnowledgeNode::TYPE_CONCEPT)
                        <div class="space-y-1 pl-[4.75rem] text-xs">
                            @if ($node->description)
                                <p class="leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">{{ $node->description }}</p>
                            @endif
                            @foreach ($this->videoSourcesFor($node) as $source)
                                <p wire:key="node-{{ $node->id }}-source-{{ $source->id }}" class="flex flex-wrap items-baseline gap-x-2">
                                    <span class="text-[#706f6c] dark:text-[#A1A09A]">出典：</span>
                                    @if ($source->video->trashed())
                                        <span>{{ $source->video->title }} <span class="text-[#706f6c] dark:text-[#A1A09A]">（削除済み）</span></span>
                                    @else
                                        <a href="{{ route('videos.show', $source->video) }}" wire:navigate class="hover:underline">{{ $source->video->title }}</a>
                                    @endif
                                    @if ($source->start_seconds !== null)
                                        <a href="{{ $source->video->urlAt($source->start_seconds) }}" target="_blank" rel="noopener" class="font-mono text-blue-700 hover:underline dark:text-blue-400">
                                            {{ Video::formatSeconds($source->start_seconds) }}〜{{ Video::formatSeconds($source->end_seconds) }}
                                        </a>
                                    @endif
                                </p>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        {{ $this->nodes->links() }}
    @endif
</div>
