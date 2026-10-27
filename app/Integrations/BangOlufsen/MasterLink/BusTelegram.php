<?php

namespace App\Integrations\BangOlufsen\MasterLink;

/**
 * One telegram of the Master Link bus as the gateway's undocumented CLI prints it after `_MLLOG ONLINE`:
 * a timestamp, then the telegram's bytes in hex. Offsets follow the Home Assistant plugin giachello/mlgw
 * and are unverified; `mlgw:probe` shows the raw bytes next to every decoded field to check them.
 */
final class BusTelegram
{
    public const DEVICES = [
        0xC0 => 'VIDEO_MASTER',
        0xC1 => 'AUDIO_MASTER',
        0xC2 => 'SOURCE_CENTER',
        0x80 => 'ALL',
        0x81 => 'LINK_1',
        0x82 => 'LINK_2',
        0x83 => 'LINK_3',
        0xF0 => 'MLGW',
    ];

    public const PAYLOAD_TYPES = [
        0x06 => 'display text',
        0x08 => 'distributed audio',
        0x0B => 'extended info',
        0x0D => 'beo4 command',
        0x20 => 'remote request',
        0x30 => 'local audio',
        0x44 => 'track change',
        0x45 => 'goto source',
        0x5C => 'request key',
        0x82 => 'audio track info',
        0x87 => 'source status',
        0x94 => 'video track info',
    ];

    /** @param int[] $bytes */
    public function __construct(public readonly string $time, public readonly array $bytes) {}

    /** Null for a line that is no telegram (the prompt, a banner). */
    public static function parse(string $line): ?self
    {
        if (!preg_match('/^(\d{8}-\d{2}:\d{2}:\d{2}(?::\d+)?):\s*(.+)$/', trim($line), $m)) {
            return null;
        }

        $bytes = [];
        foreach (preg_split('/[^0-9A-Fa-f]+/', $m[2], -1, PREG_SPLIT_NO_EMPTY) as $token) {
            foreach (str_split($token, 2) as $pair) {
                if (strlen($pair) === 2) {
                    $bytes[] = hexdec($pair);
                }
            }
        }

        return $bytes === [] ? null : new self($m[1], $bytes);
    }

    public function to(): ?int
    {
        return $this->bytes[0] ?? null;
    }

    public function from(): ?int
    {
        return $this->bytes[1] ?? null;
    }

    public function payloadType(): ?int
    {
        return $this->bytes[7] ?? null;
    }

    public function hex(): string
    {
        return strtoupper(implode(' ', array_map(fn (int $b) => sprintf('%02x', $b), $this->bytes)));
    }

    public static function deviceName(?int $id): string
    {
        return $id === null ? '?' : (self::DEVICES[$id] ?? sprintf('0x%02X', $id));
    }

    /** The text a display text (0x06) or extended info (0x0B) telegram carries, else null. */
    public function text(): ?string
    {
        $length = $this->bytes[8] ?? 0;

        [$from, $count] = match ($this->payloadType()) {
            0x06 => [15, $length - 5],
            0x0B => [24, $length - 14],
            default => [0, 0],
        };

        if ($count <= 0) {
            return null;
        }

        $text = trim(preg_replace('/[^\x20-\x7E]/', '', pack('C*', ...array_slice($this->bytes, $from, $count))) ?? '');

        return $text === '' ? null : $text;
    }

    public function describe(): string
    {
        $b = $this->bytes;
        $at = fn (int $i): ?int => $b[$i] ?? null;
        $type = $this->payloadType();

        $fields = match ($type) {
            0x87 => ['source' => $at(10), 'local' => $at(13), 'medium' => $this->word(17), 'track' => $at(19), 'activity' => $at(21), 'kind' => $at(22), 'picture' => $at(23)],
            0x0B => ['info_type' => $at(10)],
            0x0D => ['source' => $at(10), 'command' => $at(11)],
            0x82 => ['source' => $at(11), 'track' => $at(12), 'activity' => $at(13)],
            0x94 => ['source' => $at(13), 'track' => $this->word(11), 'activity' => $at(14)],
            0x44 => ['sub' => $at(9), 'source' => $at(11), 'new' => $at(9) === 0x07 ? $at(22) : null],
            0x45 => ['source' => $at(11), 'track' => $at(12)],
            0x20 => ['dest' => $at(11), 'command' => $at(14)],
            default => [],
        };

        $parts = [];
        foreach ($fields as $label => $value) {
            if ($value !== null) {
                $parts[] = sprintf('%s=0x%02X', $label, $value);
            }
        }

        if (($text = $this->text()) !== null) {
            $parts[] = 'text="'.$text.'"';
        }

        return sprintf(
            '%s -> %s  %s%s',
            self::deviceName($this->from()),
            self::deviceName($this->to()),
            $type === null ? 'no payload type' : (self::PAYLOAD_TYPES[$type] ?? sprintf('payload 0x%02X', $type)),
            $parts === [] ? '' : '  '.implode(' ', $parts),
        );
    }

    private function word(int $i): ?int
    {
        return isset($this->bytes[$i], $this->bytes[$i + 1]) ? ($this->bytes[$i] << 8) | $this->bytes[$i + 1] : null;
    }
}
