@php
    $knownPlatforms = [
        'beoradio' => ['label' => 'B&O Radio ID', 'hint' => 'contentId from the B&O notification stream, e.g. 6336061628069729'],
        'tunein'   => ['label' => 'TuneIn ID',    'hint' => 'TuneIn station ID, e.g. s15200 — used by Sonos'],
        'url'      => ['label' => 'Stream URL',   'hint' => 'https://… plain MP3/AAC stream — any device that can play a stream; B&O and Sonos prefer their own ID above'],
    ];
    $existingMeta = isset($radio) ? $radio->meta->keyBy('key') : collect();

    // Alpine state for the fields the stream finder can fill (it dispatches `radio-url-picked` on the window).
    $initial = [
        'url' => (string) old('identifiers.url', $existingMeta->get('url')?->value ?? ''),
        'image_url' => (string) old('image_url', $radio->image_url ?? ''),
        'genre' => (string) old('genre', $radio->genre ?? ''),
    ];
@endphp

<div class="space-y-6"
     x-data="{ ...@js($initial) }"
     x-on:radio-url-picked.window="url = $event.detail.url; if ($event.detail.image_url && !image_url) image_url = $event.detail.image_url; if ($event.detail.genre && !genre) genre = $event.detail.genre">
    {{-- Name --}}
    <div>
        <x-input-label class="mb-2">Station name <span class="text-red-400">*</span></x-input-label>
        <x-text-input type="text" name="name"
               value="{{ old('name', $radio->name ?? '') }}"
               placeholder="e.g. Radio Fip"
               :invalid="$errors->has('name')" class="w-full py-3" />
        <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
    </div>

    {{-- Platform identifiers --}}
    <div>
        <x-input-label value="Platform identifiers" class="mb-3" />
        <div class="space-y-4">
            @foreach($knownPlatforms as $platform => $meta)
                <div>
                    <x-input-label class="mb-1.5">{{ $meta['label'] }}</x-input-label>
                    @if($platform === 'url')
                        <x-text-input type="text" name="identifiers[url]" x-model="url"
                               placeholder="{{ $meta['hint'] }}"
                               :invalid="$errors->has('identifiers.url')" class="w-full py-3" />
                        <x-input-error :messages="$errors->get('identifiers.url')" class="mt-1.5" />
                    @else
                        <x-text-input type="text" name="identifiers[{{ $platform }}]"
                               value="{{ old('identifiers.'.$platform, $existingMeta->get($platform)?->value ?? '') }}"
                               placeholder="{{ $meta['hint'] }}" class="w-full py-3" />
                    @endif
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-xs text-gray-400 dark:text-gray-600">
            Only platforms with a filled identifier will be offered for playback on matching devices. A device with its own radio
            (B&amp;O, Sonos) uses that ID before the stream URL. New platforms are automatically learned when you play a station.
        </p>
    </div>

    {{-- Genre --}}
    <div>
        <x-input-label class="mb-2">Genre <span class="text-gray-400 dark:text-gray-600 font-normal">(optional)</span></x-input-label>
        <x-text-input type="text" name="genre" x-model="genre" placeholder="e.g. Jazz"
               :invalid="$errors->has('genre')" class="w-full py-3" />
        <x-input-error :messages="$errors->get('genre')" class="mt-1.5" />
    </div>

    {{-- Image URL --}}
    <div>
        <x-input-label class="mb-2">Logo URL <span class="text-gray-400 dark:text-gray-600 font-normal">(optional)</span></x-input-label>
        <x-text-input type="url" name="image_url" x-model="image_url"
               placeholder="https://..."
               :invalid="$errors->has('image_url')" class="w-full py-3" />
        <x-input-error :messages="$errors->get('image_url')" class="mt-1.5" />
    </div>
</div>
