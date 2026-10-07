<?php

namespace App\Domain\Media;

use App\Domain\Device\State;

/** The two lines that say what a device plays, for the compact players (player bar, player rows). */
final class NowPlayingLabel
{
    /**
     * @param  string  $idle  the title when nothing is known and the device is neither in standby nor unreachable
     * @return array{title: string, subtitle: string}
     */
    public static function for(?NowPlaying $nowPlaying, ?State $state, string $idle = '—'): array
    {
        $track = $nowPlaying?->track;
        $radio = $nowPlaying?->radio;

        if ($track) {
            return [
                'title' => (string) $track->name,
                'subtitle' => collect([$track->artist?->name, $nowPlaying->album?->name ?? $radio?->name])->filter()->implode(' · '),
            ];
        }

        if ($radio) {
            return ['title' => (string) $radio->name, 'subtitle' => 'Radio'];
        }

        if ($nowPlaying?->source) {
            return ['title' => (string) $nowPlaying->source->name, 'subtitle' => (string) $nowPlaying->source->sourceType];
        }

        return [
            'title' => match ($state) {
                State::Standby => 'Standby',
                State::Unreachable => 'Unreachable',
                default => $idle,
            },
            'subtitle' => '',
        ];
    }
}
