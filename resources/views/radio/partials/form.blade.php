@php
    $knownPlatforms = [
        'beoradio' => ['label' => 'B&O Radio ID', 'hint' => 'contentId from the B&O notification stream, e.g. 6336061628069729'],
        'tunein'   => ['label' => 'TuneIn ID',    'hint' => 'TuneIn station ID, e.g. s15200 — used by Sonos'],
    ];
    $existingMeta = isset($radio) ? $radio->meta->keyBy('key') : collect();
@endphp

<div class="space-y-6">
    {{-- Name --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            Station name <span class="text-red-400">*</span>
        </label>
        <input type="text" name="name"
               value="{{ old('name', $radio->name ?? '') }}"
               placeholder="e.g. Radio Fip"
               class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-stone-700 bg-white dark:bg-stone-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600 transition @error('name') border-red-400 @enderror">
        @error('name')
            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>

    {{-- Platform identifiers --}}
    <div>
        <p class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Platform identifiers</p>
        <div class="space-y-3">
            @foreach($knownPlatforms as $platform => $meta)
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">
                        {{ $meta['label'] }}
                    </label>
                    <input type="text" name="identifiers[{{ $platform }}]"
                           value="{{ old("identifiers.{$platform}", $existingMeta->get($platform)?->value ?? '') }}"
                           placeholder="{{ $meta['hint'] }}"
                           class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-stone-700 bg-white dark:bg-stone-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600 font-mono text-sm transition">
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-gray-400 dark:text-gray-600">
            Only platforms with a filled identifier will be offered for playback on matching devices. New platforms are automatically learned when you play a station.
        </p>
    </div>

    {{-- Image URL --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            Logo URL <span class="text-gray-400 dark:text-gray-600 font-normal">(optional)</span>
        </label>
        <input type="url" name="image_url"
               value="{{ old('image_url', $radio->image_url ?? '') }}"
               placeholder="https://..."
               class="w-full px-4 py-3 rounded-2xl border border-gray-200 dark:border-stone-700 bg-white dark:bg-stone-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:focus:ring-stone-600 transition @error('image_url') border-red-400 @enderror">
        @error('image_url')
            <p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>
        @enderror
    </div>
</div>
