<x-guest-layout>
    @if(!$tag)
        <div class="text-center">
            <h1 class="font-medium text-gray-700 dark:text-gray-300">Unknown tag</h1>
            <p class="mt-1 text-sm text-gray-500">This tag is not assigned to anything yet. An admin can assign it under Settings › Tags.</p>
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-600 break-all">{{ $uid }}</p>
        </div>
    @elseif(isset($devices))
        <h1 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $name }}</h1>
        <p class="mt-1 text-sm text-gray-500">Where should it play?</p>
        <ul class="mt-5 space-y-2">
            @forelse($devices as $choice)
                <li>
                    <a href="{{ route('tags.play', ['uid' => $tag->uid, 'device' => $choice->id]) }}"
                       class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 dark:border-stone-700 px-4 py-3 text-sm text-gray-800 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-stone-800">
                        <span class="truncate">{{ $choice->device_name }}</span>
                        <i class="fa-solid fa-play text-xs text-gray-400"></i>
                    </a>
                </li>
            @empty
                <li class="text-sm text-gray-500">There are no devices yet.</li>
            @endforelse
        </ul>
    @else
        @php($failed = !empty($error))
        <div class="text-center">
            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-100 dark:bg-stone-800">
                <i class="fa-solid {{ $failed ? 'fa-triangle-exclamation' : 'fa-play' }} text-2xl text-gray-300 dark:text-stone-600"></i>
            </div>
            <h1 class="font-medium text-gray-700 dark:text-gray-300">{{ $name }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $failed ? $error : 'Playing on '.$device->device_name.'.' }}</p>
            <a href="{{ route('devices.show', $device) }}" class="mt-5 inline-block text-sm text-gray-600 dark:text-gray-400 underline">Open {{ $device->device_name }}</a>
        </div>
    @endif
</x-guest-layout>
