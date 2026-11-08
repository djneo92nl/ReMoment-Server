<?php

namespace App\Domain\Export;

/**
 * File names inside an export zip, safe for FAT32 sticks and plain players: forbidden characters replaced, parts
 * limited in length, no trailing dots or spaces, no reserved device names, and every path unique (FAT compares
 * case-insensitively, so "A.mp3" and "a.mp3" count as duplicates).
 */
final class ExportNames
{
    public const PART_MAX = 60;

    public const FILE_MAX = 100;

    /** @var array<string, true> */
    private array $used = [];

    /** One path component: a folder or a file base name without extension. */
    public static function clean(?string $name, string $fallback, int $max = self::PART_MAX): string
    {
        $name = (string) $name;
        $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', '_', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = mb_substr($name, 0, $max);
        $name = rtrim($name, ". \t_");
        $name = ltrim($name);

        if ($name === '' || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $name)) {
            $name = $name === '' ? $fallback : "_{$name}";
        }

        return $name;
    }

    /** `Artist/Album/01 - Title.mp3` (tree) or `001 - Artist - Title.mp3` (flat; `$position` is the playlist order). */
    public function path(string $layout, ?string $artist, ?string $album, ?string $title, string $extension, int $position, ?int $trackNumber = null, ?int $discNumber = null, int $width = 3): string
    {
        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?: 'mp3');
        $title = self::clean($title, 'Untitled');

        if ($layout === 'flat') {
            $base = str_pad((string) $position, $width, '0', STR_PAD_LEFT).' - '.self::clean($artist, 'Unknown Artist', 40).' - '.$title;

            return $this->unique('', $base, $extension);
        }

        $number = $trackNumber ? str_pad((string) $trackNumber, 2, '0', STR_PAD_LEFT) : str_pad((string) $position, 2, '0', STR_PAD_LEFT);
        $prefix = ($discNumber && $discNumber > 1 ? "{$discNumber}-" : '').$number;
        $directory = self::clean($artist, 'Unknown Artist').'/'.self::clean($album, 'Unknown Album').'/';

        return $this->unique($directory, "{$prefix} - {$title}", $extension);
    }

    /** A path nothing else in this export uses; a duplicate gets " (2)", " (3)" ... before the extension. */
    private function unique(string $directory, string $base, string $extension): string
    {
        $base = rtrim(mb_substr($base, 0, self::FILE_MAX), '. ');
        $path = "{$directory}{$base}.{$extension}";
        for ($n = 2; isset($this->used[mb_strtolower($path)]); $n++) {
            $path = "{$directory}{$base} ({$n}).{$extension}";
        }
        $this->used[mb_strtolower($path)] = true;

        return $path;
    }
}
