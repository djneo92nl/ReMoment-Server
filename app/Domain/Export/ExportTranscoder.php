<?php

namespace App\Domain\Export;

use App\Domain\Analysis\Tools;
use App\Domain\Artwork\LibraryArtwork;
use App\Models\Media\Track;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * The per-file encode of an export (docs/architecture/export.md): one transcode per container (MP3, m4a, Opus) with the
 * tags, the cover and the loudness gain, and the ID3v2.3 remux of a copied MP3. Shared by `ExportRunner` (zip parts) and
 * by the sync artifacts (`Domain\Sync\SyncArtifacts`), so both make the same bytes from the same decisions.
 * `$work` is a writable directory for the temporary cover and metadata files; the caller owns it.
 */
final class ExportTranscoder
{
    /** @var array<int, string|false> album id => path of the resized cover, false = none */
    private array $covers = [];

    public function __construct(private readonly string $work) {}

    /** ReplayGain tags of the source, dropped from a file that got its gain baked in (a player would apply it twice). */
    private const GAIN_TAGS = ['replaygain_track_gain', 'replaygain_track_peak', 'replaygain_album_gain', 'replaygain_album_peak', 'replaygain_reference_loudness', 'r128_track_gain', 'r128_album_gain'];

    /**
     * One transcode per container: MP3 gets ID3v2.3 and an attached JPEG, m4a (`aac` encoder, atoms from the source
     * tags, `attached_pic` cover, `+faststart`), Opus Vorbis comments with the cover as a base64 FLAC picture block
     * (`METADATA_BLOCK_PICTURE`, handed over in an ffmetadata file because it is too long for a command line).
     */
    public function transcode(ExportItem $item, Track $track, string $source, string $target): void
    {
        $t = $item->target;
        $format = $t['codec'];
        $encoder = ExportFormats::encoder($format) ?? throw new \RuntimeException("No ffmpeg encoder for {$format}.");
        $cover = $this->cover($track, $source);
        $max = (int) config('export.cover_max_px');
        $scale = "scale=w='min({$max},iw)':h='min({$max},ih)':force_original_aspect_ratio=decrease,format=yuvj420p";
        $gain = $t['gain_db'] ?? null;

        $args = ['-i', $source];
        $metadataFile = null;

        if ($format === 'opus') {
            $cover ??= $this->embeddedCover($source, $track);
            if ($cover && ($block = $this->pictureBlock($cover))) {
                $metadataFile = $this->work.'/'.$item->trackId.'.ffmeta';
                file_put_contents($metadataFile, ";FFMETADATA1\nMETADATA_BLOCK_PICTURE=".addcslashes($block, "=;#\\\n")."\n");
                array_push($args, '-f', 'ffmetadata', '-i', $metadataFile);
            }
            array_push($args, '-map', '0:a:0', '-vn', '-map_metadata', '0');
            if ($metadataFile) {
                array_push($args, '-map_metadata', '1');
            }
        } else {
            if ($cover) {
                array_push($args, '-i', $cover, '-map', '0:a:0', '-map', '1:v:0', '-c:v', 'copy');
            } else {
                array_push($args, '-map', '0:a:0', '-map', '0:v:0?', '-c:v', 'mjpeg', '-q:v', '3', '-vf', $scale);
            }
            array_push($args, '-map_metadata', '0');
        }

        array_push($args, '-c:a', $encoder, '-b:a', ((int) $t['bitrate']).'k');
        if ($format === 'opus') {
            array_push($args, '-vbr', 'on', '-ar', (string) ($t['sample_rate'] ?: 48000)); // Opus runs at 48 kHz (or 8/12/16/24)
        } elseif ($t['sample_rate']) {
            array_push($args, '-ar', (string) $t['sample_rate']);
        }
        if ($t['stereo']) {
            array_push($args, '-ac', '2');
        }
        if ($gain !== null && abs($gain) >= 0.1) {
            array_push($args, '-af', 'volume='.number_format($gain, 1, '.', '').'dB');
        }
        if ($gain !== null) {
            foreach (self::GAIN_TAGS as $tag) {
                array_push($args, '-metadata', "{$tag}=");
            }
        }

        if ($format === 'mp3') {
            array_push($args, '-id3v2_version', '3', '-metadata:s:v', 'title=Cover', '-metadata:s:v', 'comment=Cover (front)');
        } elseif ($format === 'aac') {
            array_push($args, '-disposition:v:0', 'attached_pic', '-movflags', '+faststart');
        }
        $args[] = $target;

        try {
            $this->ffmpeg($args);
        } finally {
            if ($metadataFile) {
                @unlink($metadataFile);
            }
        }
    }

    /** The art embedded in the source file as a small baseline JPEG, or null. */
    private function embeddedCover(string $source, Track $track): ?string
    {
        $out = $this->work."/embedded-{$track->id}.jpg";
        $max = (int) config('export.cover_max_px');
        try {
            $this->ffmpeg(['-i', $source, '-map', '0:v:0', '-vf', "scale=w='min({$max},iw)':h='min({$max},ih)':force_original_aspect_ratio=decrease,format=yuvj420p", '-frames:v', '1', '-q:v', '3', $out]);

            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /** A FLAC picture block (front cover, JPEG) as base64, the Vorbis/Opus way to embed a cover; null when the image is unreadable. */
    private function pictureBlock(string $jpeg): ?string
    {
        $data = @file_get_contents($jpeg);
        $size = @getimagesize($jpeg);
        if (!$data || !$size) {
            return null;
        }
        $mime = 'image/jpeg';

        return base64_encode(
            pack('N', 3).pack('N', strlen($mime)).$mime.pack('N', 0).pack('N', $size[0]).pack('N', $size[1]).pack('N', 24).pack('N', 0).pack('N', strlen($data)).$data,
        );
    }

    /** The album cover as a baseline JPEG of at most `cover_max_px`, made once per album; null when there is none to use. */
    private function cover(Track $track, string $source): ?string
    {
        $key = (int) $track->album_id;
        if (!isset($this->covers[$key])) {
            $this->covers[$key] = false;
            $url = LibraryArtwork::coverUrl($track->album?->images);
            $processed = $url ? Storage::disk('public')->path('artwork/'.md5($url).'/512.jpg') : null;
            if ($processed && is_file($processed)) {
                $out = $this->work."/cover-{$key}.jpg";
                $max = (int) config('export.cover_max_px');
                try {
                    $this->ffmpeg(['-i', $processed, '-vf', "scale=w='min({$max},iw)':h='min({$max},ih)':force_original_aspect_ratio=decrease,format=yuvj420p", '-frames:v', '1', '-q:v', '3', $out]);
                    $this->covers[$key] = $out;
                } catch (\Throwable $e) {
                    Log::info("Export cover for album {$key}: {$e->getMessage()}");
                }
            }
        }

        return $this->covers[$key] ?: null;
    }

    /** @param list<string> $args */
    private function ffmpeg(array $args): void
    {
        $command = [Tools::ffmpeg(), '-y', '-nostdin', '-v', 'error', ...$args];
        $nice = (int) config('export.nice');
        if ($nice > 0 && is_executable('/usr/bin/nice')) {
            $command = ['/usr/bin/nice', '-n', (string) $nice, ...$command];
        }

        $result = Process::timeout((int) config('export.file_timeout'))->run($command);
        $target = end($args);
        if (!$result->successful() || !is_file($target) || filesize($target) === 0) {
            @unlink($target);
            throw new \RuntimeException('ffmpeg failed: '.trim($result->errorOutput() ?: 'no output'));
        }
    }

    /** A copied MP3 remuxed with ID3v2.3 tags (profiles with `id3v23`), streams untouched. */
    public function remuxId3v23(string $source, string $target): void
    {
        $this->ffmpeg(['-i', $source, '-map', '0', '-c', 'copy', '-map_metadata', '0', '-id3v2_version', '3', $target]);
    }
}
