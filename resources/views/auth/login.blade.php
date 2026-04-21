<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <label for="email" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                {{ __('Email') }}
            </label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-stone-700 bg-gray-50 dark:bg-stone-800 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400 dark:focus:ring-stone-500 focus:border-transparent"
                   required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <label for="password" class="block text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                {{ __('Password') }}
            </label>
            <input id="password" type="password" name="password"
                   class="w-full px-4 py-2.5 rounded-xl border border-gray-200 dark:border-stone-700 bg-gray-50 dark:bg-stone-800 text-gray-900 dark:text-gray-100 text-sm focus:outline-none focus:ring-2 focus:ring-gray-400 dark:focus:ring-stone-500 focus:border-transparent"
                   required autocomplete="current-password" />
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

        <div class="mt-6 flex items-center justify-between">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}"
                   class="text-sm text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                    {{ __('Forgot password?') }}
                </a>
            @endif

            <button type="submit"
                    class="px-5 py-2.5 bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-sm font-medium rounded-xl hover:bg-gray-700 dark:hover:bg-white transition-colors">
                {{ __('Sign in') }}
            </button>
        </div>
    </form>
</x-guest-layout>
