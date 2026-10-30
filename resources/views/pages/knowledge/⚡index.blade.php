<?php

use App\Models\KnowledgeNode;
use App\Models\KnowledgeSource;
use App\Models\Video;
use App\Models\VideoProduction;
use App\Services\Knowledge\KnowledgeCurator;
use App\Services\Knowledge\KnowledgeEmbedder;
use App\Services\Knowledge\KnowledgeRuleViolation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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

    /** 意味で検索する（pgvector）。名称の文字が一致しなくても、意味の近いノードを類似度の高い順に出す。 */
    #[Url]
    public bool $semantic = false;

    /** 意味で検索するときに出す件数の上限と、類似度の下限（関連のない概念は 0.6 未満になることが多い）。 */
    public const SEMANTIC_LIMIT = 30;

    public const SEMANTIC_MIN_SIMILARITY = 0.6;

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'type', 'genre', 'q', 'semantic'], true)) {
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
        $nodes = $this->semanticVector !== null ? $this->similarNodes($this->semanticVector) : $this->matchingNodes();

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
     * 意味で検索するときの、検索語の埋め込み。意味で検索しないとき・埋め込みを作れなかったときは null（名称で検索する）。
     *
     * @return ?array<int, float>
     */
    #[Computed]
    public function semanticVector(): ?array
    {
        if (! $this->semantic || trim($this->q) === '' || $this->searchingCode() || ! app(KnowledgeEmbedder::class)->enabled()) {
            return null;
        }

        try {
            return app(KnowledgeEmbedder::class)->vectorFor(trim($this->q));
        } catch (Throwable $e) {
            Log::warning('意味で検索するための埋め込みを作れませんでした。', ['error' => $e->getMessage()]);
            $this->addError('semantic', '意味で検索できなかったため、名称で検索しています（'.$e->getMessage().'）。');

            return null;
        }
    }

    /**
     * 意味の近いノード（類似度の高い順、最大 SEMANTIC_LIMIT 件、1 ページ）。状態・種類・ジャンルの絞り込みは名称の検索と同じ。
     *
     * @param  array<int, float>  $vector
     * @return LengthAwarePaginator<int, KnowledgeNode>
     */
    private function similarNodes(array $vector): LengthAwarePaginator
    {
        $nodes = app(KnowledgeEmbedder::class)->similarTo($vector, self::SEMANTIC_MIN_SIMILARITY)
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->type !== '', fn ($query) => $query->where('node_type', $this->type))
            ->when($this->genre !== '', fn ($query) => $query->inGenre($this->genre))
            ->with('video')
            ->withCount(['outgoingEdges', 'incomingEdges', 'sources'])
            ->limit(self::SEMANTIC_LIMIT)
            ->get();

        return new LengthAwarePaginator($nodes, $nodes->count(), self::SEMANTIC_LIMIT, 1);
    }

    /**
     * @return LengthAwarePaginator<int, KnowledgeNode>
     */
    private function matchingNodes(): LengthAwarePaginator
    {
        $codeLinkMatches = fn ($query) => $query
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->where(fn ($q) => $q->whereLike('path', '%'.trim($this->q).'%')->orWhere('graphify_node_id', trim($this->q)));

        return KnowledgeNode::query()
            ->when($this->status !== '' && ! $this->searchingCode(), fn ($query) => $query->where('status', $this->status))
            ->when($this->type !== '', fn ($query) => $query->where('node_type', $this->type))
            ->when($this->genre !== '', fn ($query) => $query->inGenre($this->genre))
            ->when(trim($this->q) !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereLike('title', '%'.trim($this->q).'%')
                ->orWhereHas('codeLinks', $codeLinkMatches)))
            ->when(trim($this->q) !== '', fn ($query) => $query->with(['codeLinks' => $codeLinkMatches]))
            ->with('video')
            ->withCount(['outgoingEdges', 'incomingEdges', 'sources'])
            ->orderByRaw("case status when 'candidate' then 0 when 'confirmed' then 1 else 2 end")
            ->latest('id')
            ->paginate(20);
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
            ->when($this->genre !== '', fn ($query) => $query->inGenre($this->genre))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
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
        {{-- 意味で検索するときは、入力のたびに埋め込みの API を呼ばないよう、Enter かフォーカスを外したときに検索する。 --}}
        <input
            type="search"
            @if ($semantic) wire:model.change="q" @else wire:model.live.debounce.300ms="q" @endif
            placeholder="{{ $semantic ? '意味で検索（Enter で検索）' : '名称・ファイルのパスで検索' }}"
            aria-label="名称・ファイルのパスで検索"
            class="w-full rounded-sm border border-[#19140035] bg-white px-3 py-1 sm:w-56 dark:border-[#3E3E3A] dark:bg-[#161615]"
        >
        @if (app(KnowledgeEmbedder::class)->enabled())
            <label class="flex items-center gap-1 whitespace-nowrap text-xs">
                <input type="checkbox" wire:model.live="semantic" class="rounded-sm">
                意味で検索
            </label>
        @endif
    </div>

    @if ($semantic)
        <x-ai-thinking wire:loading.inline-flex wire:target="q, semantic" label="AI が意味の近いノードを探しています…" />
    @endif
    {{-- 埋め込みを先に作る（作れなかったときのエラーを、すぐ下に出すため）。 --}}
    @if ($this->semanticVector !== null)
        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">「{{ trim($q) }}」と意味の近いノードを、類似度の高い順に表示しています（類似度 {{ $this::SEMANTIC_MIN_SIMILARITY }} 以上、最大 {{ $this::SEMANTIC_LIMIT }} 件）。</p>
    @endif
    @error('semantic')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @if ($status === KnowledgeNode::STATUS_CONFIRMED)
        <livewire:knowledge.chat :genre="$genre" />
    @endif

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
                        <span class="w-16 shrink-0 text-xs text-[#706f6c] dark:text-[#A1A09A]">{{ $node->typeLabel() }}</span>
                        <a href="{{ route('knowledge.show', $node) }}" wire:navigate class="min-w-0 flex-1 truncate font-medium hover:underline">{{ $node->title }}</a>
                        <span class="flex shrink-0 items-center gap-3">
                            @isset($node->similarity)
                                <span class="whitespace-nowrap font-mono text-xs text-blue-700 dark:text-blue-400" title="意味の近さ（0〜1）">類似度 {{ number_format($node->similarity, 2) }}</span>
                            @endisset
                            @if ($node->isPersonal())
                                <span class="whitespace-nowrap rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">本人だけ</span>
                            @endif
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
