{{-- Opt-in browser notifications for alerts (docs/frontend/pwa.md). State lives in this browser's localStorage. --}}
<x-card class="p-5" x-data="alertNotifications" data-test="alert-notifications">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100"><i class="fa-solid fa-bell mr-1.5"></i>Alert notifications in this browser</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Shows a notification for warning and critical alerts while a ReMoment tab or the installed app is open. Nothing arrives when the browser is closed.
            </p>
            <p x-show="!supported" x-cloak class="mt-1 text-sm text-amber-800 dark:text-amber-300">This browser does not support notifications here (they need https or localhost).</p>
            <p x-show="supported && permission === 'denied'" x-cloak class="mt-1 text-sm text-amber-800 dark:text-amber-300">Notifications are blocked for this site; allow them in the browser's site settings.</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" x-show="enabled" x-cloak @click="test()" class="px-3 py-2 text-sm rounded-xl text-gray-500 hover:bg-gray-100 dark:hover:bg-stone-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600">Send a test</button>
            <button type="button" @click="toggle()" :disabled="!supported || permission === 'denied'" data-test="alert-notifications-toggle"
                    class="px-4 py-2 text-sm font-medium rounded-xl bg-gray-900 text-white dark:bg-stone-700 hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600"
                    x-text="enabled ? 'Turn off' : 'Turn on'">Turn on</button>
        </div>
    </div>
</x-card>
