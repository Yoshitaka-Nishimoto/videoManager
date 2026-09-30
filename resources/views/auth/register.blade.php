<x-layouts.auth title="Register">
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <x-auth.input name="name" label="Name" required autofocus autocomplete="name" />
        <x-auth.input name="email" label="Email" type="email" required autocomplete="username" />
        <x-auth.input name="password" label="Password" type="password" required autocomplete="new-password" />
        <x-auth.input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

        <x-auth.button class="mt-2">Register</x-auth.button>
    </form>

    <p class="mt-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Already registered?
        <a href="{{ route('login') }}" class="text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]">Log in</a>
    </p>
</x-layouts.auth>
