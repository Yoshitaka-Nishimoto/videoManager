<x-layouts.auth title="Forgot password">
    <p class="mb-4 text-sm text-[#706f6c] dark:text-[#A1A09A]">
        Enter your email address and we will send you a password reset link.
    </p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <x-auth.input name="email" label="Email" type="email" required autofocus autocomplete="username" />

        <x-auth.button class="mt-2">Email password reset link</x-auth.button>
    </form>

    <p class="mt-6 text-center text-sm">
        <a href="{{ route('login') }}" class="underline underline-offset-4">Back to log in</a>
    </p>
</x-layouts.auth>
