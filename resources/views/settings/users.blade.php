<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-4">
            <a href="{{ route('settings.index') }}"
               class="flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors flex-shrink-0">
                <i class="fa-solid fa-arrow-left text-sm"></i>
            </a>
            <div>
                <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Users</h1>
                <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ $users->count() }} {{ $users->count() === 1 ? 'user' : 'users' }}</p>
            </div>
        </div>
    </x-slot>

    <div class="max-w-4xl">
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

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
                    <div class="col-span-4 text-sm text-gray-500 dark:text-gray-500">{{ $user->created_at->format('M j, Y') }}</div>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>
