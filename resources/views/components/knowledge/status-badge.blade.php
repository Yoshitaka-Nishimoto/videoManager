@props(['status'])

@php
    $classes = match ($status) {
        \App\Models\KnowledgeNode::STATUS_CONFIRMED => 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
        \App\Models\KnowledgeNode::STATUS_DEPRECATED => 'bg-[#f0f0ec] text-[#706f6c] line-through dark:bg-[#1f1f1e] dark:text-[#A1A09A]',
        default => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    };
@endphp

<span {{ $attributes->class(['inline-block rounded-full px-2 py-0.5 text-xs whitespace-nowrap', $classes]) }}>
    {{ \App\Models\KnowledgeNode::statusLabelFor($status) }}
</span>
