<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Dashboard - {{ config('app.name', 'Laravel') }}</title>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <header class="border-b border-[#19140035] dark:border-[#3E3E3A]">
            <div class="mx-auto flex max-w-4xl items-center justify-between px-6 py-4">
                <a href="{{ url('/') }}" class="font-semibold">{{ config('app.name', 'Laravel') }}</a>

                <div class="flex items-center gap-4 text-sm">
                    <span>{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-sm border border-[#19140035] px-4 py-1.5 hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]">
                            Log out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-6 py-8">
            <h1 class="mb-2 text-xl font-semibold">Dashboard</h1>
            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">You're logged in as {{ auth()->user()->email }}.</p>
        </main>
    </body>
</html>
