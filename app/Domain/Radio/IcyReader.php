<?php

namespace App\Domain\Radio;

/**
 * Reads the current song title of an Icecast/Shoutcast stream (ICY metadata): ask for it with `Icy-MetaData: 1`,
 * skip `icy-metaint` bytes of audio, then read the metadata block that follows (length byte × 16).
 */
class IcyReader
{
    private const TIMEOUT_SECONDS = 6;

    /** Most streams have the title in the first block; a few put an empty one first, so a few blocks are read. */
    private const MAX_BLOCKS = 3;

    /** The stream's current "Artist - Title", null when it has none or can't be reached. */
    public function title(string $url): ?string
    {
        $stream = @fopen($url, 'rb', false, stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Icy-MetaData: 1\r\nUser-Agent: ".config('metadata.user_agent')."\r\nConnection: close\r\n",
            'timeout' => self::TIMEOUT_SECONDS,
            'follow_location' => 1,
            'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => true]]));

        if ($stream === false) {
            return null;
        }

        try {
            stream_set_timeout($stream, self::TIMEOUT_SECONDS);

            return $this->readTitle($stream, self::metaint($http_response_header ?? []));
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream positioned at the start of the audio */
    public function readTitle($stream, ?int $metaint): ?string
    {
        if (!$metaint || $metaint < 1) {
            return null;
        }

        for ($i = 0; $i < self::MAX_BLOCKS; $i++) {
            if (!$this->skip($stream, $metaint)) {
                return null;
            }

            $length = fread($stream, 1);

            if ($length === false || $length === '') {
                return null;
            }

            $size = ord($length) * 16;

            if ($size === 0) {
                continue;
            }

            $block = $this->read($stream, $size);

            if ($title = self::parseBlock($block)) {
                return $title;
            }
        }

        return null;
    }

    /** `StreamTitle='Artist - Song';StreamUrl='';` → "Artist - Song" (null when empty). */
    public static function parseBlock(string $block): ?string
    {
        if (!preg_match("/StreamTitle='(.*?)';(?:\\w+=|\\0|$)/s", rtrim($block, "\0")."\0", $m)) {
            return null;
        }

        $title = trim(self::utf8($m[1]));

        return $title === '' ? null : $title;
    }

    /** "Artist - Title" split into [artist, title]; one without a separator is just a title. @return array{0: ?string, 1: string} */
    public static function split(string $title): array
    {
        if (preg_match('/^(.+?)\s+-\s+(.+)$/u', $title, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [null, $title];
    }

    /** @param string[] $headers the response headers of the HTTP wrapper */
    public static function metaint(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (stripos($header, 'icy-metaint:') === 0) {
                return (int) trim(substr($header, strlen('icy-metaint:'))) ?: null;
            }
        }

        return null;
    }

    private static function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }

    /** @param resource $stream */
    private function skip($stream, int $bytes): bool
    {
        return strlen($this->read($stream, $bytes)) === $bytes;
    }

    /** @param resource $stream */
    private function read($stream, int $bytes): string
    {
        $data = '';

        while (strlen($data) < $bytes && !feof($stream)) {
            $chunk = fread($stream, $bytes - strlen($data));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
        }

        return $data;
    }
}
