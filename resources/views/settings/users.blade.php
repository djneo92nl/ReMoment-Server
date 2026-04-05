<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('settings.index') }}"
                   class="flex items-center justify-center w-9 h-9 rounded-xl bg-gray-100 dark:bg-stone-800 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-stone-700 transition-colors flex-shrink-0">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </a>
                <div>
                    <h1 class="text-3xl md:text-4xl font-medium tracking-tight dark:text-gray-100 text-gray-900">Users</h1>
                    <p class="mt-1.5 text-gray-500 dark:text-gray-500">{{ $users->count() }} {{ $users->count() === 1 ? 'user' : 'users' }} registered</p>
                </div>
            </div>
            <a href="{{ route('register') }}"
               class="flex items-center gap-2 px-5 py-2.5 bg-gray-900 dark:bg-stone-700 text-white rounded-2xl text-sm font-medium hover:bg-gray-700 dark:hover:bg-stone-600 transition-colors">
                <i class="fa-solid fa-plus"></i>
                <span class="hidden sm:inline">Add User</span>
            </a>
        </div>
    </x-slot>

    <div class="max-w-4xl">
        <div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">

            @if($users->isEmpty())
                <div class="flex flex-col items-center justify-center py-20 text-center px-8">
                    <div class="w-16 h-16 bg-gray-100 dark:bg-stone-800 rounded-3xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-users text-2xl text-gray-300 dark:text-stone-600"></i>
                    </div>
                    <p class="text-gray-500 dark:text-gray-500">No users found.</p>
                </div>
            @else
                <!-- Table header -->
                <div class="grid grid-cols-12 gap-4 px-8 py-4 border-b border-gray-100 dark:border-stone-800">
                    <div class="col-span-5 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Name</div>
                    <div class="col-span-4 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Email</div>
                    <div class="col-span-2 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">Joined</div>
                    <div class="col-span-1"></div>
                </div>

                <!-- User rows -->
                @foreach($users as $user)
                    <div class="grid grid-cols-12 gap-4 items-center px-8 py-5 border-b border-gray-50 dark:border-stone-800/50 last:border-0 hover:bg-gray-50/50 dark:hover:bg-stone-800/20 transition-colors">
                        <div class="col-span-5 flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-gray-200 to-gray-300 dark:from-stone-700 dark:to-stone-600 flex items-center justify-center flex-shrink-0">
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</div>
                                @if($user->email_verified_at)
                                    <div class="text-xs text-gray-400 dark:text-gray-600 flex items-center gap-1">
                                        <i class="fa-solid fa-circle-check text-emerald-500 text-xs"></i> Verified
                                    </div>
                                @else
                                    <div class="text-xs text-amber-500">Unverified</div>
                                @endif
                            </div>
                        </div>
                        <div class="col-span-4 text-sm text-gray-600 dark:text-gray-400 truncate">{{ $user->email }}</div>
                        <div class="col-span-2 text-sm text-gray-500 dark:text-gray-500">{{ $user->created_at->format('M j, Y') }}</div>
                        <div class="col-span-1 flex justify-end">
                            <form method="POST" action="{{ route('settings.users.destroy', $user) }}"
                                  onsubmit="return confirm('Remove {{ addslashes($user->name) }}? This cannot be undone.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="w-8 h-8 flex items-center justify-center text-gray-300 dark:text-stone-700 hover:text-red-500 dark:hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors">
                                    <i class="fa-solid fa-trash text-sm"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</x-app-layout>
