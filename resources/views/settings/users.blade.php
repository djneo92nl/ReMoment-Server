<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Users" :subtitle="($users->count()) . ' ' . ($users->count() === 1 ? 'user' : 'users')" :back="route('settings.index')" />
    </x-slot>

    <div class="max-w-4xl">
        <x-card flush>

            <!-- Table header -->
            <div class="grid grid-cols-12 gap-4 px-8 py-4 border-b border-gray-100 dark:border-stone-800">
                <div class="col-span-8 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Username</div>
                <div class="col-span-4 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Joined</div>
            </div>

            @foreach($users as $user)
                <div class="grid grid-cols-12 gap-4 items-center px-8 py-5 border-b border-gray-50 dark:border-stone-800/50 last:border-0">
                    <div class="col-span-8 flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-gray-200 to-gray-300 dark:from-stone-700 dark:to-stone-600 flex items-center justify-center flex-shrink-0">
                            <span class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                        </div>
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</div>
                    </div>
                    <div class="col-span-4 text-sm text-gray-500">{{ $user->created_at->format('M j, Y') }}</div>
                </div>
            @endforeach
        </x-card>

        <x-card flush class="mt-8">
            <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800">
                <h2 class="text-sm font-medium text-gray-900 dark:text-gray-100">Signed-in apps</h2>
                <p class="mt-1 text-sm text-gray-500">Apps that signed in with the admin password. Changing the password signs them all out.</p>
            </div>

            @forelse($appTokens as $appToken)
                <div class="flex items-center gap-4 px-8 py-4 border-b border-gray-50 dark:border-stone-800/50 last:border-0">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $appToken->name ?: 'Unnamed app' }}</div>
                        <div class="text-xs text-gray-500">
                            Signed in {{ $appToken->created_at->diffForHumans() }}
                            · {{ $appToken->last_used_at ? 'last used '.$appToken->last_used_at->diffForHumans().($appToken->last_used_ip ? ' from '.$appToken->last_used_ip : '') : 'not used yet' }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('settings.app-tokens.destroy', $appToken) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">Sign out</button>
                    </form>
                </div>
            @empty
                <div class="px-8 py-6 text-sm text-gray-500">No apps are signed in.</div>
            @endforelse
        </x-card>
    </div>
</x-app-layout>
