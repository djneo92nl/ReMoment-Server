@props(['hero', 'scope'])
@php $pinned = app(\App\Support\SelectedDevice::class)->device(); @endphp
{{-- The top of /library: the scope's name and counts, a Shuffle button, a tilted collage of the newest covers fading into the page, all tinted by the first cover. --}}
<div class="tint-hero relative overflow-hidden rounded-3xl px-6 py-7 sm:px-8 sm:py-9" style="{{ $hero['tint'] }}">
    @if($hero['covers'])
        <div class="hero-collage pointer-events-none absolute inset-y-0 right-0 w-3/5" aria-hidden="true">
            <div class="grid -translate-y-8 translate-x-10 rotate-[8deg] scale-110 grid-cols-3 gap-3 opacity-90 sm:grid-cols-4">
                @foreach($hero['covers'] as $cover)
                    <img src="{{ $cover }}" alt="" decoding="async" class="aspect-square w-full rounded-xl object-cover shadow-lg {{ $loop->odd ? 'translate-y-6' : '' }}">
                @endforeach
            </div>
        </div>
    @endif

    <div class="relative">
        <div class="mb-5"><x-library-scope :scope="$scope" /></div>
        <div class="flex items-center gap-4">
            <span class="flex h-12 w-12 flex-none items-center justify-center rounded-full bg-black/10 dark:bg-white/10"><i class="fa-solid fa-{{ $hero['icon'] }} text-lg"></i></span>
            <h1 class="text-4xl font-semibold tracking-tight sm:text-5xl">{{ $hero['name'] }}</h1>
        </div>
        <p class="mt-3 text-base opacity-70">
            {{ number_format($hero['albums']) }} {{ Str::plural('album', $hero['albums']) }} · {{ number_format($hero['artists']) }} {{ Str::plural('artist', $hero['artists']) }}
            @if($hero['playlists'] > 0) · {{ number_format($hero['playlists']) }} {{ Str::plural('playlist', $hero['playlists']) }} @endif
        </p>
        @if($hero['albums'] > 0)
            @php $shuffleClass = 'inline-flex items-center gap-2 rounded-full bg-gray-900 px-6 py-3 text-base font-medium text-white shadow-sm transition hover:bg-gray-700 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200'; @endphp
            @if($pinned)
                <form method="POST" action="{{ route('library.shuffle') }}" class="mt-6">
                    @csrf
                    <button type="submit" class="{{ $shuffleClass }}" title="Shuffle on {{ $pinned->device_name }}"><i class="fa-solid fa-shuffle"></i>Shuffle</button>
                </form>
            @else
                <button type="button" class="{{ $shuffleClass }} mt-6" @click="$dispatch('open-player-panel')" title="Choose a player to shuffle on"><i class="fa-solid fa-shuffle"></i>Shuffle</button>
            @endif
        @endif
    </div>
</div>
