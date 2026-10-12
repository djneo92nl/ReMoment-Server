<?php

namespace App\Services\Local;

use App\Domain\Library\Enrichment;
use App\Domain\Library\LibraryIdentity;
use App\Models\Media\Track;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Indexes audio files from a folder into the shared library, the way DlnaLibraryScanner does for a DLNA
 * server, but reading the tags from the files themselves.
 *
 * Spike: a file's track is a `dlna`-source track whose stream (`GET /stream/{track}`, served by this app)
 * is stored as `dlna_url` with metadata source `dlna:local`, so playback, library scopes and merging
 * work unchanged. The file location and its mtime/size fingerprint are metadata with source `local`.
 */
class LocalLibraryScanner
{
    public const EXTENSIONS = ['mp3', 'flac', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'aif', 'aiff', 'wma'];

    public const URL_SOURCE = 'dlna:local';

    public const FILE_SOURCE = 'local';

    private int $imported = 0;

    private int $skipped = 0;

    /**
     * @param  callable(int $imported, string $file)|null  $progress
     * @return int tracks imported or updated; files unchanged since the last scan are not counted
     */
    public function scan(string $root, ?callable $progress = null): int
    {
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            throw new \InvalidArgumentException('Not a folder.');
        }

        $this->imported = $this->skipped = 0;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }

            if ($this->importFile($file->getPathname())) {
                $this->imported++;
                if ($progress) {
                    ($progress)($this->imported, $file->getFilename());
                }
            } else {
                $this->skipped++;
            }
        }

        return $this->imported;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    /** @return bool whether the file was (re)indexed; false when unchanged or unreadable */
    private function importFile(string $path): bool
    {
        $fingerprint = filemtime($path).':'.filesize($path);
        $externalId = 'local:'.sha1($path);

        $existing = Track::where('external_id', $externalId)->first();
        if ($existing && $existing->metadata()->where('key', 'file_fingerprint')->where('source', self::FILE_SOURCE)->value('value') === $fingerprint) {
            return false;
        }

        $info = (new \getID3)->analyze($path);
        \getid3_lib::CopyTagsToComments($info);

        if (empty($info['audio'])) {
            return false;
        }

        $tag = fn (string $key) => trim((string) ($info['comments'][$key][0] ?? '')) ?: null;

        $artistName = $tag('artist') ?? $tag('band') ?? 'Unknown Artist';
        $albumArtistName = $tag('band') ?? $artistName;
        $albumName = $tag('album') ?? 'Unknown Album';
        $title = $tag('title') ?? pathinfo($path, PATHINFO_FILENAME);

        $artist = LibraryIdentity::artist($artistName, 'dlna');
        $albumArtist = $albumArtistName === $artistName ? $artist : LibraryIdentity::artist($albumArtistName, 'dlna');

        $albumData = [];
        if ($year = $tag('year') ?? $tag('date')) {
            $albumData['released_at'] = $year;
        }
        $album = LibraryIdentity::album($albumArtist, $albumName, 'dlna', $albumData);

        $track = LibraryIdentity::track($artist, $album, $title, $externalId, 'dlna', [
            'duration' => isset($info['playtime_seconds']) ? (int) round($info['playtime_seconds']) : null,
        ]);

        Enrichment::save($track, 'dlna_url', url('/stream/'.$track->id), 'url', self::URL_SOURCE);

        $audio = $info['audio'];
        $values = array_filter([
            'file_path' => [$path, 'string'],
            'file_fingerprint' => [$fingerprint, 'string'],
            'file_size' => [(string) filesize($path), 'int'],
            'mime_type' => isset($info['mime_type']) ? [$info['mime_type'], 'string'] : null,
            'bitrate' => isset($audio['bitrate']) ? [(string) (int) $audio['bitrate'], 'int'] : null,
            'sample_rate' => isset($audio['sample_rate']) ? [(string) (int) $audio['sample_rate'], 'int'] : null,
            'bit_depth' => isset($audio['bits_per_sample']) ? [(string) (int) $audio['bits_per_sample'], 'int'] : null,
            'channels' => isset($audio['channels']) ? [(string) (int) $audio['channels'], 'int'] : null,
            'track_number' => ($n = (int) $tag('track_number')) ? [(string) $n, 'int'] : null,
            'disc_number' => ($n = (int) $tag('part_of_a_set')) ? [(string) $n, 'int'] : null,
            'album_artist' => $tag('band') ? [$tag('band'), 'string'] : null,
            'genres' => ($g = array_values(array_filter($info['comments']['genre'] ?? []))) ? [json_encode($g, JSON_UNESCAPED_UNICODE), 'json'] : null,
            'mbid' => ($m = $info['tags']['id3v2']['text']['MusicBrainz Release Track Id'][0] ?? $tag('musicbrainz_trackid')) ? [$m, 'string'] : null,
        ]);

        foreach ($values as $key => [$value, $type]) {
            Enrichment::save($track, $key, $value, $type, self::FILE_SOURCE);
        }

        return true;
    }
}
