<?php

use App\Actions\Productions\CreateProduction;
use App\Models\KnowledgeNode;
use App\Models\VideoProduction;
use App\Services\Productions\ProductionTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('制作を作る')] class extends Component
{
    /** 元の設計判断（知識の画面から来たときは選ばれた状態で開く）。 */
    #[Url(as: 'decision')]
    public ?int $decisionId = null;

    // 1. ジャンル
    public string $genre = '';

    // 2. 場面の部品の構成（ジャンルを選ぶと初期値が入る。選び直した後はジャンルを変えても上書きしない）
    public string $structure = '';

    public bool $structureChosen = false;

    // 3. 設計判断での調整
    public string $audience = 'beginner';

    public int $duration = 60;

    public string $tone = 'calm';

    public string $ratio = '1280:720';

    public string $title = '';

    public string $brief = '';

    public function mount(): void
    {
        $this->fillFromDecision();
    }

    public function updatedDecisionId(): void
    {
        $this->title = '';
        $this->brief = '';
        $this->fillFromDecision();
    }

    public function updatedGenre(): void
    {
        if (! $this->structureChosen) {
            $this->structure = ProductionTemplate::DEFAULT_STRUCTURE_FOR_GENRE[$this->genre] ?? '';
        }
    }

    public function updatedStructure(): void
    {
        $this->structureChosen = true;
    }

    public function create(CreateProduction $create): void
    {
        $validated = $this->validate([
            'decisionId' => ['required', Rule::exists('knowledge_nodes', 'id')->where('node_type', KnowledgeNode::TYPE_DECISION)],
            'genre' => ['required', Rule::in(array_keys(VideoProduction::GENRES))],
            'structure' => ['required', Rule::in(array_keys(ProductionTemplate::STRUCTURES))],
            'audience' => ['required', Rule::in(array_keys(ProductionTemplate::AUDIENCES))],
            'duration' => ['required', 'integer', Rule::in(array_keys(ProductionTemplate::DURATIONS))],
            'tone' => ['required', Rule::in(array_keys(ProductionTemplate::TONES))],
            'ratio' => ['required', Rule::in(VideoProduction::RATIOS)],
            'title' => ['required', 'string', 'max:255'],
            'brief' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'decisionId' => '設計判断',
            'genre' => 'ジャンル',
            'structure' => '場面の部品の構成',
            'audience' => '対象者',
            'duration' => '長さ',
            'tone' => '口調',
            'ratio' => '画面の比率',
            'title' => 'タイトル',
            'brief' => '伝えたいこと',
        ]);

        $production = $create->handle(KnowledgeNode::findOrFail($validated['decisionId']), [
            ...$validated,
            'brief' => $validated['brief'] ?: null,
        ], auth()->user());

        session()->flash('status', '制作を作りました。場面の静止画で見た目を確認できます（台本は仮の文です）。');
        $this->redirectRoute('productions.show', $production, navigate: true);
    }

    /**
     * 制作の元にできる設計判断（廃止を除く）。
     *
     * @return Collection<int, KnowledgeNode>
     */
    #[Computed]
    public function decisions(): Collection
    {
        return KnowledgeNode::query()
            ->where('node_type', KnowledgeNode::TYPE_DECISION)
            ->where('status', '!=', KnowledgeNode::STATUS_DEPRECATED)
            ->latest('id')
            ->get(['id', 'title', 'description', 'status']);
    }

    /**
     * 選んだ構成と長さで作られる場面の一覧（確認用）。
     *
     * @return list<array{type: string, title: string, seconds: float}>
     */
    #[Computed]
    public function preview(): array
    {
        if (! isset(ProductionTemplate::STRUCTURES[$this->structure], ProductionTemplate::DURATIONS[$this->duration])) {
            return [];
        }

        $durations = ProductionTemplate::sceneDurations($this->structure, $this->duration);

        return array_map(fn (array $spec, int $i) => ['type' => $spec[0], 'title' => $spec[1], 'seconds' => $durations[$i]],
            ProductionTemplate::STRUCTURES[$this->structure]['scenes'], array_keys($durations));
    }

    /**
     * タイトルと伝えたいことが空なら、設計判断の名称と説明を初期値にする。
     */
    private function fillFromDecision(): void
    {
        $decision = $this->decisionId ? $this->decisions->firstWhere('id', $this->decisionId) : null;

        if ($decision === null) {
            return;
        }

        $this->title = $this->title !== '' ? $this->title : $decision->title;
        $this->brief = $this->brief !== '' ? $this->brief : (string) $decision->description;
    }
};
?>

@php
    $muted = 'text-[#706f6c] dark:text-[#A1A09A]';
    $card = 'space-y-3 rounded-sm border border-[#19140035] p-4 dark:border-[#3E3E3A]';
    $select = 'w-full rounded-sm border border-[#19140035] bg-white px-3 py-2 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]';
    $label = 'block space-y-1 text-sm';
@endphp

<div class="max-w-3xl space-y-6">
    <div>
        <a href="{{ route('productions.index') }}" wire:navigate class="text-sm {{ $muted }} hover:underline">← 制作一覧</a>
    </div>

    <h1 class="text-xl font-semibold">制作を作る</h1>

    <form wire:submit="create" class="space-y-6">
        <section class="{{ $card }}">
            <h2 class="font-semibold">1. 動画のジャンル</h2>
            <label class="{{ $label }}">
                <span class="{{ $muted }}">ジャンル</span>
                <select wire:model.live="genre" class="{{ $select }}">
                    <option value="">選んでください</option>
                    @foreach (VideoProduction::GENRES as $key => $name)
                        <option wire:key="genre-{{ $key }}" value="{{ $key }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            @error('genre') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        </section>

        <section class="{{ $card }}">
            <h2 class="font-semibold">2. 場面の部品の構成</h2>
            <label class="{{ $label }}">
                <span class="{{ $muted }}">構成の型（ジャンルを選ぶと初期値が入ります）</span>
                <select wire:model.live="structure" class="{{ $select }}">
                    <option value="">選んでください</option>
                    @foreach (ProductionTemplate::STRUCTURES as $key => $definition)
                        <option wire:key="structure-{{ $key }}" value="{{ $key }}">
                            {{ $definition['label'] }}：{{ collect($definition['scenes'])->pluck(1)->implode(' → ') }}
                        </option>
                    @endforeach
                </select>
            </label>
            @error('structure') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            @if ($this->preview !== [])
                <ol class="space-y-1 text-sm">
                    @foreach ($this->preview as $scene)
                        <li wire:key="preview-{{ $loop->index }}" class="flex gap-3">
                            <span class="w-8 {{ $muted }}">s{{ $loop->iteration }}</span>
                            <span class="w-24">{{ $scene['title'] }}</span>
                            <span class="w-36 {{ $muted }}">{{ $scene['type'] }}</span>
                            <span>{{ rtrim(rtrim(number_format($scene['seconds'], 1), '0'), '.') }} 秒</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <section class="{{ $card }}">
            <h2 class="font-semibold">3. 設計判断での調整</h2>

            <label class="{{ $label }}">
                <span class="{{ $muted }}">設計判断</span>
                <select wire:model.live="decisionId" class="{{ $select }}">
                    <option value="">選んでください</option>
                    @foreach ($this->decisions as $decision)
                        <option wire:key="decision-{{ $decision->id }}" value="{{ $decision->id }}">{{ $decision->title }}</option>
                    @endforeach
                </select>
            </label>
            @error('decisionId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            @if ($this->decisions->isEmpty())
                <p class="text-xs {{ $muted }}">設計判断がまだありません。知識の概念の画面の「この概念から設計判断を作る」で作れます。</p>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="{{ $label }}">
                    <span class="{{ $muted }}">対象者</span>
                    <select wire:model="audience" class="{{ $select }}">
                        @foreach (ProductionTemplate::AUDIENCES as $key => $name)
                            <option wire:key="audience-{{ $key }}" value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="{{ $label }}">
                    <span class="{{ $muted }}">長さ</span>
                    <select wire:model.live="duration" class="{{ $select }}">
                        @foreach (ProductionTemplate::DURATIONS as $seconds => $name)
                            <option wire:key="duration-{{ $seconds }}" value="{{ $seconds }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="{{ $label }}">
                    <span class="{{ $muted }}">口調</span>
                    <select wire:model="tone" class="{{ $select }}">
                        @foreach (ProductionTemplate::TONES as $key => $name)
                            <option wire:key="tone-{{ $key }}" value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="{{ $label }}">
                    <span class="{{ $muted }}">画面の比率</span>
                    <select wire:model="ratio" class="{{ $select }}">
                        @foreach (ProductionTemplate::RATIO_LABELS as $key => $name)
                            <option wire:key="ratio-{{ $key }}" value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            @foreach (['audience', 'duration', 'tone', 'ratio'] as $field)
                @error($field) <p wire:key="error-{{ $field }}" class="text-xs text-red-600">{{ $message }}</p> @enderror
            @endforeach

            <label class="{{ $label }}">
                <span class="{{ $muted }}">タイトル（設計判断の名称が初期値）</span>
                <input type="text" wire:model="title" class="{{ $select }}">
            </label>
            @error('title') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            <label class="{{ $label }}">
                <span class="{{ $muted }}">伝えたいこと（設計判断の説明が初期値。文ごとに箇条書きの仮の項目になります）</span>
                <textarea wire:model="brief" rows="4" class="{{ $select }}"></textarea>
            </label>
            @error('brief') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
        </section>

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled" class="rounded-sm bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]">制作を作る</button>
            <p class="text-xs {{ $muted }}">制作案（候補・第1版）と場面を作ります。台本（字幕・ナレーション）は次の段階で選びます。</p>
        </div>
    </form>
</div>
