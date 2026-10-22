@props(['link', 'repository' => null])

@php($muted = 'text-[#706f6c] dark:text-[#A1A09A]')

<div {{ $attributes->class('space-y-1') }}>
    <p class="break-all font-mono text-xs">
        @if ($repository)
            <a href="{{ $link->githubUrl($repository) }}" target="_blank" rel="noopener" class="text-blue-700 hover:underline dark:text-blue-400">{{ $link->path }}</a>
        @else
            {{ $link->path }}
        @endif
        @if ($link->start_line !== null)
            <span class="{{ $muted }}">L{{ $link->start_line }}{{ $link->end_line !== null ? "-{$link->end_line}" : '' }}</span>
        @endif
    </p>
    @if ($link->symbol)
        <p class="break-all font-mono text-xs font-medium">{{ $link->symbol }}</p>
    @endif
    @if ($link->isMissing())
        <p class="text-xs text-red-700 dark:text-red-300">{{ $link->missing_since->format('Y-m-d') }} から graph.json で見つかりません</p>
    @endif
    @if ($link->note)
        <p class="text-xs">{{ $link->note }}</p>
    @endif
</div>
