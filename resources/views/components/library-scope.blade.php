@props(['scope'])

<div class="inline-flex rounded-xl border border-gray-200 dark:border-stone-700 bg-white dark:bg-stone-900 p-0.5 text-sm" role="group" aria-label="Library scope">
    @foreach(\App\Domain\Library\LeadingSource::labels() as $key => $label)
        <a href="{{ request()->fullUrlWithQuery(['scope' => $key, 'page' => null]) }}"
           @class([
               'px-3 py-1.5 rounded-[10px] transition-colors',
               'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $scope === $key,
               'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200' => $scope !== $key,
           ])>{{ $label }}</a>
    @endforeach
</div>
