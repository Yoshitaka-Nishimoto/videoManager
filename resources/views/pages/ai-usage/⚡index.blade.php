<?php

use App\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('AI 使用量')] class extends Component
{
    /** 集計期間（日数）。0 は全期間。 */
    #[Url]
    public int $days = 30;

    #[Computed]
    public function totals(): object
    {
        return $this->logs()
            ->selectRaw('count(*) as calls, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(estimated_cost), 0) as cost, count(*) filter (where not succeeded) as failures')
            ->toBase()
            ->first();
    }

    /**
     * @return SupportCollection<int, object>
     */
    #[Computed]
    public function byModel(): SupportCollection
    {
        return $this->grouped(['provider', 'model']);
    }

    /**
     * @return SupportCollection<int, object>
     */
    #[Computed]
    public function byFeature(): SupportCollection
    {
        return $this->grouped(['feature']);
    }

    /**
     * @return Collection<int, AiUsageLog>
     */
    #[Computed]
    public function recent(): Collection
    {
        return $this->logs()->with('usable')->latest('id')->limit(20)->get();
    }

    /**
     * @param  list<string>  $columns
     * @return SupportCollection<int, object>
     */
    private function grouped(array $columns): SupportCollection
    {
        return $this->logs()
            ->select($columns)
            ->selectRaw('count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(estimated_cost) as cost, count(*) filter (where not succeeded) as failures')
            ->groupBy($columns)
            ->orderByDesc('calls')
            ->toBase()
            ->get();
    }

    /**
     * @return Builder<AiUsageLog>
     */
    private function logs(): Builder
    {
        return AiUsageLog::query()->when($this->days > 0, fn ($query) => $query->where('created_at', '>=', now()->subDays($this->days)));
    }
};
?>

@php
    $muted = 'text-[#706f6c] dark:text-[#A1A09A]';
    $card = 'rounded-sm border border-[#19140035] p-4 dark:border-[#3E3E3A]';
    $cost = fn ($value) => $value === null ? '—' : '$'.number_format((float) $value, 4);
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-xl font-semibold">AI 使用量</h1>
        <select wire:model.live="days" aria-label="集計期間" class="rounded-sm border border-[#19140035] bg-white px-2 py-1 text-sm dark:border-[#3E3E3A] dark:bg-[#161615]">
            <option value="7">直近7日</option>
            <option value="30">直近30日</option>
            <option value="0">全期間</option>
        </select>
    </div>

    <p class="text-xs {{ $muted }}">VideoManager が呼び出した AI の記録です。推定費用は config/ai_usage.php の単価から計算します（単価が未登録のモデルは「—」）。</p>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            '呼び出し' => number_format($this->totals->calls).' 回',
            'トークン（入力／出力）' => number_format($this->totals->input_tokens).' / '.number_format($this->totals->output_tokens),
            '推定費用' => $cost($this->totals->cost),
            '失敗' => number_format($this->totals->failures).' 件',
        ] as $label => $value)
            <div wire:key="total-{{ $loop->index }}" class="{{ $card }}">
                <p class="text-xs {{ $muted }}">{{ $label }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @foreach (['モデル別' => $this->byModel, '機能別' => $this->byFeature] as $heading => $rows)
        <section wire:key="section-{{ $loop->index }}" class="space-y-2">
            <h2 class="font-semibold">{{ $heading }}</h2>
            @if ($rows->isEmpty())
                <p class="text-sm {{ $muted }}">記録はありません。</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm tabular-nums">
                        <thead class="border-b border-[#19140035] {{ $muted }} dark:border-[#3E3E3A]">
                            <tr>
                                <th class="py-2 pr-4 font-normal">{{ $heading === 'モデル別' ? 'モデル' : '機能' }}</th>
                                <th class="py-2 pr-4 text-right font-normal">回数</th>
                                <th class="py-2 pr-4 text-right font-normal">入力</th>
                                <th class="py-2 pr-4 text-right font-normal">出力</th>
                                <th class="py-2 pr-4 text-right font-normal">推定費用</th>
                                <th class="py-2 text-right font-normal">失敗</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr wire:key="row-{{ $loop->parent->index }}-{{ $loop->index }}" class="border-b border-[#19140015] dark:border-[#3E3E3A]">
                                    <td class="py-2 pr-4">{{ isset($row->model) ? $row->provider.' / '.$row->model : $row->feature }}</td>
                                    <td class="py-2 pr-4 text-right">{{ number_format($row->calls) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ number_format($row->input_tokens) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ number_format($row->output_tokens) }}</td>
                                    <td class="py-2 pr-4 text-right">{{ $cost($row->cost) }}</td>
                                    <td @class(['py-2 text-right', 'text-red-600' => $row->failures > 0])>{{ number_format($row->failures) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach

    <section class="space-y-2">
        <h2 class="font-semibold">最近の呼び出し</h2>
        @forelse ($this->recent as $log)
            <p wire:key="log-{{ $log->id }}" class="text-sm">
                <span class="{{ $muted }}">{{ $log->created_at?->format('Y-m-d H:i') }}</span>
                {{ $log->feature }} · {{ $log->model }} ·
                {{ number_format($log->input_tokens) }} / {{ number_format($log->output_tokens) }} tokens
                @if ($log->usable instanceof \App\Models\VideoAnalysis)
                    · <a href="{{ route('videos.show', $log->usable->video_id) }}" wire:navigate class="underline">分析 第{{ $log->usable->version }}版</a>
                @elseif ($log->usable instanceof \App\Models\ProductionPlan)
                    · <a href="{{ route('productions.show', ['production' => $log->usable->video_production_id, 'plan' => $log->usable->id]) }}" wire:navigate class="underline">台本の候補（制作案 第{{ $log->usable->version }}版）</a>
                @endif
                @unless ($log->succeeded)
                    · <span class="text-red-600">失敗：{{ $log->error_message }}</span>
                @endunless
            </p>
        @empty
            <p class="text-sm {{ $muted }}">記録はありません。</p>
        @endforelse
    </section>
</div>
