<?php

use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\KnowledgeRelationType;
use App\Models\KnowledgeRevision;
use App\Models\KnowledgeSource;
use App\Models\Video;
use App\Services\Knowledge\KnowledgeCurator;
use App\Services\Knowledge\KnowledgeRuleViolation;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('知識の詳細')] class extends Component
{
    public KnowledgeNode $node;

    /** 操作の理由（変更履歴に残す）。 */
    public string $reason = '';

    public bool $editing = false;

    public string $title = '';

    public string $description = '';

    public string $relationTypeId = '';

    public string $relationSearch = '';

    public string $relationTargetId = '';

    public string $mergeSearch = '';

    public string $mergeTargetId = '';

    public function confirm(KnowledgeCurator $curator): void
    {
        $this->run(fn () => $curator->confirmNode($this->node, auth()->user(), $this->reasonOrNull()));
    }

    public function deprecate(KnowledgeCurator $curator): void
    {
        $this->run(fn () => $curator->deprecateNode($this->node, auth()->user(), $this->reasonOrNull()));
    }

    public function edit(): void
    {
        $this->title = $this->node->title;
        $this->description = (string) $this->node->description;
        $this->editing = true;
    }

    public function save(KnowledgeCurator $curator): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
        ], attributes: ['title' => '名称', 'description' => '説明']);

        $this->run(function () use ($curator) {
            $curator->updateNode($this->node, $this->title, $this->description ?: null, auth()->user(), $this->reasonOrNull());
            $this->editing = false;
        });
    }

    public function confirmEdge(KnowledgeEdge $edge, KnowledgeCurator $curator): void
    {
        $this->ensureConnected($edge);
        $this->run(fn () => $curator->confirmEdge($edge, auth()->user(), $this->reasonOrNull()));
    }

    public function deprecateEdge(KnowledgeEdge $edge, KnowledgeCurator $curator): void
    {
        $this->ensureConnected($edge);
        $this->run(fn () => $curator->deprecateEdge($edge, auth()->user(), $this->reasonOrNull()));
    }

    public function addRelation(KnowledgeCurator $curator): void
    {
        $this->validate([
            'relationTypeId' => 'required|exists:knowledge_relation_types,id',
            'relationTargetId' => 'required|exists:knowledge_nodes,id',
        ], attributes: ['relationTypeId' => '関係の種類', 'relationTargetId' => '接続先']);

        $this->run(function () use ($curator) {
            $curator->addEdge(
                $this->node,
                KnowledgeRelationType::findOrFail($this->relationTypeId),
                KnowledgeNode::findOrFail($this->relationTargetId),
                auth()->user(),
                $this->reasonOrNull(),
            );
            $this->reset('relationTypeId', 'relationSearch', 'relationTargetId');
        });
    }

    public function merge(KnowledgeCurator $curator): void
    {
        $this->validate(['mergeTargetId' => 'required|exists:knowledge_nodes,id'], attributes: ['mergeTargetId' => '統合先']);

        $this->run(function () use ($curator) {
            $into = KnowledgeNode::findOrFail($this->mergeTargetId);
            $curator->mergeNode($this->node, $into, auth()->user(), $this->reasonOrNull());

            session()->flash('status', "「{$this->node->title}」を統合しました。");
            $this->redirectRoute('knowledge.show', $into, navigate: true);
        });
    }

    /**
     * 根拠：このノード自身の出典と、このノードから出る関係の出典。
     *
     * @return Collection<int, KnowledgeSource>
     */
    #[Computed]
    public function sources(): Collection
    {
        $edgeIds = $this->node->outgoingEdges()->pluck('id');

        return KnowledgeSource::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('sourceable_type', $this->node->getMorphClass())->where('sourceable_id', $this->node->id))
                ->orWhere(fn ($q) => $q->where('sourceable_type', (new KnowledgeEdge)->getMorphClass())->whereIn('sourceable_id', $edgeIds)))
            ->with(['video', 'videoAnalysis', 'conversationMessage.conversation'])
            ->latest('id')
            ->get();
    }

    /**
     * 関係：このノードから出る関係と、このノードへ入る関係。
     *
     * @return Collection<int, KnowledgeEdge>
     */
    #[Computed]
    public function edges(): Collection
    {
        return KnowledgeEdge::query()
            ->where('source_node_id', $this->node->id)
            ->orWhere('target_node_id', $this->node->id)
            ->with(['sourceNode', 'targetNode', 'relationType'])
            ->orderByRaw("case status when 'candidate' then 0 when 'confirmed' then 1 else 2 end")
            ->orderByDesc('confidence')
            ->get();
    }

    /**
     * @return Collection<int, KnowledgeRelationType>
     */
    #[Computed]
    public function relationTypes(): Collection
    {
        return KnowledgeRelationType::query()->orderBy('id')->get()
            ->filter(fn (KnowledgeRelationType $type) => in_array($this->node->node_type, $type->allowed_source_types ?? [$this->node->node_type], true))
            ->values();
    }

    /**
     * @return Collection<int, KnowledgeNode>
     */
    #[Computed]
    public function relationCandidates(): Collection
    {
        return $this->searchNodes($this->relationSearch);
    }

    /**
     * @return Collection<int, KnowledgeNode>
     */
    #[Computed]
    public function mergeCandidates(): Collection
    {
        return $this->searchNodes($this->mergeSearch, sameType: true);
    }

    /**
     * @return Collection<int, KnowledgeRevision>
     */
    #[Computed]
    public function revisions(): Collection
    {
        return $this->node->revisions()->with('changedBy')->latest('id')->limit(20)->get();
    }

    #[Computed]
    public function canMerge(): bool
    {
        return in_array($this->node->node_type, [KnowledgeNode::TYPE_CONCEPT, KnowledgeNode::TYPE_DECISION, KnowledgeNode::TYPE_IMPLEMENTATION], true)
            && $this->node->status !== KnowledgeNode::STATUS_DEPRECATED;
    }

    /**
     * @return Collection<int, KnowledgeNode>
     */
    private function searchNodes(string $search, bool $sameType = false): Collection
    {
        if (mb_strlen(trim($search)) < 1) {
            return new Collection;
        }

        return KnowledgeNode::query()
            ->whereKeyNot($this->node->id)
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->when($sameType, fn ($query) => $query->where('node_type', $this->node->node_type))
            ->whereLike('title', '%'.trim($search).'%')
            ->orderBy('title')
            ->limit(8)
            ->get();
    }

    private function ensureConnected(KnowledgeEdge $edge): void
    {
        abort_unless(in_array($this->node->id, [$edge->source_node_id, $edge->target_node_id], true), 404);
    }

    private function reasonOrNull(): ?string
    {
        return trim($this->reason) === '' ? null : trim($this->reason);
    }

    private function run(callable $action): void
    {
        try {
            $action();
            $this->reason = '';
        } catch (KnowledgeRuleViolation $e) {
            $this->addError('action', $e->getMessage());
        }

        $this->node->refresh();
        unset($this->sources, $this->edges, $this->revisions, $this->canMerge);
    }
};
?>

@php
    $muted = 'text-[#706f6c] dark:text-[#A1A09A]';
    $card = 'rounded-sm border border-[#19140035] p-4 dark:border-[#3E3E3A]';
    $input = 'w-full rounded-sm border border-[#19140035] bg-white px-3 py-1.5 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]';
    $button = 'rounded-sm border border-[#19140035] px-3 py-1 text-sm hover:bg-[#f0f0ec] disabled:opacity-50 dark:border-[#3E3E3A] dark:hover:bg-[#1f1f1e]';
@endphp

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <a href="{{ route('knowledge.index') }}" wire:navigate class="text-sm {{ $muted }} hover:underline">← 知識一覧</a>
    </div>

    @if (session('status'))
        <p class="rounded-sm bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">{{ session('status') }}</p>
    @endif
    @error('action')
        <p class="rounded-sm bg-red-50 px-4 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-200">{{ $message }}</p>
    @enderror

    <div class="grid gap-6 lg:grid-cols-[1fr_1.4fr_1fr]">
        {{-- 左側：根拠 --}}
        <section class="space-y-3 lg:order-1">
            <h2 class="font-semibold">根拠</h2>
            @forelse ($this->sources as $source)
                <div wire:key="source-{{ $source->id }}" class="{{ $card }} space-y-1 text-sm">
                    @if ($source->video)
                        <a href="{{ route('videos.show', $source->video) }}" wire:navigate class="line-clamp-2 font-medium hover:underline">{{ $source->video->title }}</a>
                    @endif
                    @if ($source->videoAnalysis)
                        <p class="text-xs {{ $muted }}">分析 第{{ $source->videoAnalysis->version }}版</p>
                    @endif
                    @if ($source->start_seconds !== null && $source->video)
                        <a href="{{ $source->video->urlAt($source->start_seconds) }}" target="_blank" rel="noopener" class="font-mono text-xs text-blue-700 hover:underline dark:text-blue-400">
                            {{ Video::formatSeconds($source->start_seconds) }}〜{{ Video::formatSeconds($source->end_seconds) }}
                        </a>
                    @endif
                    @if ($source->conversationMessage)
                        <p class="text-xs {{ $muted }}">会話「{{ $source->conversationMessage->conversation?->title }}」より</p>
                    @endif
                    @if ($source->excerpt)
                        <p class="text-xs">{{ $source->excerpt }}</p>
                    @endif
                </div>
            @empty
                <p class="text-sm {{ $muted }}">根拠はまだ記録されていません。</p>
            @endforelse
        </section>

        {{-- 中央：選んだノード --}}
        <section class="space-y-4 lg:order-2">
            <div class="{{ $card }} space-y-3">
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="{{ $muted }}">{{ KnowledgeNode::typeLabelFor($node->node_type) }}</span>
                    <x-knowledge.status-badge :status="$node->status" />
                    <span class="{{ $muted }}">提案：{{ $node->proposed_by === KnowledgeNode::PROPOSED_BY_AI ? 'AI' : '人' }}</span>
                </div>

                @if ($editing)
                    <form wire:submit="save" class="space-y-2">
                        <input type="text" wire:model="title" aria-label="名称" class="{{ $input }}">
                        @error('title') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <textarea wire:model="description" rows="4" aria-label="説明" class="{{ $input }}"></textarea>
                        @error('description') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <div class="flex gap-2">
                            <button type="submit" class="{{ $button }}">保存</button>
                            <button type="button" wire:click="$set('editing', false)" class="{{ $button }}">やめる</button>
                        </div>
                    </form>
                @else
                    <h1 class="text-xl font-semibold">{{ $node->title }}</h1>
                    @if ($node->description)
                        <p class="text-sm leading-relaxed">{{ $node->description }}</p>
                    @endif
                @endif

                @if ($node->mergedInto)
                    <p class="text-sm">統合先：<a href="{{ route('knowledge.show', $node->mergedInto) }}" wire:navigate class="underline">{{ $node->mergedInto->title }}</a></p>
                @endif
                @if ($node->video)
                    <p class="text-sm"><a href="{{ route('videos.show', $node->video) }}" wire:navigate class="underline">動画の詳細を開く</a></p>
                @endif

                <div class="space-y-2 border-t border-[#19140015] pt-3 dark:border-[#3E3E3A]">
                    <input type="text" wire:model="reason" placeholder="理由（任意・変更履歴に残ります）" aria-label="理由" class="{{ $input }}">
                    <div class="flex flex-wrap gap-2">
                        @if ($node->status !== KnowledgeNode::STATUS_CONFIRMED && $node->status !== KnowledgeNode::STATUS_DEPRECATED)
                            <button type="button" wire:click="confirm" class="rounded-sm border border-green-700 px-3 py-1 text-sm text-green-800 hover:bg-green-50 dark:text-green-300 dark:hover:bg-green-950">採用</button>
                        @endif
                        @if ($node->status !== KnowledgeNode::STATUS_DEPRECATED)
                            <button type="button" wire:click="edit" class="{{ $button }}">修正</button>
                            <button type="button" wire:click="deprecate" wire:confirm="このノードを廃止しますか？" class="{{ $button }}">廃止</button>
                        @endif
                    </div>
                </div>
            </div>

            @if ($node->status !== KnowledgeNode::STATUS_DEPRECATED)
                <details class="{{ $card }}">
                    <summary class="cursor-pointer text-sm font-semibold">関係を追加</summary>
                    <form wire:submit="addRelation" class="mt-3 space-y-2 text-sm">
                        <p class="{{ $muted }}">「{{ $node->title }}」→ 関係 → 接続先</p>
                        <select wire:model="relationTypeId" aria-label="関係の種類" class="{{ $input }}">
                            <option value="">関係の種類を選ぶ</option>
                            @foreach ($this->relationTypes as $type)
                                <option wire:key="type-{{ $type->id }}" value="{{ $type->id }}">{{ $type->label }}</option>
                            @endforeach
                        </select>
                        @error('relationTypeId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <input type="search" wire:model.live.debounce.300ms="relationSearch" placeholder="接続先のノードを名称で検索" aria-label="接続先を検索" class="{{ $input }}">
                        @foreach ($this->relationCandidates as $candidate)
                            <label wire:key="relation-candidate-{{ $candidate->id }}" class="flex items-center gap-2">
                                <input type="radio" wire:model="relationTargetId" value="{{ $candidate->id }}">
                                <span class="text-xs {{ $muted }}">{{ KnowledgeNode::typeLabelFor($candidate->node_type) }}</span>
                                {{ $candidate->title }}
                            </label>
                        @endforeach
                        @error('relationTargetId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="{{ $button }}">追加</button>
                    </form>
                </details>
            @endif

            @if ($this->canMerge)
                <details class="{{ $card }}">
                    <summary class="cursor-pointer text-sm font-semibold">別のノードに統合</summary>
                    <form wire:submit="merge" class="mt-3 space-y-2 text-sm">
                        <p class="{{ $muted }}">このノードの関係と根拠を統合先に移し、このノードは廃止します。</p>
                        <input type="search" wire:model.live.debounce.300ms="mergeSearch" placeholder="統合先を名称で検索（同じ種類のみ）" aria-label="統合先を検索" class="{{ $input }}">
                        @foreach ($this->mergeCandidates as $candidate)
                            <label wire:key="merge-candidate-{{ $candidate->id }}" class="flex items-center gap-2">
                                <input type="radio" wire:model="mergeTargetId" value="{{ $candidate->id }}">
                                {{ $candidate->title }}
                                <x-knowledge.status-badge :status="$candidate->status" />
                            </label>
                        @endforeach
                        @error('mergeTargetId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" wire:confirm="統合すると元に戻せません。統合しますか？" class="{{ $button }}">統合</button>
                    </form>
                </details>
            @endif

            <section class="{{ $card }} space-y-2">
                <h2 class="text-sm font-semibold">変更履歴</h2>
                @forelse ($this->revisions as $revision)
                    <p wire:key="revision-{{ $revision->id }}" class="text-xs">
                        <span class="{{ $muted }}">{{ $revision->created_at?->format('Y-m-d H:i') }}</span>
                        {{ $revision->changedBy?->name ?? 'AI' }}：{{ $revision->actionLabel() }}{{ $revision->reason ? "（{$revision->reason}）" : '' }}
                    </p>
                @empty
                    <p class="text-xs {{ $muted }}">履歴はありません。</p>
                @endforelse
            </section>
        </section>

        {{-- 右側：関連するノード --}}
        <section class="space-y-3 lg:order-3">
            <h2 class="font-semibold">関係</h2>
            @forelse ($this->edges as $edge)
                @php($outgoing = $edge->source_node_id === $node->id)
                @php($other = $outgoing ? $edge->targetNode : $edge->sourceNode)
                <div wire:key="edge-{{ $edge->id }}" class="{{ $card }} space-y-1 text-sm">
                    <p class="text-xs {{ $muted }}">{{ $outgoing ? $edge->relationType->label : $edge->relationType->inverse_label }}</p>
                    <a href="{{ route('knowledge.show', $other) }}" wire:navigate class="block font-medium hover:underline">
                        <span class="text-xs {{ $muted }}">{{ KnowledgeNode::typeLabelFor($other->node_type) }}</span>
                        {{ $other->title }}
                    </a>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-knowledge.status-badge :status="$edge->status" />
                        @if ($edge->confidence !== null)
                            <span class="text-xs {{ $muted }}">確信度 {{ number_format((float) $edge->confidence * 100) }}%</span>
                        @endif
                        @if ($edge->status === KnowledgeNode::STATUS_CANDIDATE)
                            <button type="button" wire:click="confirmEdge({{ $edge->id }})" class="rounded-sm border border-green-700 px-2 py-0.5 text-xs text-green-800 hover:bg-green-50 dark:text-green-300 dark:hover:bg-green-950">採用</button>
                        @endif
                        @if ($edge->status !== KnowledgeNode::STATUS_DEPRECATED)
                            <button type="button" wire:click="deprecateEdge({{ $edge->id }})" class="rounded-sm border border-[#19140035] px-2 py-0.5 text-xs hover:bg-[#f0f0ec] dark:border-[#3E3E3A] dark:hover:bg-[#1f1f1e]">廃止</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm {{ $muted }}">関係はまだありません。</p>
            @endforelse
        </section>
    </div>
</div>
