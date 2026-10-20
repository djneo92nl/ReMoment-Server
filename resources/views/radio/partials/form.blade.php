@php
    $knownPlatforms = [
        'beoradio' => ['label' => 'B&O Radio ID', 'hint' => 'contentId from the B&O notification stream, e.g. 6336061628069729'],
        'tunein'   => ['label' => 'TuneIn ID',    'hint' => 'TuneIn station ID, e.g. s15200 — used by Sonos'],
        'url'      => ['label' => 'Stream URL',   'hint' => 'https://… plain MP3/AAC stream — any device that can play a stream; B&O and Sonos prefer their own ID above'],
    ];
    $existingMeta = isset($radio) ? $radio->meta->keyBy('key') : collect();
@endphp

<div class="space-y-6">
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
        <div class="space-y-3">
            @foreach($knownPlatforms as $platform => $meta)
                <div>
                    <x-input-label class="mb-1.5">{{ $meta['label'] }}</x-input-label>
                    <x-text-input type="text" name="identifiers[{{ $platform }}]"
                           value="{{ old("identifiers.{$platform}", $existingMeta->get($platform)?->value ?? '') }}"
                           placeholder="{{ $meta['hint'] }}" class="w-full py-3" />
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-gray-400 dark:text-gray-600">
            Only platforms with a filled identifier will be offered for playback on matching devices. New platforms are automatically learned when you play a station.
        </p>
    </div>

    {{-- Image URL --}}
    <div>
        <x-input-label class="mb-2">Logo URL <span class="text-gray-400 dark:text-gray-600 font-normal">(optional)</span></x-input-label>
        <x-text-input type="url" name="image_url"
               value="{{ old('image_url', $radio->image_url ?? '') }}"
               placeholder="https://..."
               :invalid="$errors->has('image_url')" class="w-full py-3" />
        <x-input-error :messages="$errors->get('image_url')" class="mt-1.5" />
    </div>
</div>
