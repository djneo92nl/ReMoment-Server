<?php

namespace App\Domain\Library;

use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use App\Models\Device;
use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Listening modes: ready-made smart playlist definitions (Focus, Party, Sleep, Workout) over what the audio
 * analysis knows of the own files (tempo, energy, danceability, mood, instrumentalness, loudness). Each is a
 * plain `{match, rules, sort, limit}` definition for SmartPlaylistBuilder, so a new mode needs one entry here and
 * nothing else. Not to be confused with a device's `modes` (shuffle/repeat/liked) or `PlaylistMode`.
 *
 * The thresholds are starting points: change them here, not in a client. Modes only match analysed own files,
 * so one with fewer than MIN_TRACKS matches is left out instead of offering a playlist of two songs.
 */
final class ListeningModes
{
    /** Tracks a mode needs before it is offered. */
    public const MIN_TRACKS = 5;

    /** Tracks a mode plays. */
    public const LIMIT = 100;

    private const TTL = 600;

    /**
     * Key => name, description, Font Awesome icon and the rules.
     *
     * @return array<string, array{name: string, description: string, icon: string, definition: array}>
     */
    public static function all(): array
    {
        return [
            'focus' => [
                'name' => 'Focus',
                'description' => 'Instrumental and calm, nothing to sing along to.',
                'icon' => 'bullseye',
                'definition' => self::define([
                    ['instrumental', 'yes'],
                    ['energy', 'lte', 60],
                ]),
            ],
            'party' => [
                'name' => 'Party',
                'description' => 'Danceable, upbeat and positive, between 100 and 140 BPM.',
                'icon' => 'champagne-glasses',
                'definition' => self::define([
                    ['danceability', 'gte', 80],
                    ['energy', 'gte', 50],
                    ['positivity', 'gte', 55],
                    ['bpm', 'gte', 100],
                    ['bpm', 'lte', 140],
                ]),
            ],
            'sleep' => [
                'name' => 'Sleep',
                'description' => 'Relaxed, slow and low in energy.',
                'icon' => 'moon',
                'definition' => self::define([
                    ['mood', 'is', 'relaxed'],
                    ['energy', 'lte', 50],
                    ['danceability', 'lte', 70],
                    ['bpm', 'lte', 125],
                ]),
            ],
            'workout' => [
                'name' => 'Workout',
                'description' => 'Fast, loud and danceable, 125 to 170 BPM.',
                'icon' => 'person-running',
                'definition' => self::define([
                    ['bpm', 'gte', 125],
                    ['bpm', 'lte', 170],
                    ['energy', 'gte', 50],
                    ['danceability', 'gte', 70],
                    ['loudness', 'gte', -9],
                ]),
            ],
        ];
    }

    /** @return array{name: string, description: string, icon: string, definition: array}|null */
    public static function find(mixed $key): ?array
    {
        return is_string($key) ? (self::all()[$key] ?? null) : null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The modes the library can serve now, each with how many tracks match (cached a few minutes).
     *
     * @return array<string, array{key: string, name: string, description: string, icon: string, track_count: int}>
     */
    public static function available(): array
    {
        $counts = Cache::remember('listening-modes:counts:'.LeadingSource::defaultScope(), self::TTL, fn () => array_map(
            fn (array $mode) => SmartPlaylistBuilder::count($mode['definition']),
            self::all(),
        ));

        $available = [];
        foreach (self::all() as $key => $mode) {
            if (($counts[$key] ?? 0) >= self::MIN_TRACKS) {
                $available[$key] = ['key' => $key, 'name' => $mode['name'], 'description' => $mode['description'], 'icon' => $mode['icon'], 'track_count' => min($counts[$key], self::LIMIT)];
            }
        }

        return $available;
    }

    /**
     * The tracks a mode plays, in random order, limited to what $device can play when given.
     *
     * @return Builder<Track>
     */
    public static function tracks(string $key, ?Device $device = null): Builder
    {
        $definition = self::find($key)['definition'] ?? throw new \InvalidArgumentException("Unknown listening mode {$key}");

        $query = SmartPlaylistBuilder::query($definition);
        if ($device !== null) {
            LibraryPlayback::playableTracks($query, $device);
        }

        return $query->inRandomOrder()->limit(self::LIMIT);
    }

    /**
     * @param  list<array{0: string, 1: string, 2?: string|int}>  $rules  [field, operator, value?]
     */
    private static function define(array $rules): array
    {
        return SmartPlaylistBuilder::normalize([
            'match' => 'all',
            'rules' => array_map(fn (array $r) => ['field' => $r[0], 'operator' => $r[1], 'value' => $r[2] ?? ''], $rules),
            'sort' => 'random',
            'limit' => self::LIMIT,
        ]);
    }
}
