@props(['name', 'label', 'type' => 'text', 'value' => null])

<div class="mb-4">
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        {{ $attributes->merge(['class' => 'w-full rounded-sm border border-[#19140035] bg-transparent px-3 py-2 text-sm focus:border-[#1b1b18] focus:outline-none dark:border-[#3E3E3A] dark:focus:border-[#EDEDEC]']) }}
    >
    @error($name)
        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>
