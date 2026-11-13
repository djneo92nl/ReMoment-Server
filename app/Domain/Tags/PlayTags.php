<?php

namespace App\Domain\Tags;

use App\Mcp\ApiBridge;
use App\Models\Device;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\PhysicalItem;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use App\Models\PlayTag;
use App\Models\RadioStation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Tap to play: a tag uid (NFC) or QR code mapped to something to play. Playing goes through the REST API
 * in-process (ApiBridge), so a tag gets exactly the play endpoints' guards, errors and profile gates.
 */
final class PlayTags
{
    /** @var array<string, class-string<Model>> */
    public const TYPES = [
        'album' => Album::class,
        'artist' => Artist::class,
        'playlist' => Playlist::class,
        'track' => Track::class,
        'radio' => RadioStation::class,
        'physical' => PhysicalItem::class,
    ];

    private const LAST_SCANNED = 'play_tags:last_scanned';

    /** Readers format a uid differently ("04:a3:1f", "04A31F"): compare letters and digits only, upper case. */
    public static function normalise(string $uid): string
    {
        return mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $uid) ?? ''), 0, 64);
    }

    public static function find(string $uid): ?PlayTag
    {
        $uid = self::normalise($uid);

        return $uid === '' ? null : PlayTag::where('uid', $uid)->first();
    }

    /** A new uid for a QR code, which has no chip to read one from. */
    public static function newUid(): string
    {
        do {
            $uid = 'QR'.strtoupper(bin2hex(random_bytes(5)));
        } while (PlayTag::where('uid', $uid)->exists());

        return $uid;
    }

    /** An unknown uid that was just tapped, so the admin can assign it without typing it (10 minutes). */
    public static function rememberUnknown(string $uid): void
    {
        $uid = self::normalise($uid);
        if ($uid !== '') {
            Cache::put(self::LAST_SCANNED, ['uid' => $uid, 'at' => now()->toIso8601String()], 600);
        }
    }

    /** @return array{uid: string, at: string}|null */
    public static function lastScanned(): ?array
    {
        $last = Cache::get(self::LAST_SCANNED);

        return is_array($last) && !PlayTag::where('uid', $last['uid'])->exists() ? $last : null;
    }

    public static function target(PlayTag $tag): ?Model
    {
        $class = self::TYPES[$tag->target_type] ?? null;

        return $class ? $class::find($tag->target_id) : null;
    }

    public static function targetName(string $type, ?Model $target): ?string
    {
        return match (true) {
            $target === null => null,
            $type === 'album' => trim(($target->artist?->name ? $target->artist->name.' – ' : '').$target->name),
            $type === 'track' => trim(($target->artist?->name ? $target->artist->name.' – ' : '').$target->name),
            $type === 'physical' => $target->artist.' – '.$target->title,
            default => $target->name,
        };
    }

    /** The API shape of a tag. */
    public static function item(PlayTag $tag): array
    {
        $target = self::target($tag);

        return [
            'id' => $tag->id,
            'uid' => $tag->uid,
            'label' => $tag->label,
            'target' => ['type' => $tag->target_type, 'id' => $tag->target_id, 'name' => self::targetName($tag->target_type, $target), 'exists' => $target !== null],
            'device' => $tag->device ? ['id' => $tag->device->id, 'name' => $tag->device->device_name] : null,
            'shuffle' => $tag->shuffle,
            'last_used_at' => $tag->last_used_at?->toIso8601String(),
            'url' => url('/t/'.$tag->uid),
        ];
    }

    /**
     * Play the tag's target on the device.
     *
     * @return array{0: int, 1: array} HTTP status and JSON body of the play endpoint that ran
     */
    public static function play(PlayTag $tag, Device $device): array
    {
        $target = self::target($tag);
        if ($target === null) {
            return [422, ['error' => 'not_playable', 'message' => 'What this tag pointed to is no longer there.']];
        }

        $shuffle = $tag->shuffle ? ['shuffle' => true] : [];
        $base = "devices/{$device->id}";

        if ($tag->target_type === 'physical') {
            if (!$target->album_id) {
                return [422, ['error' => 'not_playable', 'message' => 'This item has no album in the library to play.']];
            }
            $call = ["$base/library/play-album", ['album_id' => $target->album_id] + $shuffle];
        } else {
            $call = match ($tag->target_type) {
                'album' => ["$base/library/play-album", ['album_id' => $target->id] + $shuffle],
                'artist' => ["$base/library/play-artist", ['artist_id' => $target->id] + $shuffle],
                'playlist' => ["$base/library/play-playlist", ['playlist_id' => $target->id] + $shuffle],
                'track' => ["$base/library/play", ['track_id' => $target->id]],
                'radio' => ["$base/radio/{$target->id}", []],
            };
        }

        [$status, $body] = app(ApiBridge::class)->call('POST', ...$call);

        if ($status >= 200 && $status < 300) {
            $tag->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return [$status, $body];
    }
}
