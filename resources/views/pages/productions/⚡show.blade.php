<?php

use App\Actions\Productions\RenderNotAllowedException;
use App\Actions\Productions\RequestProductionRender;
use App\Models\ProductionPlan;
use App\Models\ProductionRender;
use App\Models\VideoProduction;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('制作詳細')] class extends Component
{
    public VideoProduction $production;

    /** 表示する制作案の版。指定がなければ確認済みの版、なければ最新の版。 */
    #[Url(as: 'plan')]
    public ?int $planId = null;

    public function renderStill(int $sceneId, RequestProductionRender $request): void
    {
        $scene = $this->plan?->videoScenes->firstWhere('id', $sceneId);

        $this->request(fn () => $request->handle($this->plan, ProductionRender::KIND_STILL, auth()->user(), $scene));
    }

    public function renderVideo(string $kind, RequestProductionRender $request): void
    {
        if (! in_array($kind, [ProductionRender::KIND_PREVIEW, ProductionRender::KIND_FINAL], true)) {
            return;
        }

        $this->request(fn () => $request->handle($this->plan, $kind, auth()->user()));
    }

    /**
     * @return Collection<int, ProductionPlan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return $this->production->plans()->orderByDesc('version')->get();
    }

    #[Computed]
    public function plan(): ?ProductionPlan
    {
        $plan = $this->plans->firstWhere('id', $this->planId)
            ?? $this->plans->firstWhere('status', ProductionPlan::STATUS_CONFIRMED)
            ?? $this->plans->first();

        return $plan?->load(['videoScenes.renders' => fn ($query) => $query->latest('id')]);
    }

    /**
     * この制作案の書き出し（新しい順）。
     *
     * @return Collection<int, ProductionRender>
     */
    #[Computed]
    public function renders(): Collection
    {
        if ($this->plan === null) {
            return new Collection;
        }

        return $this->plan->renders()->with(['scene', 'requestedBy'])->latest('id')->limit(30)->get();
    }

    #[Computed]
    public function isRendering(): bool
    {
        return $this->renders->contains(fn (ProductionRender $render) => $render->isInProgress());
    }

    /**
     * 最後に書き出しが完了した動画（確認用か完成版）。
     */
    #[Computed]
    public function latestVideo(): ?ProductionRender
    {
        return $this->renders->first(fn (ProductionRender $render) => ! $render->isStill() && $render->isCompleted());
    }

    private function request(Closure $callback): void
    {
        $this->resetErrorBag('render');

        if ($this->plan === null) {
            $this->addError('render', '制作案がありません。');

            return;
        }

        try {
            $callback();
        } catch (RenderNotAllowedException $e) {
            $this->addError('render', $e->getMessage());
        }

        unset($this->plan, $this->renders);
    }
};
?>

@php
    $muted = 'text-[#706f6c] dark:text-[#A1A09A]';
    $button = 'rounded-sm bg-[#1b1b18] px-4 py-2 text-sm text-white hover:bg-black disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#1b1b18]';
    $subButton = 'rounded-sm border border-[#19140035] px-3 py-1 text-xs hover:border-[#1915014a] disabled:opacity-50 dark:border-[#3E3E3A] dark:hover:border-[#62605b]';
@endphp

<div class="space-y-8" @if ($this->isRendering) wire:poll.2s @endif>
    <div>
        <a href="{{ route('productions.index') }}" wire:navigate class="text-sm {{ $muted }} hover:underline">← 制作一覧</a>
    </div>

    @if (session('status'))
        <p class="rounded-sm bg-green-50 px-4 py-2 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">{{ session('status') }}</p>
    @endif

    <section class="space-y-3">
        <h1 class="text-xl font-semibold">{{ $production->title }}</h1>
        <dl class="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="{{ $muted }}">ジャンル</dt>
            <dd>{{ $production->genreLabel() }}</dd>
            <dt class="{{ $muted }}">設計判断</dt>
            <dd>
                @if ($production->decisionNode)
                    <a href="{{ route('knowledge.show', $production->decisionNode) }}" wire:navigate class="hover:underline">{{ $production->decisionNode->title }}</a>
                @else
                    —
                @endif
            </dd>
            <dt class="{{ $muted }}">比率</dt>
            <dd>{{ $production->ratio }}</dd>
            <dt class="{{ $muted }}">目標の長さ</dt>
            <dd>{{ $production->target_duration_seconds ? $production->target_duration_seconds.' 秒' : '—' }}</dd>
        </dl>
        @if ($production->brief)
            <p class="text-sm leading-relaxed">{{ $production->brief }}</p>
        @endif
    </section>

    @if ($this->plan === null)
        <p class="text-sm {{ $muted }}">まだ制作案がありません。</p>
    @else
        @php($plan = $this->plan)

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <h2 class="text-lg font-semibold">制作案</h2>
                    <select wire:model.live="planId" class="rounded-sm border border-[#19140035] bg-transparent px-2 py-1 text-sm dark:border-[#3E3E3A]">
                        @foreach ($this->plans as $option)
                            <option wire:key="plan-option-{{ $option->id }}" value="{{ $option->id }}" @selected($option->id === $plan->id)>第{{ $option->version }}版（{{ $option->statusLabel() }}）</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-wrap gap-2">
                    {{-- 読み取り専用の Studio で、書き出さずに動きを確認する（操作は残らない）。 --}}
                    <a
                        href="{{ route('remotion-studio', ['path' => "plans/{$plan->id}/"]) }}?/Production"
                        target="_blank"
                        rel="noopener"
                        class="rounded-sm border border-[#19140035] px-4 py-2 text-sm hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]"
                    >Studio で確認</a>
                    <button type="button" wire:click="renderVideo('preview')" wire:loading.attr="disabled" class="{{ $button }}">確認用の動画を書き出す</button>
                    <button
                        type="button"
                        wire:click="renderVideo('final')"
                        wire:loading.attr="disabled"
                        @disabled($plan->status !== ProductionPlan::STATUS_CONFIRMED)
                        title="{{ $plan->status === ProductionPlan::STATUS_CONFIRMED ? '' : '完成版は確認済みの制作案だけ書き出せます' }}"
                        class="{{ $button }}"
                    >完成版を書き出す</button>
                </div>
            </div>
            @error('render')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            @if ($plan->summary)
                <p class="text-sm leading-relaxed">{{ $plan->summary }}</p>
            @endif

            @if ($plan->videoScenes->isEmpty())
                <p class="text-sm {{ $muted }}">再生する場面がありません。</p>
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($plan->videoScenes as $scene)
                        @php($still = $scene->renders->first(fn ($render) => $render->isCompleted()))
                        @php($stillInProgress = $scene->renders->contains(fn ($render) => $render->isInProgress()))

                        <div wire:key="scene-{{ $scene->id }}" class="space-y-2 rounded-sm border border-[#19140035] p-3 text-sm dark:border-[#3E3E3A]">
                            <div class="aspect-video overflow-hidden rounded-sm bg-[#f0efea] dark:bg-[#262624]">
                                @if ($still)
                                    <a href="{{ route('production-renders.file', $still) }}" target="_blank" rel="noopener">
                                        <img src="{{ route('production-renders.file', $still) }}" alt="{{ $scene->title }}" class="h-full w-full object-contain">
                                    </a>
                                @else
                                    <div class="flex h-full items-center justify-center text-xs {{ $muted }}">{{ $stillInProgress ? '静止画を書き出し中…' : '静止画なし' }}</div>
                                @endif
                            </div>
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-medium">{{ $scene->scene_key }}　{{ $scene->title }}</p>
                                    <p class="text-xs {{ $muted }}">{{ $scene->scene_type }} · {{ rtrim(rtrim($scene->duration_seconds, '0'), '.') }} 秒 · {{ $scene->statusLabel() }}</p>
                                </div>
                                @unless ($scene->isRunway())
                                    <button type="button" wire:click="renderStill({{ $scene->id }})" wire:loading.attr="disabled" @disabled($stillInProgress) class="{{ $subButton }} shrink-0">静止画</button>
                                @endunless
                            </div>
                            @if ($scene->caption ?? $scene->narration)
                                <p class="line-clamp-2 text-xs {{ $muted }}">{{ $scene->caption ?? $scene->narration }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($this->latestVideo)
            <section class="space-y-2">
                <h2 class="text-lg font-semibold">最新の動画（{{ $this->latestVideo->kindLabel() }}・{{ $this->latestVideo->completed_at?->format('Y-m-d H:i') }}）</h2>
                <video wire:key="video-{{ $this->latestVideo->id }}" src="{{ route('production-renders.file', $this->latestVideo) }}" controls preload="metadata" class="w-full max-w-3xl rounded-sm bg-black"></video>
            </section>
        @endif

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">書き出しの履歴</h2>

            @if ($this->renders->isEmpty())
                <p class="text-sm {{ $muted }}">まだ書き出していません。</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[#19140035] {{ $muted }} dark:border-[#3E3E3A]">
                        <tr>
                            <th class="py-2 pr-4 font-normal">種類</th>
                            <th class="py-2 pr-4 font-normal">状態</th>
                            <th class="py-2 pr-4 font-normal">大きさ</th>
                            <th class="py-2 pr-4 font-normal">時間</th>
                            <th class="py-2 pr-4 font-normal">依頼</th>
                            <th class="py-2 font-normal">出力</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->renders as $render)
                            <tr wire:key="render-{{ $render->id }}" class="border-b border-[#19140015] align-top dark:border-[#3E3E3A]">
                                <td class="py-2 pr-4">
                                    {{ $render->kindLabel() }}
                                    @if ($render->scene)
                                        <span class="text-xs {{ $muted }}">（{{ $render->scene->scene_key }}）</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    {{ $render->statusLabel() }}
                                    @if ($render->isInProgress())
                                        <span class="ml-2 inline-block h-1.5 w-24 overflow-hidden rounded-full bg-[#e5e5e0] align-middle dark:bg-[#3E3E3A]">
                                            <span class="block h-full bg-[#1b1b18] transition-all dark:bg-[#EDEDEC]" style="width: {{ $render->progress }}%"></span>
                                        </span>
                                        <span class="ml-1 text-xs">{{ $render->progress }}%</span>
                                    @endif
                                    @if ($render->error_message)
                                        <p class="text-xs text-red-600">{{ $render->error_message }}</p>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $render->width && $render->height ? "{$render->width}×{$render->height}" : '—' }}</td>
                                <td class="py-2 pr-4">{{ $render->render_ms ? number_format($render->render_ms / 1000, 1).' 秒' : '—' }}</td>
                                <td class="py-2 pr-4">
                                    {{ $render->requestedBy?->name ?? '—' }}
                                    <p class="text-xs {{ $muted }}">{{ $render->created_at?->format('m-d H:i') }}</p>
                                </td>
                                <td class="py-2">
                                    @if ($render->isCompleted())
                                        <a href="{{ route('production-renders.file', $render) }}" target="_blank" rel="noopener" class="text-blue-700 hover:underline dark:text-blue-400">開く</a>
                                        <span class="text-xs {{ $muted }}">{{ number_format($render->size_bytes / 1024) }} KB</span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif
</div>
