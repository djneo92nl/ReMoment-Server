{{-- Opt-in browser notifications for alerts (docs/frontend/pwa.md). State lives in this browser's localStorage. --}}
<x-card class="p-5" x-data="alertNotifications" data-test="alert-notifications" data-vapid-key="{{ \App\Domain\Push\Vapid::publicKey() }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100"><i class="fa-solid fa-bell mr-1.5"></i>Alert notifications in this browser</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Shows a notification for warning and critical alerts while a ReMoment tab or the installed app is open. Turn on background notifications below to get them when the browser is closed too.
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

    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-stone-800 flex flex-wrap items-center justify-between gap-3" data-test="push-notifications">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Also when the browser is closed</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Background push: the server sends warning and critical alerts through your browser's push service (Google, Mozilla, Apple or Microsoft), so they arrive with no tab open. This browser's address at that service is stored here; there are no accounts.
                It needs <strong>https</strong>. On an iPhone or iPad the app must be installed first (Share › Add to Home Screen) and opened from the home screen once.
            </p>
            <p x-show="!pushSupported" x-cloak class="mt-1 text-sm text-amber-800 dark:text-amber-300">This browser cannot do background push here (it needs https or localhost, and on iOS the installed app).</p>
            <p x-show="pushError" x-cloak x-text="pushError" class="mt-1 text-sm text-amber-800 dark:text-amber-300"></p>
        </div>
        <button type="button" @click="togglePush()" :disabled="!pushSupported || pushBusy || permission === 'denied'" data-test="push-notifications-toggle"
                class="px-4 py-2 text-sm font-medium rounded-xl bg-gray-900 text-white dark:bg-stone-700 hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600"
                x-text="push ? 'Turn off background' : 'Turn on background'">Turn on background</button>
    </div>
</x-card>
