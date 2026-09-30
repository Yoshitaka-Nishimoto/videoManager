<x-layouts.auth title="Reset password">
    <form method="POST" action="{{ route('password.update') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-auth.input name="email" label="Email" type="email" :value="$request->email" required autofocus autocomplete="username" />
        <x-auth.input name="password" label="New password" type="password" required autocomplete="new-password" />
        <x-auth.input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

        <x-auth.button class="mt-2">Reset password</x-auth.button>
    </form>
</x-layouts.auth>
