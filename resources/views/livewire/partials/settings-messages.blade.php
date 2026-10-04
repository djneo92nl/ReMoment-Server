@if(!empty($problems[$section]))
    <div class="mb-4 rounded-2xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 px-5 py-3 text-sm text-red-800 dark:text-red-300">{{ $problems[$section] }}</div>
@endif
@if(!empty($notices[$section]))
    <div class="mb-4 rounded-2xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-5 py-3 text-sm text-emerald-800 dark:text-emerald-300">{{ $notices[$section] }}</div>
@endif
