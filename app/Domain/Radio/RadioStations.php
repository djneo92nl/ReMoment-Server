<?php

namespace App\Domain\Radio;

use App\Models\RadioStation;
use App\Models\RadioStationMeta;

/**
 * Creating, changing and removing radio stations: the web pages (`RadioStationController`, `Livewire\RadioStationSearch`)
 * and the admin API (`Api\AdminRadioStationController`, docs/api/admin-radio.md) share this.
 *
 * A station has its own fields (`name`, `image_url`, `genre`) and identifiers, one per platform it can be played on
 * (`url` for any device that plays a stream, `beoradio`, `tunein`, ...), kept as the station's meta.
 */
class RadioStations
{
    /**
     * Creates a station, or changes one. Only the fields given are changed; `$identifiers` maps a platform to its
     * identifier, an empty value removes that identifier (on a new station it is simply not stored).
     *
     * @param  array{name?: string, image_url?: ?string, genre?: ?string}  $attributes
     * @param  array<string, mixed>  $identifiers
     */
    public static function save(?RadioStation $station, array $attributes, array $identifiers = []): RadioStation
    {
        $fields = array_intersect_key($attributes, array_flip(['name', 'image_url', 'genre']));

        $station = $station === null ? RadioStation::create($fields) : tap($station)->update($fields);

        foreach ($identifiers as $platform => $identifier) {
            $identifier = trim((string) $identifier);
            if ($identifier !== '') {
                $station->setMeta((string) $platform, $identifier);
            } else {
                $station->meta()->where('key', $platform)->delete();
                $station->unsetRelation('meta');
            }
        }

        return $station->refresh();
    }

    public static function delete(RadioStation $station): void
    {
        $station->delete();
    }

    /**
     * A station found in the radio-browser.info directory (`RadioBrowser` result): the station that already has this
     * stream URL, else a new one with its URL and directory id.
     *
     * @param  array{name: string, url: string, image_url?: ?string, genre?: ?string, uuid?: ?string}  $result
     */
    public static function addFromDirectory(array $result): RadioStation
    {
        $existing = RadioStationMeta::where('key', 'url')->where('value', $result['url'])->first()?->station;

        if ($existing !== null) {
            return $existing;
        }

        $station = RadioStation::create([
            'name' => $result['name'],
            'image_url' => $result['image_url'] ?? null,
            'genre' => $result['genre'] ?? null,
        ]);
        $station->setMeta('url', $result['url']);

        if (!empty($result['uuid'])) {
            $station->setMeta('radio_browser_uuid', $result['uuid']);
        }

        return $station;
    }

    /**
     * The station as the admin API shows it: the item every client sees (`RadioStation::apiItem()`, artwork and now playing
     * included) plus what is editable.
     *
     * @return array<string, mixed>
     */
    public static function toAdminArray(RadioStation $station): array
    {
        $station->loadMissing('meta');

        // Sorted by platform, so the answer does not depend on the order the rows were stored in.
        $identifiers = $station->meta->pluck('value', 'key')->sortKeys()->all();

        return $station->apiItem() + ['image_url' => $station->image_url, 'identifiers' => $identifiers ?: (object) []];
    }
}
