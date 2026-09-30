<x-layouts.auth title="Log in">
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <x-auth.input name="email" label="Email" type="email" required autofocus autocomplete="username" />
        <x-auth.input name="password" label="Password" type="password" required autocomplete="current-password" />

        <div class="mb-6 flex items-center justify-between text-sm">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="remember" class="rounded-sm">
                Remember me
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="underline underline-offset-4">Forgot password?</a>
            @endif
        </div>

        <x-auth.button>Log in</x-auth.button>
    </form>

    @if (Route::has('register'))
        <p class="mt-6 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Don't have an account?
            <a href="{{ route('register') }}" class="text-[#1b1b18] underline underline-offset-4 dark:text-[#EDEDEC]">Register</a>
        </p>
    @endif
</x-layouts.auth>
