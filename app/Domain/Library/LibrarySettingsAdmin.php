<?php

namespace App\Domain\Library;

use App\Domain\Concerts\ConcertSettings;
use App\Domain\Radio\RadioSettings;
use App\Domain\Stats\PlayRetention;
use App\Domain\Storage\PlaybackLoudness;
use App\Models\Setting;

/**
 * The library settings of Settings › Storage › Library (and the history retention of the History page) for the web forms
 * and `/api/admin/library/settings`. Spotify and Last.fm connections are not here: they stay on the web.
 */
class LibrarySettingsAdmin
{
    /** @return array{leading_source: string, leading: string, add_played_tracks: bool, playback_loudness: bool, nts_enabled: bool, history_keep_months: ?int, concerts_enabled: bool, concerts_location: ?string} */
    public function current(): array
    {
        return [
            'leading_source' => LeadingSource::mode(),
            'leading' => LeadingSource::leading(),
            'add_played_tracks' => LibrarySettings::addPlayedTracks(),
            'playback_loudness' => PlaybackLoudness::enabled(),
            'nts_enabled' => RadioSettings::ntsEnabled(),
            'history_keep_months' => PlayRetention::months(),
            'concerts_enabled' => ConcertSettings::enabled(),
            'concerts_location' => ConcertSettings::location(),
        ];
    }

    /** Changes only the keys given (the web form always sends all four switches). */
    public function save(array $data): array
    {
        if (isset($data['leading_source'])) {
            Setting::set(LeadingSource::SETTING, $data['leading_source']);
        }
        if (isset($data['add_played_tracks'])) {
            Setting::set(LibrarySettings::ADD_PLAYED_TRACKS, $data['add_played_tracks'] ? '1' : '0');
        }
        if (isset($data['playback_loudness'])) {
            PlaybackLoudness::set((bool) $data['playback_loudness']);
        }
        if (isset($data['nts_enabled'])) {
            Setting::set(RadioSettings::NTS_ENABLED, $data['nts_enabled'] ? '1' : '0');
        }
        if (array_key_exists('history_keep_months', $data)) {
            PlayRetention::setMonths($data['history_keep_months'] !== null ? (int) $data['history_keep_months'] : null);
        }

        if (isset($data['concerts_enabled'])) {
            ConcertSettings::setEnabled((bool) $data['concerts_enabled']);
        }
        if (array_key_exists('concerts_location', $data)) {
            ConcertSettings::setLocation($data['concerts_location']);
        }

        return $this->current();
    }
}
