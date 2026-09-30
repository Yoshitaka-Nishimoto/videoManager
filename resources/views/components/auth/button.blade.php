<button
    type="submit"
    {{ $attributes->merge(['class' => 'w-full rounded-sm bg-[#1b1b18] px-4 py-2 text-sm font-medium text-white hover:bg-black dark:bg-[#EDEDEC] dark:text-[#1C1C1A] dark:hover:bg-white']) }}
>
    {{ $slot }}
</button>
