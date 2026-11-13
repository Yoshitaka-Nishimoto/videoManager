@props([
    // 表示する本文（貼り付けた本文、分析に使った本文）
    'text' => '',
    // 画像の名前 → 元のページの画像の URL（videos.pasted_images）。名前だけの行を画像にする
    'images' => [],
])

@php
    // 画像の名前だけの行で区切り、文章のかたまりと画像を順に並べる。
    $blocks = [];
    $lines = [];

    foreach (preg_split('/\R/u', (string) $text) as $line) {
        $name = trim($line);

        if ($name !== '' && isset($images[$name])) {
            if ($lines !== []) {
                $blocks[] = ['text' => implode("\n", $lines)];
                $lines = [];
            }

            $blocks[] = ['image' => $images[$name], 'name' => $name];
        } else {
            $lines[] = $line;
        }
    }

    if ($lines !== []) {
        $blocks[] = ['text' => implode("\n", $lines)];
    }
@endphp

<div {{ $attributes->merge(['class' => 'max-h-[40rem] overflow-auto text-xs']) }}>
    @foreach ($blocks as $block)
        @if (isset($block['image']))
            <figure wire:key="source-image-{{ $loop->index }}" class="my-2">
                <a href="{{ $block['image'] }}" target="_blank" rel="noopener noreferrer">
                    <img src="{{ $block['image'] }}" alt="{{ $block['name'] }}" loading="lazy" referrerpolicy="no-referrer" class="max-h-96 max-w-full rounded-sm border border-[#19140035] object-contain dark:border-[#3E3E3A]">
                </a>
                <figcaption class="mt-0.5 text-[#706f6c] dark:text-[#A1A09A]">{{ $block['name'] }}</figcaption>
            </figure>
        @else
            <pre wire:key="source-text-{{ $loop->index }}" class="whitespace-pre-wrap break-words">{{ $block['text'] }}</pre>
        @endif
    @endforeach
</div>
