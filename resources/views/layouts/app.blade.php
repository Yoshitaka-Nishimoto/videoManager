<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        @auth
            <header class="border-b border-[#19140035] dark:border-[#3E3E3A]">
                <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <div class="flex items-center gap-6">
                        <a href="{{ route('dashboard') }}" class="font-semibold">{{ config('app.name') }}</a>
                        <nav class="flex gap-4 text-sm">
                            <a href="{{ route('videos.index') }}" wire:navigate @class(['underline underline-offset-4' => request()->routeIs('videos.*')])>動画</a>
                            <a href="{{ route('knowledge.index') }}" wire:navigate @class(['underline underline-offset-4' => request()->routeIs('knowledge.*')])>知識</a>
                            <a href="{{ route('ai-usage.index') }}" wire:navigate @class(['underline underline-offset-4' => request()->routeIs('ai-usage.*')])>AI使用量</a>
                        </nav>
                    </div>

                    <div class="flex items-center gap-4 text-sm">
                        <span class="hidden sm:inline">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="rounded-sm border border-[#19140035] px-4 py-1.5 hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]">
                                ログアウト
                            </button>
                        </form>
                    </div>
                </div>
            </header>
        @endauth

        <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
