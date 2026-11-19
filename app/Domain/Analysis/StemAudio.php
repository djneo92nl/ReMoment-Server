<?php

namespace App\Domain\Analysis;

use App\Domain\Analysis\Stages\StemsStage;
use App\Models\File;
use App\Models\FileAnalysis;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * The kept audio of the stems of one track (`stem_audio` kind of DeepAnalysis): four files (vocals, drums, bass, other; six with guitar and piano for the `htdemucs_6s` model),
 * MP3 or FLAC, in `stems/{file id}` on `analysis.stem_audio_disk`. They are derived data, not library files: no scan
 * indexes them and no storage location owns them. The manifest in `file_analyses.stem_audio` says what is there; a file
 * whose content changed forgets it (`FileAnalysis::resetFor`) and `stems:prune` removes the directory that is left.
 * See docs/architecture/stems-service.md.
 */
final class StemAudio
{
    public const FORMATS = ['mp3' => 'audio/mpeg', 'flac' => 'audio/flac'];

    public static function disk(): Filesystem
    {
        return Storage::disk(config('analysis.stem_audio_disk'));
    }

    /** The configured format, mp3 when the setting is anything else. */
    public static function format(): string
    {
        $format = strtolower((string) config('analysis.stem_audio_format'));

        return array_key_exists($format, self::FORMATS) ? $format : 'mp3';
    }

    public static function mime(string $format): string
    {
        return self::FORMATS[$format] ?? 'application/octet-stream';
    }

    public static function directory(File $file): string
    {
        return 'stems/'.$file->id;
    }

    public static function path(File $file, string $name): string
    {
        return self::directory($file).'/'.$name;
    }

    /** Removes the kept audio of a file. */
    public static function delete(File $file): void
    {
        self::disk()->deleteDirectory(self::directory($file));
    }

    /** Whether the manifest is there and every file of it still exists on the disk. */
    public static function complete(File $file, ?FileAnalysis $analysis): bool
    {
        $manifest = $analysis?->stem_audio;
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            return false;
        }

        $stems = array_keys($manifest['files']);
        if (array_diff(StemsStage::REQUIRED, $stems) !== []) {
            return false;
        }

        foreach ($stems as $stem) {
            $name = $manifest['files'][$stem]['name'] ?? null;
            if (!is_string($name) || !self::disk()->exists(self::path($file, $name))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unpacks the service's zip (`stems.json` and one file per stem) into the file's directory, replacing what was
     * there, and returns the service's JSON with the manifest of what was kept.
     *
     * @return array{data: array, manifest: array}
     */
    public static function store(File $file, string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('The stems service answered something that is not a zip file.');
        }

        try {
            $json = $zip->getFromName('stems.json');
            $data = $json === false ? null : json_decode($json, true);
            $audio = is_array($data) ? ($data['audio'] ?? null) : null;
            if (!is_array($audio) || !is_array($audio['files'] ?? null)) {
                throw new \RuntimeException('The stems service answered without stems.json or its audio block.');
            }

            $format = (string) ($audio['format'] ?? '');
            if (!array_key_exists($format, self::FORMATS)) {
                throw new \RuntimeException("The stems service answered an unknown audio format ({$format}).");
            }

            self::delete($file);
            $files = [];
            $bytes = 0;

            $stems = StemsStage::ordered(array_keys($audio['files']));
            if (array_diff(StemsStage::REQUIRED, $stems) !== []) {
                throw new \RuntimeException('The stems service answered without all of the vocals, drums, bass and other files.');
            }

            foreach ($stems as $stem) {
                $name = basename((string) ($audio['files'][$stem] ?? ''));
                $stream = $name === '' ? false : $zip->getStream($name);
                if ($stream === false) {
                    self::delete($file);
                    throw new \RuntimeException("The stems service answered without the {$stem} file.");
                }

                self::disk()->writeStream(self::path($file, $name), $stream);
                is_resource($stream) && fclose($stream);

                $size = (int) self::disk()->size(self::path($file, $name));
                if ($size <= 0) {
                    self::delete($file);
                    throw new \RuntimeException("The {$stem} file of the stems service is empty.");
                }

                $files[$stem] = ['name' => $name, 'bytes' => $size];
                $bytes += $size;
            }
        } finally {
            $zip->close();
        }

        return ['data' => $data, 'manifest' => [
            'format' => $format,
            'bitrate' => isset($audio['bitrate']) ? (int) $audio['bitrate'] : null,
            'sample_rate' => (int) ($audio['sample_rate'] ?? 44100),
            'channels' => (int) ($audio['channels'] ?? 2),
            'duration' => round((float) ($data['duration'] ?? 0), 2),
            'service_version' => (int) ($data['version'] ?? 1),
            'bytes' => $bytes,
            'files' => $files,
        ]];
    }

    /** Removes the directories of files that no longer have kept stem audio; returns how many. */
    public static function pruneOrphans(bool $dryRun = false): int
    {
        $kept = FileAnalysis::query()->whereNotNull('stem_audio')->pluck('file_id')->map(fn ($id) => (string) $id)->all();
        $removed = 0;

        foreach (self::disk()->directories('stems') as $directory) {
            if (!in_array(basename($directory), $kept, true)) {
                $dryRun || self::disk()->deleteDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }
}
