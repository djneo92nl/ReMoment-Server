@php
    $dot = ['ok' => 'bg-emerald-500', 'warning' => 'bg-amber-500', 'error' => 'bg-red-500', 'disabled' => 'bg-gray-400'];
    $text = ['ok' => 'text-emerald-600 dark:text-emerald-500', 'warning' => 'text-amber-600 dark:text-amber-500', 'error' => 'text-red-600 dark:text-red-500', 'disabled' => 'text-gray-500'];
    $label = ['ok' => 'OK', 'warning' => 'Warning', 'error' => 'Failing', 'disabled' => 'Not set up'];
@endphp

<div class="bg-white dark:bg-stone-900 rounded-3xl shadow-lg border border-gray-200/70 dark:border-stone-800/80 overflow-hidden">
    <div class="px-8 py-5 border-b border-gray-100 dark:border-stone-800 flex items-center justify-between gap-4">
        <div>
            <h2 class="text-base font-medium text-gray-900 dark:text-gray-100">External services</h2>
            <div class="text-xs text-gray-500 mt-0.5">
                @if($checkedAt)
                    Checked {{ $checkedAt->diffForHumans() }}
                    @if($problems) · <span class="text-red-500">{{ $problems }} {{ \Illuminate\Support\Str::plural('problem', $problems) }}</span>@endif
                @else
                    Not checked yet
                @endif
            </div>
        </div>
        <button wire:click="check" wire:loading.attr="disabled" class="px-4 py-2 rounded-xl text-sm font-medium bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 disabled:opacity-50">
            <span wire:loading.remove wire:target="check"><i class="fa-solid fa-rotate mr-1.5"></i>Check now</span>
            <span wire:loading wire:target="check"><i class="fa-solid fa-spinner fa-spin mr-1.5"></i>Checking…</span>
        </button>
    </div>

    @forelse($groups as $group => $services)
        <div class="px-8 pt-4 pb-1 text-xs font-medium uppercase tracking-wider text-gray-400 dark:text-gray-600">{{ $group }}</div>
        @foreach($services as $service)
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-x-4 gap-y-1 items-center px-8 py-3 border-b border-gray-50 dark:border-stone-800/50 last:border-0 text-sm">
                <div class="sm:col-span-4 font-medium text-gray-900 dark:text-gray-100">{{ $service['name'] }}</div>
                <div class="sm:col-span-2 inline-flex items-center gap-1.5 font-medium {{ $text[$service['status']] }}">
                    <span class="w-2 h-2 rounded-full {{ $dot[$service['status']] }}"></span>{{ $label[$service['status']] }}
                </div>
                <div class="sm:col-span-6 text-xs text-gray-500 break-words">{{ $service['message'] }}</div>
            </div>
        @endforeach
    @empty
        <div class="px-8 py-6 text-sm text-gray-500">Press “Check now” to test every service and credential.</div>
    @endforelse
</div>
