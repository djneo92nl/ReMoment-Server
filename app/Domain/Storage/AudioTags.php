<?php

namespace App\Domain\Storage;

/** Tags and audio properties of a local audio file (getID3), as one flat array; null when it isn't audio. */
class AudioTags
{
    public const EXTENSIONS = ['mp3', 'flac', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'aif', 'aiff', 'wma'];

    public static function isAudio(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * @return array{artist:?string,album_artist:?string,album:?string,title:?string,year:?string,track_number:?int,disc_number:?int,genres:list<string>,mbid:?string,duration:?int,mime_type:?string,codec:?string,bitrate:?int,sample_rate:?int,bit_depth:?int,channels:?int,replaygain_track_gain:?float,replaygain_album_gain:?float,picture:?array{mime:?string,data:string}}|null
     */
    public static function read(string $localPath): ?array
    {
        $info = (new \getID3)->analyze($localPath);
        \getid3_lib::CopyTagsToComments($info);

        if (empty($info['audio'])) {
            return null;
        }

        $tag = fn (string $key) => trim((string) ($info['comments'][$key][0] ?? '')) ?: null;
        $audio = $info['audio'];
        $int = fn ($v) => isset($v) && is_numeric($v) ? (int) round($v) : null;
        // The embedded cover: the front cover when the file says which one it is, else the first picture.
        $pictures = array_values(array_filter($info['comments']['picture'] ?? [], fn ($p) => !empty($p['data'])));
        $front = collect($pictures)->first(fn ($p) => stripos((string) ($p['picturetype'] ?? ''), 'front') !== false) ?? ($pictures[0] ?? null);
        $gain = fn ($v) => isset($v) && is_numeric($v) ? round((float) $v, 2) : null;

        return [
            'artist' => $tag('artist'),
            'album_artist' => $tag('band') ?? $tag('albumartist'), // ID3 TPE2 (getID3's `band`) or the Vorbis ALBUMARTIST of a FLAC/Ogg
            'album' => $tag('album'),
            'title' => $tag('title'),
            'year' => $tag('year') ?? $tag('date'),
            'track_number' => ((int) $tag('track_number')) ?: null,
            'disc_number' => ((int) ($tag('part_of_a_set') ?? $tag('discnumber'))) ?: null, // ID3 TPOS or Vorbis DISCNUMBER ("1" or "1/2")
            'genres' => array_values(array_filter($info['comments']['genre'] ?? [])),
            'mbid' => $info['tags']['id3v2']['text']['MusicBrainz Release Track Id'][0] ?? $tag('musicbrainz_trackid'),
            'duration' => $int($info['playtime_seconds'] ?? null),
            'mime_type' => $info['mime_type'] ?? null,
            'codec' => $audio['dataformat'] ?? null,
            'bitrate' => $int($audio['bitrate'] ?? null),
            'sample_rate' => $int($audio['sample_rate'] ?? null),
            'bit_depth' => $int($audio['bits_per_sample'] ?? null),
            'channels' => $int($audio['channels'] ?? null),
            'replaygain_track_gain' => $gain($info['replay_gain']['track']['adjustment'] ?? null),
            'replaygain_album_gain' => $gain($info['replay_gain']['album']['adjustment'] ?? null),
            'picture' => $front ? ['mime' => $front['image_mime'] ?? null, 'data' => $front['data']] : null,
        ];
    }
}
