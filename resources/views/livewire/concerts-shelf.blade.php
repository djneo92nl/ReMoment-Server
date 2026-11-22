<div data-test="concerts-shelf">
    @if($enabled && $data !== [])
        <x-shelf title="Coming up" :subtitle="$location ? 'Concerts near '.$location.', from MusicBrainz' : 'Concerts from MusicBrainz'">
            @foreach($data as $concert)
                <x-card compact class="w-64 shrink-0 snap-start p-4" data-test="concert-card">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">
                        {{ \Illuminate\Support\Carbon::parse($concert['starts_on'])->isoFormat('ddd D MMM YYYY') }}@if($concert['starts_at']) &middot; {{ $concert['starts_at'] }}@endif
                    </p>
                    <a href="{{ $concert['url'] }}" target="_blank" rel="noopener" class="mt-1 block truncate text-sm font-medium text-gray-900 hover:underline dark:text-gray-100" title="{{ $concert['name'] }}">{{ $concert['name'] }}</a>
                    @unless($single)
                        <a href="{{ route('artists.show', $concert['artist_id']) }}" class="mt-0.5 block truncate text-xs text-gray-600 hover:underline dark:text-gray-400">{{ $concert['artist_name'] }}</a>
                    @endunless
                    @if($concert['venue'] || $concert['city'])
                        <p class="mt-0.5 truncate text-xs text-gray-500" title="{{ $concert['address'] }}">{{ collect([$concert['venue'], $concert['city']])->filter()->unique()->implode(', ') }}</p>
                    @endif
                </x-card>
            @endforeach
        </x-shelf>
    @endif
</div>
