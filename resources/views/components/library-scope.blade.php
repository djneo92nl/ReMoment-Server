@props(['scope'])

@php
    $group = \App\Domain\Library\LeadingSource::group($scope);
    // Only services that hold something (plus the one being viewed), so an empty Spotify/DLNA isn't offered.
    $services = $group === 'streaming'
        ? array_values(array_filter(
            \App\Domain\Library\LibrarySources::libraryIdsOfKind('streaming'),
            fn ($id) => $id === $scope || \App\Domain\Library\LibrarySources::availableFrom(\App\Models\Media\Track::query(), $id)->exists(),
        ))
        : [];
@endphp

<div>

<div class="inline-flex rounded-xl border border-gray-200 dark:border-stone-700 bg-white dark:bg-stone-900 p-0.5 text-sm" role="group" aria-label="Library scope">
    @foreach(\App\Domain\Library\LeadingSource::labels() as $key => $label)
        <a href="{{ request()->fullUrlWithQuery(['scope' => $key, 'page' => null]) }}"
           @class([
               'px-3 py-1.5 rounded-[10px] transition-colors',
               'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $group === $key,
               'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200' => $group !== $key,
           ])>{{ $label }}</a>
    @endforeach
</div>

{{-- Streaming: narrow it down to one service --}}
@if(count($services) > 1)
    <div class="mt-2 inline-flex flex-wrap gap-1 text-sm" role="group" aria-label="Streaming service">
        @foreach(['streaming' => 'All streaming'] + array_map(fn ($id) => \App\Domain\Library\LibrarySources::LIBRARIES[$id]['name'], array_combine($services, $services)) as $key => $label)
            <a href="{{ request()->fullUrlWithQuery(['scope' => $key, 'page' => null]) }}"
               @class([
                   'px-3 py-1 rounded-full border transition-colors',
                   'border-gray-900 bg-gray-900 text-white dark:border-gray-100 dark:bg-gray-100 dark:text-gray-900' => $scope === $key,
                   'border-gray-200 dark:border-stone-700 text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200' => $scope !== $key,
               ])>{{ $label }}</a>
        @endforeach
    </div>
@endif
</div>
