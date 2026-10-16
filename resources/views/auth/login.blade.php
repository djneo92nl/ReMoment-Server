<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" class="mb-1.5" />
            <x-text-input id="password" type="password" name="password" class="w-full"
                          required autofocus autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="mt-4">
            <label for="remember_me" class="inline-flex items-center gap-2 cursor-pointer">
                <input id="remember_me" type="checkbox" name="remember"
                       class="rounded border-gray-300 dark:border-stone-600 dark:bg-stone-800 text-gray-700 focus:ring-gray-400">
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="mt-6 flex justify-end">
            <x-primary-button>{{ __('Sign in') }}</x-primary-button>
        </div>
    </form>
</x-guest-layout>
