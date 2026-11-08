<?php

namespace App\Domain\Export;

use App\Domain\Analysis\Tools;
use App\Domain\Artwork\LibraryArtwork;
use App\Domain\Storage\FileStore;
use App\Models\Export;
use App\Models\File;
use App\Models\Media\Track;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Executes an export (workstream 13, step 2): plans the selection, then for each file copies the original or
 * transcodes it with ffmpeg, and streams the result into store-mode zip parts of at most `export.part_gb`
 * (`ZipWriter`, never in memory) under `exports/{public_id}/` on the private disk. Progress is kept on the row.
 * A file that fails is listed as skipped and the rest go on; a run that fails or is cancelled removes its output.
 *
 * Covers: a transcode embeds the album cover as a baseline JPEG of at most `export.cover_max_px` (the processed
 * 512 px cover of the artwork pipeline, re-encoded, else the art embedded in the source file). A copied file is
 * left untouched: its own tags and cover stay as they are (only profiles with `id3v23` remux a copied MP3 with
 * `-c copy` to get ID3v2.3 tags). Transcodes are not shared through the proxy cache (its profiles drop tags).
 */
final class ExportRunner
{
    private Export $export;

    private Filesystem $disk;

    private string $root;

    private string $work;

    /** @var array<int, string|false> album id => path of the resized cover, false = none */
    private array $covers = [];

    /** @var list<array{name: string, size: int, files: int}> */
    private array $parts = [];

    private ?ZipWriter $zip = null;

    /** @var list<array{path: string, title: string, artist: string, duration: int}> */
    private array $partEntries = [];

    public function __construct(private readonly ExportPlanner $planner = new ExportPlanner) {}

    public function run(Export $export): void
    {
        $this->export = $export;
        $this->disk = Storage::disk(config('export.disk'));
        $this->disk->makeDirectory($export->directory());
        $this->root = $this->disk->path($export->directory());
        $this->work = $this->root.'/work';

        try {
            $this->execute();
        } catch (ExportCancelled) {
            $this->cleanup();
        } catch (\Throwable $e) {
            $this->cleanup();
            $this->export->forceFill(['status' => Export::FAILED, 'error' => $e->getMessage(), 'finished_at' => now(), 'part_files' => null])->save();
            Log::warning("Export {$export->public_id} failed: {$e->getMessage()}");
        }
    }

    private function execute(): void
    {
        $export = $this->export;
        $tracks = ExportSelection::tracks($export);
        $plan = $this->planner->plan($tracks, $export->profile);
        $byId = $tracks->keyBy('id');

        $skipped = [];
        foreach ($plan->items as $item) {
            if ($item->action === ExportPlanner::SKIP) {
                $skipped[] = ['track_id' => $item->trackId, 'name' => $byId[$item->trackId]->name ?? null, 'reason' => $item->reason];
            }
        }

        $export->forceFill([
            'status' => Export::RUNNING, 'started_at' => now(), 'error' => null, 'tracks' => $plan->tracks, 'copied' => $plan->copied,
            'transcoded' => $plan->transcoded, 'skipped_count' => count($skipped), 'estimated_bytes' => $plan->estimatedBytes,
            'planned_parts' => $plan->parts, 'files_total' => $plan->exportable(), 'files_done' => 0, 'skipped' => $skipped,
        ])->save();

        if ($plan->exceedsLimits()) {
            throw new \RuntimeException('The export is over the limit ('.implode(', ', $plan->exceeds).'); estimated '.round($plan->estimatedBytes / 1024 ** 3, 2).' GB.');
        }
        if ($plan->exportable() === 0) {
            throw new \RuntimeException('None of the tracks has an own file to export.');
        }
        $this->checkFreeSpace($plan->estimatedBytes);
        if (!Tools::available(Tools::ffmpeg())) {
            throw new \RuntimeException('ffmpeg is not available.');
        }

        @mkdir($this->work, 0775, true);
        $layout = $export->option('layout') ?: (in_array($export->selection_type, ['playlist', 'tracks'], true) ? 'flat' : 'tree');
        $names = new ExportNames;
        $width = max(3, strlen((string) $plan->tracks));
        $position = 0;
        $done = 0;

        foreach ($plan->items as $item) {
            $position++;
            if ($item->action === ExportPlanner::SKIP) {
                continue;
            }
            $this->checkCancelled();

            $track = $byId[$item->trackId];
            try {
                $file = File::with('location')->find($item->fileId);
                if (!$file || $file->status !== File::PRESENT) {
                    throw new \RuntimeException('file_missing');
                }
                $extension = $item->action === ExportPlanner::TRANSCODE ? 'mp3' : ($file->extension ?: pathinfo($file->path, PATHINFO_EXTENSION));
                $path = $names->path(
                    $layout, $track->artist?->name, $track->album?->name, $track->name, $extension, $position,
                    (int) $track->metaValue('track_number') ?: null, (int) $track->metaValue('disc_number') ?: null, $width,
                );
                (new FileStore($file->location))->withLocalPath($file->path, fn (string $local) => $this->add($item, $track, $path, $local));
            } catch (\Throwable $e) {
                $reason = $e->getMessage() === 'file_missing' ? 'file_missing' : ($item->action === ExportPlanner::TRANSCODE ? 'transcode_failed' : 'copy_failed');
                Log::warning("Export {$export->public_id}: track {$item->trackId}: {$e->getMessage()}");
                $skipped[] = ['track_id' => $item->trackId, 'name' => $track->name, 'reason' => $reason];
                $export->skipped = $skipped;
                $export->skipped_count = count($skipped);
            }

            $export->files_done = ++$done;
            $export->save();
        }

        $this->closePart();

        if ($this->parts === []) {
            throw new \RuntimeException('No file could be exported.');
        }

        $this->name($export);
        $this->deleteWork();

        $export->forceFill([
            'status' => Export::DONE, 'part_files' => $this->parts, 'bytes' => array_sum(array_column($this->parts, 'size')),
            'finished_at' => now(), 'expires_at' => now()->addHours((int) config('export.keep_hours')),
        ])->save();
    }

    /** Makes the file as it goes into the zip (the original, a remux or a transcode) and writes it into the right part. */
    private function add(ExportItem $item, Track $track, string $path, string $local): void
    {
        $temp = null;
        $source = $local;

        if ($item->action === ExportPlanner::TRANSCODE) {
            $temp = $this->work.'/'.$item->trackId.'.mp3';
            $this->transcode($item, $track, $local, $temp);
            $source = $temp;
        } elseif (config("export.profiles.{$this->export->profile}.id3v23") && $this->isMp3($local)) {
            $temp = $this->work.'/'.$item->trackId.'.mp3';
            $this->ffmpeg(['-i', $local, '-map', '0', '-c', 'copy', '-map_metadata', '0', '-id3v2_version', '3', $temp]);
            $source = $temp;
        }

        try {
            $size = (int) filesize($source);
            $part = (int) round((float) config('export.part_gb') * 1024 ** 3);
            if ($this->zip && $this->zip->count() > 0 && $part > 0 && $this->zip->size() + $size + 1024 > $part) {
                $this->closePart();
            }
            $this->zip ??= $this->openPart();
            $this->zip->addFile($path, $source, filemtime($local) ?: null);
            $this->partEntries[] = ['path' => $path, 'title' => (string) $track->name, 'artist' => (string) $track->artist?->name, 'duration' => (int) $track->duration];
        } finally {
            if ($temp) {
                @unlink($temp);
            }
        }
    }

    private function transcode(ExportItem $item, Track $track, string $source, string $target): void
    {
        $t = $item->target;
        $cover = $this->cover($track, $source);
        $max = (int) config('export.cover_max_px');
        $scale = "scale=w='min({$max},iw)':h='min({$max},ih)':force_original_aspect_ratio=decrease,format=yuvj420p";

        $args = ['-i', $source];
        if ($cover) {
            array_push($args, '-i', $cover, '-map', '0:a:0', '-map', '1:v:0', '-c:v', 'copy');
        } else {
            array_push($args, '-map', '0:a:0', '-map', '0:v:0?', '-c:v', 'mjpeg', '-q:v', '3', '-vf', $scale);
        }
        array_push($args, '-map_metadata', '0', '-c:a', 'libmp3lame', '-b:a', ((int) $t['bitrate']).'k');
        if ($t['sample_rate']) {
            array_push($args, '-ar', (string) $t['sample_rate']);
        }
        if ($t['stereo']) {
            array_push($args, '-ac', '2');
        }
        array_push($args, '-id3v2_version', '3', '-metadata:s:v', 'title=Cover', '-metadata:s:v', 'comment=Cover (front)', $target);

        $this->ffmpeg($args);
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

    private function isMp3(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mp3';
    }

    private function openPart(): ZipWriter
    {
        $this->partEntries = [];

        return new ZipWriter($this->root.'/part-'.(count($this->parts) + 1).'.zip');
    }

    private function closePart(): void
    {
        if (!$this->zip) {
            return;
        }
        if ($this->export->selection_type === 'playlist') {
            $this->zip->addString('playlist.m3u8', $this->m3u8());
        }
        $this->zip->close();
        $this->parts[] = ['name' => basename($this->zip->path), 'size' => $this->zip->size(), 'files' => count($this->partEntries)];
        $this->zip = null;
        $this->partEntries = [];
    }

    /** Relative paths, in the order of this part's files. */
    private function m3u8(): string
    {
        $lines = ['#EXTM3U'];
        foreach ($this->partEntries as $e) {
            $label = trim($e['artist'] !== '' ? "{$e['artist']} - {$e['title']}" : $e['title']);
            $lines[] = '#EXTINF:'.($e['duration'] ?: -1).','.str_replace(["\r", "\n"], ' ', $label);
            $lines[] = $e['path'];
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /** Gives the parts their final names: `Title.zip`, or `Title (part 1 of 3).zip`. */
    private function name(Export $export): void
    {
        $title = ExportNames::clean(ExportSelection::title($export), 'Export', 80);
        $count = count($this->parts);
        foreach ($this->parts as $i => &$part) {
            $final = $count === 1 ? "{$title}.zip" : "{$title} (part ".($i + 1)." of {$count}).zip";
            rename("{$this->root}/{$part['name']}", "{$this->root}/{$final}");
            $part['name'] = $final;
        }
    }

    private function checkFreeSpace(int $estimate): void
    {
        $free = @disk_free_space($this->root);
        $need = $estimate + (int) config('export.free_margin_mb') * 1024 * 1024;
        if ($free !== false && $free < $need) {
            throw new \RuntimeException('Not enough free disk space: '.round($need / 1024 ** 2).' MB needed, '.round($free / 1024 ** 2).' MB free.');
        }
    }

    private function checkCancelled(): void
    {
        if (Export::query()->whereKey($this->export->id)->value('status') !== Export::RUNNING) {
            throw new ExportCancelled;
        }
    }

    private function deleteWork(): void
    {
        $this->disk->deleteDirectory($this->export->directory().'/work');
    }

    /** Removes everything this run wrote, parts and work files alike. */
    private function cleanup(): void
    {
        $this->zip?->abort();
        $this->zip = null;
        $this->disk->deleteDirectory($this->export->directory());
    }
}
