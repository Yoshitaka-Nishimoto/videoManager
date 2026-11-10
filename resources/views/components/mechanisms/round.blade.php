{{--
    仕組みの 1 周の結果（次の周への入力、① 目標の理解、② 知識検索、⑥ 結果報告）。仕組みの詳細画面で使う。
--}}
@props(['round'])

@use('App\Ai\Agents\MechanismDesigner')
@use('App\Models\KnowledgeNode')
@use('App\Models\MechanismSource')
@use('App\Services\Knowledge\KnowledgeSearch')

@php
    $muted = 'text-[#706f6c] dark:text-[#A1A09A]';
    $card = 'space-y-3 rounded-sm border border-[#19140035] p-4 dark:border-[#3E3E3A]';
    $understanding = $round->understanding;
    $search = $round->search;
    $report = $round->report;
    $usedSources = $round->sources->where('source_type', MechanismSource::TYPE_KNOWLEDGE);
    $usedIds = $usedSources->pluck('knowledge_node_id')->all();
    $statusColors = [
        MechanismDesigner::CRITERION_MET => 'text-green-700 dark:text-green-400',
        MechanismDesigner::CRITERION_UNMET => 'text-red-700 dark:text-red-400',
        MechanismDesigner::CRITERION_UNKNOWN => $muted,
    ];
@endphp

<div class="space-y-4">
    @php $receivedAnswers = $round->receivedAnswers(); @endphp
    @if ($round->instruction || $receivedAnswers !== [])
        <section class="{{ $card }}">
            <h3 class="font-semibold">{{ $round->number }} 周目への入力</h3>
            @if ($receivedAnswers !== [])
                <dl class="space-y-2 text-sm">
                    @foreach ($receivedAnswers as $qa)
                        <div wire:key="r{{ $round->id }}-answer-{{ $loop->index }}">
                            <dt class="{{ $muted }}">Q. {{ $qa['question'] }}</dt>
                            <dd class="whitespace-pre-wrap">A. {{ $qa['answer'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
            @if ($round->instruction)
                <p class="whitespace-pre-wrap text-sm">{{ $round->instruction }}</p>
            @endif
        </section>
    @endif

    {{-- ① 目標の理解 --}}
    @if ($understanding)
        <section class="{{ $card }}">
            <h3 class="font-semibold">① 目標の理解</h3>
            <div class="space-y-1 text-sm">
                <p class="{{ $muted }}">言い換え</p>
                <p>{{ $understanding['restated_goal'] }}</p>
            </div>
            <div class="space-y-1 text-sm">
                <p class="{{ $muted }}">完了条件</p>
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($understanding['done_criteria'] as $criterion)
                        <li wire:key="r{{ $round->id }}-criterion-{{ $loop->index }}">{{ $criterion }}</li>
                    @endforeach
                </ul>
            </div>
            @foreach (['scope_out' => '扱わないこと', 'unclear_points' => 'はっきりしない点（後の段階で質問・候補にします）'] as $key => $label)
                @if (($understanding[$key] ?? []) !== [])
                    <div wire:key="r{{ $round->id }}-{{ $key }}" class="space-y-1 text-sm">
                        <p class="{{ $muted }}">{{ $label }}</p>
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ($understanding[$key] as $item)
                                <li wire:key="r{{ $round->id }}-{{ $key }}-{{ $loop->index }}">{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
            @if (($understanding['search_keywords'] ?? []) !== [])
                <p class="text-sm"><span class="{{ $muted }}">知識を探す検索語：</span>{{ implode('、', $understanding['search_keywords']) }}</p>
            @endif
            <p class="text-xs {{ $muted }}">{{ $understanding['model'] ?? '' }}</p>
        </section>
    @endif

    {{-- ② 知識検索 --}}
    @if ($search)
        <section class="{{ $card }}">
            <h3 class="font-semibold">② 知識検索</h3>

            <div class="space-y-1 text-sm">
                <p class="{{ $muted }}">使う知識（{{ $usedSources->count() }} 件 / 見つかった {{ count($search['found']) }} 件）</p>
                @if ($usedSources->isEmpty())
                    <p>仕組みに使える知識は見つかりませんでした。</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($usedSources as $source)
                            <li wire:key="r{{ $round->id }}-used-{{ $source->id }}">
                                @if ($source->knowledge_node_id)
                                    <a href="{{ route('knowledge.show', $source->knowledge_node_id) }}" wire:navigate class="font-medium hover:underline">{{ $source->title }}</a>
                                @else
                                    <span class="font-medium">{{ $source->title }}</span>（削除された知識）
                                @endif
                                <p class="{{ $muted }}">{{ $source->reason }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($search['enough'])
                <p class="text-sm">プロジェクト内の知識だけで設計できると判断しました。</p>
            @else
                <div class="space-y-1 rounded-sm bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    <p>プロジェクト内の知識だけでは足りません：{{ $search['why_not_enough'] }}</p>
                    @if ($search['web_queries'] !== [])
                        <p>Web で調べる検索語：{{ implode('、', $search['web_queries']) }}（Web 検索は準備中です）</p>
                    @endif
                </div>
            @endif

            @if ($search['found'] !== [])
                <details class="text-sm">
                    <summary class="cursor-pointer {{ $muted }}">見つかった知識（{{ count($search['found']) }} 件）と検索語</summary>
                    <p class="mt-2 {{ $muted }}">検索語：{{ implode(' / ', $search['queries']) }}</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($search['found'] as $found)
                            <li wire:key="r{{ $round->id }}-found-{{ $found['id'] }}" class="flex flex-wrap gap-x-2">
                                <span>{{ in_array($found['id'], $usedIds, true) ? '✓' : '・' }}</span>
                                <a href="{{ route('knowledge.show', $found['id']) }}" wire:navigate class="hover:underline">{{ $found['title'] }}</a>
                                <span class="{{ $muted }}">
                                    {{ KnowledgeNode::typeLabelFor($found['type']) }}・{{ KnowledgeNode::statusLabelFor($found['status']) }}
                                    ・{{ collect($found['match_reasons'])->map(fn (string $reason) => KnowledgeSearch::REASON_LABELS[$reason] ?? $reason)->implode('、') }}
                                    @if ($found['similarity'] !== null)
                                        （類似度 {{ number_format($found['similarity'], 2) }}）
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
            <p class="text-xs {{ $muted }}">{{ $search['model'] ?? '' }}</p>
        </section>
    @endif

    {{-- ⑥ 結果報告 --}}
    @if ($report)
        <section class="{{ $card }}">
            <h3 class="font-semibold">⑥ 結果報告</h3>
            <p class="text-sm">{{ $report['summary'] }}</p>

            @if ($report['criteria_check'] !== [])
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[#19140035] {{ $muted }} dark:border-[#3E3E3A]">
                        <tr>
                            <th class="py-1 pr-4 font-normal">完了条件</th>
                            <th class="py-1 pr-4 font-normal">判定</th>
                            <th class="py-1 font-normal">理由</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['criteria_check'] as $check)
                            <tr wire:key="r{{ $round->id }}-check-{{ $loop->index }}" class="border-b border-[#19140015] align-top dark:border-[#3E3E3A]">
                                <td class="py-1 pr-4">{{ $check['criterion'] }}</td>
                                <td class="py-1 pr-4 whitespace-nowrap {{ $statusColors[$check['status']] ?? $muted }}">{{ MechanismDesigner::CRITERION_LABELS[$check['status']] ?? $check['status'] }}</td>
                                <td class="py-1">{{ $check['note'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($report['remaining_missing'] !== [])
                <div class="space-y-1 text-sm">
                    <p class="{{ $muted }}">まだ足りない情報</p>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($report['remaining_missing'] as $item)
                            <li wire:key="r{{ $round->id }}-missing-{{ $loop->index }}">{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($report['next_suggestion'] !== '')
                <div class="space-y-1 text-sm">
                    <p class="{{ $muted }}">次の周でするとよいこと</p>
                    <p>{{ $report['next_suggestion'] }}</p>
                </div>
            @endif
            <p class="text-xs {{ $muted }}">{{ $report['model'] ?? '' }}</p>
        </section>
    @endif
</div>
