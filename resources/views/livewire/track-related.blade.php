<div data-test="track-related" class="text-sm text-gray-700 dark:text-gray-300">
    @if($related === [] && $versions === [])
        <span class="text-xs text-gray-500 dark:text-gray-400" data-test="related-none">Nothing related found on MusicBrainz.</span>
    @else
        <ul class="space-y-1" data-test="related-items">
            @foreach($related as $item)
                <li data-test="related-{{ $item['relation'] }}">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $item['label'] }}</span>
                    @if($item['track'])
                        <a href="{{ route('albums.show', $item['track']['album_id']) }}#track-{{ $item['track']['id'] }}" class="hover:underline" title="In your library">{{ $item['track']['name'] }} · {{ $item['track']['artist_name'] }}</a>
                    @else
                        {{ $item['title'] }}@if($item['artist']) <span class="text-gray-500 dark:text-gray-400">· {{ $item['artist'] }}</span>@endif
                        @if($item['detail'])<span class="text-xs text-gray-500 dark:text-gray-400">({{ $item['detail'] }})</span>@endif
                    @endif
                </li>
            @endforeach
        </ul>
        @if($versions !== [])
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Other versions here</p>
            <ul class="space-y-1" data-test="related-versions">
                @foreach($versions as $v)
                    <li><a href="{{ route('albums.show', $v['album_id']) }}#track-{{ $v['id'] }}" class="hover:underline">{{ $v['name'] }} · {{ $v['artist_name'] }}</a></li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
