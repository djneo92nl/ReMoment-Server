<?php

namespace App\Domain\Storage;

use App\Domain\Analysis\Tools;
use Illuminate\Support\Facades\Process;

/**
 * Writes tags and an embedded cover into a copy of an audio file with ffmpeg (`-c copy`: the audio is not re-encoded, every
 * other tag and picture is carried over), then reads the copy back with getID3 — the library the indexer reads with — and
 * refuses a result that does not hold what was asked. The caller (TagEditor) replaces the original only after this passes.
 *
 * The earlier getID3 writer replaced every tag frame it was not given (cover, MusicBrainz ids, ReplayGain); this one does not.
 * ffmpeg is in the app image; without it nothing is written (an exact error, never a partial write).
 */
final class TagWriter
{
    /** Editable fields => the ffmpeg metadata key. */
    public const FIELDS = [
        'title' => 'title', 'artist' => 'artist', 'album' => 'album', 'album_artist' => 'album_artist', 'year' => 'date',
        'track_number' => 'track', 'disc_number' => 'disc', 'genre' => 'genre',
    ];

    /** Formats ffmpeg rewrites tags of with `-c copy`. m4a and ogg are written the same way but not exercised by the tests. */
    private const EXTENSIONS = ['mp3', 'flac', 'ogg', 'oga', 'opus', 'm4a'];

    /** Formats that hold an embedded picture ffmpeg can attach (an Ogg or Opus picture is a base64 comment: not written). */
    private const COVER_EXTENSIONS = ['mp3', 'flac', 'm4a'];

    public static function supports(string $extension): bool
    {
        return in_array(strtolower($extension), self::EXTENSIONS, true);
    }

    public static function supportsCover(string $extension): bool
    {
        return in_array(strtolower($extension), self::COVER_EXTENSIONS, true);
    }

    public static function available(): bool
    {
        return Tools::available(Tools::ffmpeg());
    }

    /**
     * @param  array<string, string|null>  $tags  field => value; null leaves the field alone, '' removes it
     * @param  array{data: string, mime: ?string}|false|null  $cover  null keeps the picture, false removes it, an array replaces it
     */
    public function write(string $source, string $output, array $tags, array|false|null $cover): void
    {
        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));

        if (!self::supports($extension)) {
            throw new \InvalidArgumentException("Writing tags to .{$extension} files is not supported.");
        }
        if ($cover !== null && !self::supportsCover($extension)) {
            throw new \InvalidArgumentException("A cover cannot be written into .{$extension} files.");
        }
        if (!self::available()) {
            throw new \RuntimeException('Writing tags needs ffmpeg, which is not available on this server.');
        }

        $coverFile = null;
        $command = [Tools::ffmpeg(), '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $source];

        try {
            if (is_array($cover)) {
                $coverFile = tempnam(sys_get_temp_dir(), 'remoment-cover-');
                file_put_contents($coverFile, $cover['data']);
                $imageFile = $coverFile.(($cover['mime'] ?? '') === 'image/png' ? '.png' : '.jpg');
                rename($coverFile, $imageFile);
                $coverFile = $imageFile;
                array_push($command, '-i', $imageFile, '-map', '0:a', '-map', '1', '-disposition:v:0', 'attached_pic',
                    '-metadata:s:v', 'title=Cover', '-metadata:s:v', 'comment=Cover (front)');
            } elseif ($cover === false) {
                array_push($command, '-map', '0:a');
            } else {
                array_push($command, '-map', '0');
            }

            array_push($command, '-c', 'copy', '-map_metadata', '0');
            if ($extension === 'mp3') {
                array_push($command, '-id3v2_version', '3', '-write_id3v1', '0');
            }
            foreach (self::FIELDS as $field => $key) {
                if (array_key_exists($field, $tags) && $tags[$field] !== null) {
                    $command[] = '-metadata';
                    $command[] = $key.'='.$tags[$field];
                }
            }
            $command[] = $output;

            $result = Process::timeout((int) config('analysis.ffmpeg_timeout', 300))->run($command);
            if (!$result->successful()) {
                throw new \RuntimeException('ffmpeg could not write the tags: '.trim(substr($result->errorOutput(), -300)));
            }
        } finally {
            if ($coverFile !== null) {
                @unlink($coverFile);
            }
        }

        $this->verify($output, $tags, $cover);
    }

    /** The written copy must read back (getID3) with the asked tags and cover, and still be audio. */
    private function verify(string $output, array $tags, array|false|null $cover): void
    {
        $read = AudioTags::read($output);
        if ($read === null) {
            throw new \RuntimeException('The new version of the file does not read as audio; the original was kept.');
        }

        foreach ($tags as $field => $wanted) {
            if ($wanted === null || !array_key_exists($field, self::FIELDS)) {
                continue;
            }

            $got = self::fieldValue($read, $field);
            $ok = $wanted === '' ? ($got === null || $got === '') : ($field === 'genre' ? $got !== null && $got !== '' : mb_strtolower(trim((string) $got)) === mb_strtolower(trim($wanted)));

            if (!$ok) {
                throw new \RuntimeException("The new version of the file does not hold the {$field} that was asked for; the original was kept.");
            }
        }

        if (is_array($cover) && empty($read['picture']['data'])) {
            throw new \RuntimeException('The cover was not embedded; the original was kept.');
        }
        if ($cover === false && !empty($read['picture']['data'])) {
            throw new \RuntimeException('The cover was not removed; the original was kept.');
        }
    }

    /** One editable field of an AudioTags::read() result as a string (null when absent). */
    public static function fieldValue(array $read, string $field): ?string
    {
        $value = match ($field) {
            'genre' => implode('; ', $read['genres'] ?? []),
            'track_number', 'disc_number' => $read[$field] ?? null,
            default => $read[$field] ?? null,
        };

        return $value === null || $value === '' ? null : (string) $value;
    }
}
