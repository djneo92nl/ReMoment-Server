<?php

namespace Tests\Support;

use App\Models\StorageLocation;
use App\Services\Local\LocalLibraryScanner;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Process;

/** A temp library and inbox with real tagged audio (ffmpeg makes one second of tone), for the tag editor tests. */
trait MakesTaggedFiles
{
    private string $base;

    protected function makeBase(): void
    {
        $this->base = sys_get_temp_dir().'/remoment-tags-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
        Fs::makeDirectory($this->base.'/inbox', 0777, true);
    }

    protected function removeBase(): void
    {
        Fs::deleteDirectory($this->base);
    }

    protected function location(string $name, string $role): StorageLocation
    {
        return StorageLocation::firstOrCreate(['name' => $name], ['driver' => 'local', 'role' => $role, 'config' => ['root' => $this->base.'/'.$name]]);
    }

    /** A JPEG of $color, 300 px square. */
    protected function jpeg(int $color = 0xCC2222): string
    {
        $image = imagecreatetruecolor(300, 300);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * One second of tone with tags (ffmpeg keys, e.g. title, artist, "MusicBrainz Album Id") and optionally a cover.
     *
     * @param  array<string, string>  $tags
     */
    protected function makeAudio(string $location, string $path, array $tags = [], ?string $cover = null, string $codec = 'mp3'): string
    {
        $full = "{$this->base}/{$location}/{$path}";
        Fs::ensureDirectoryExists(dirname($full));

        $command = ['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency='.(440 + crc32($path) % 200).':duration=1'];
        if ($cover !== null) {
            file_put_contents("{$this->base}/cover-src.jpg", $cover);
            array_push($command, '-i', "{$this->base}/cover-src.jpg", '-map', '0:a', '-map', '1', '-disposition:v:0', 'attached_pic');
        }
        array_push($command, ...($codec === 'flac' ? ['-c:a', 'flac', '-c:v', 'copy'] : ['-c:a', 'libmp3lame', '-b:a', '128k', '-c:v', 'copy', '-id3v2_version', '3']));
        foreach ($tags as $key => $value) {
            array_push($command, '-metadata', "{$key}={$value}");
        }
        $command[] = $full;

        $result = Process::run($command);
        $this->assertTrue($result->successful(), $result->errorOutput());

        return $full;
    }

    protected function scan(StorageLocation $location): void
    {
        app(LocalLibraryScanner::class)->scan($location);
    }
}
