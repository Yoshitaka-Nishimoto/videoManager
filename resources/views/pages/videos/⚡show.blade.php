<?php

use App\Actions\Videos\AnalysisAlreadyRunningException;
use App\Actions\Videos\RequestVideoAnalysis;
use App\Models\KnowledgeEdge;
use App\Models\KnowledgeNode;
use App\Models\Video;
use App\Models\VideoAnalysis;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('動画詳細')] class extends Component
{
    public Video $video;

    public function analyze(RequestVideoAnalysis $request): void
    {
        try {
            $request->handle($this->video, auth()->user());
        } catch (AnalysisAlreadyRunningException $e) {
            $this->addError('analysis', $e->getMessage());
        }

        unset($this->analyses);
    }

    /**
     * @return Collection<int, VideoAnalysis>
     */
    #[Computed]
    public function analyses(): Collection
    {
        return $this->video->analyses()->with('requestedBy')->orderByDesc('version')->get();
    }

    #[Computed]
    public function isAnalyzing(): bool
    {
        return $this->analyses->contains(fn (VideoAnalysis $analysis) => $analysis->isInProgress());
    }

    #[Computed]
    public function latestCompleted(): ?VideoAnalysis
    {
        return $this->analyses->firstWhere('status', VideoAnalysis::STATUS_COMPLETED);
    }

    /**
     * 最新の分析から抽出された概念の候補（エッジ）と、その出典。
     *
     * @return Collection<int, KnowledgeEdge>
     */
    #[Computed]
    public function concepts(): Collection
    {
        $analysisNode = $this->latestCompleted?->knowledgeNode;

        if ($analysisNode === null) {
            return new Collection;
        }

        return $analysisNode->incomingEdges()
            ->whereRelation('relationType', 'key', 'extracted_from')
            ->with(['sourceNode', 'sources'])
            ->orderByDesc('confidence')
            ->get();
    }
};
?>

<div class="space-y-8" @if ($this->isAnalyzing) wire:poll.2s @endif>
    <div>
        <a href="{{ route('videos.index') }}" wire:navigate class="text-sm text-[#706f6c] hover:underline dark:text-[#A1A09A]">← 動画一覧</a>
    </div>

    @if (session('status'))
        <p class="rounded-sm bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">{{ session('status') }}</p>
    @endif

    <section class="grid gap-6 md:grid-cols-[320px_1fr]">
        @if ($video->thumbnail_url)
            <img src="{{ $video->thumbnail_url }}" alt="" class="aspect-video w-full rounded-sm object-cover">
        @endif

        <div class="space-y-3">
            <h1 class="text-xl font-semibold">{{ $video->title }}</h1>
            <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-[#706f6c] dark:text-[#A1A09A]">チャンネル</dt>
                <dd>{{ $video->channel_title ?? '—' }}</dd>
                <dt class="text-[#706f6c] dark:text-[#A1A09A]">再生時間</dt>
                <dd>{{ Video::formatSeconds($video->duration_seconds) }}</dd>
                <dt class="text-[#706f6c] dark:text-[#A1A09A]">公開日</dt>
                <dd>{{ $video->published_at?->format('Y-m-d') ?? '—' }}</dd>
                <dt class="text-[#706f6c] dark:text-[#A1A09A]">YouTube</dt>
                <dd><a href="{{ $video->url }}" target="_blank" rel="noopener" class="break-all text-blue-700 hover:underline dark:text-blue-400">{{ $video->url }}</a></dd>
            </dl>
        </div>
    </section>

    <section class="space-y-3">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-lg font-semibold">分析</h2>
            <button
                type="button"
                wire:click="analyze"
                @disabled($this->isAnalyzing)
                class="rounded-sm bg-[#1b1b18] px-5 py-2 text-sm text-white hover:bg-black disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]"
            >
                {{ $this->analyses->isEmpty() ? '分析する' : '再分析する' }}
            </button>
        </div>
        @error('analysis')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        @if ($this->analyses->isEmpty())
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">まだ分析していません。「分析する」を押すと、分析ジョブがキューに入ります。</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="border-b border-[#19140035] text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                    <tr>
                        <th class="py-2 pr-4 font-normal">版</th>
                        <th class="py-2 pr-4 font-normal">状態</th>
                        <th class="py-2 pr-4 font-normal">モデル</th>
                        <th class="py-2 pr-4 font-normal">依頼者</th>
                        <th class="py-2 font-normal">完了日時</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->analyses as $analysis)
                        <tr wire:key="analysis-{{ $analysis->id }}" class="border-b border-[#19140015] align-top dark:border-[#3E3E3A]">
                            <td class="py-2 pr-4">第{{ $analysis->version }}版</td>
                            <td class="py-2 pr-4">
                                {{ $analysis->statusLabel() }}
                                @if ($analysis->isInProgress())
                                    <span class="ml-2 inline-block h-1.5 w-24 overflow-hidden rounded-full bg-[#e5e5e0] align-middle dark:bg-[#3E3E3A]">
                                        <span class="block h-full bg-[#1b1b18] transition-all dark:bg-[#EDEDEC]" style="width: {{ $analysis->progress }}%"></span>
                                    </span>
                                    <span class="ml-1 text-xs">{{ $analysis->progress }}%</span>
                                @endif
                                @if ($analysis->error_message)
                                    <p class="text-xs text-red-600">{{ $analysis->error_message }}</p>
                                @endif
                            </td>
                            <td class="py-2 pr-4">{{ $analysis->model ?? '—' }}</td>
                            <td class="py-2 pr-4">{{ $analysis->requestedBy?->name ?? '—' }}</td>
                            <td class="py-2">{{ $analysis->analyzed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($this->latestCompleted)
        @php($content = $this->latestCompleted->content ?? [])

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">要約（第{{ $this->latestCompleted->version }}版）</h2>
            <p class="text-sm leading-relaxed">{{ $this->latestCompleted->summary }}</p>
            @if (! empty($content['topics']))
                <p class="flex flex-wrap gap-2 text-xs">
                    @foreach ($content['topics'] as $topic)
                        <span wire:key="topic-{{ $loop->index }}" class="rounded-full border border-[#19140035] px-2 py-0.5 dark:border-[#3E3E3A]">{{ $topic }}</span>
                    @endforeach
                </p>
            @endif
        </section>

        @if (! empty($content['key_points']))
            <section class="space-y-3">
                <h2 class="text-lg font-semibold">要点</h2>
                <ul class="space-y-1 text-sm">
                    @foreach ($content['key_points'] as $point)
                        <li wire:key="point-{{ $loop->index }}">
                            <a href="{{ $video->urlAt($point['start_seconds']) }}" target="_blank" rel="noopener" class="font-mono text-blue-700 hover:underline dark:text-blue-400">
                                {{ Video::formatSeconds($point['start_seconds']) }}〜{{ Video::formatSeconds($point['end_seconds']) }}
                            </a>
                            {{ $point['point'] }}
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">抽出された概念（候補）</h2>
            @if ($this->concepts->isEmpty())
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">概念は抽出されていません。</p>
            @else
                <ul class="space-y-3">
                    @foreach ($this->concepts as $edge)
                        <li wire:key="concept-{{ $edge->id }}" class="rounded-sm border border-[#19140035] p-3 text-sm dark:border-[#3E3E3A]">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('knowledge.show', $edge->sourceNode) }}" wire:navigate class="font-medium hover:underline">{{ $edge->sourceNode->title }}</a>
                                <span class="rounded-full bg-[#f0f0ec] px-2 py-0.5 text-xs dark:bg-[#1f1f1e]">ノード：{{ KnowledgeNode::statusLabelFor($edge->sourceNode->status) }}</span>
                                <span class="rounded-full bg-[#f0f0ec] px-2 py-0.5 text-xs dark:bg-[#1f1f1e]">関係：{{ KnowledgeNode::statusLabelFor($edge->status) }}</span>
                                @if ($edge->confidence !== null)
                                    <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">確信度 {{ number_format((float) $edge->confidence * 100) }}%</span>
                                @endif
                            </div>
                            @if ($edge->sourceNode->description)
                                <p class="mt-1 text-[#706f6c] dark:text-[#A1A09A]">{{ $edge->sourceNode->description }}</p>
                            @endif
                            @foreach ($edge->sources as $source)
                                <p wire:key="source-{{ $source->id }}" class="mt-1 text-xs">
                                    出典：
                                    <a href="{{ $video->urlAt($source->start_seconds) }}" target="_blank" rel="noopener" class="font-mono text-blue-700 hover:underline dark:text-blue-400">
                                        {{ Video::formatSeconds($source->start_seconds) }}〜{{ Video::formatSeconds($source->end_seconds) }}
                                    </a>
                                    {{ $source->excerpt }}
                                </p>
                            @endforeach
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">概念名を押すと知識画面が開き、候補の採用・修正・統合ができます。</p>
            @endif
        </section>
    @endif
</div>
