<?php

namespace App\Integrations\BangOlufsen\MasterLink;

/**
 * One packet of the MLGW / BLGW IP protocol (TCP, port 9000): SOH, type, payload length, a spare 0x00,
 * then the payload. Field layouts follow the Home Assistant plugin giachello/mlgw and are not yet
 * verified on hardware, which is what `mlgw:probe` is for: every frame is also shown as raw hex.
 */
final class MlgwFrame
{
    public const SOH = 0x01;

    public const LOGIN_REQUEST = 0x30;

    public const PING = 0x36;

    /** Assumed from the protocol's numbering (reply 0x3A is the serial number); unverified. */
    public const SERIAL_REQUEST = 0x39;

    public const LOGIN_STATUS = 0x31;

    public const SERIAL_NUMBER = 0x3A;

    /** Frames the gateway sends to a client. */
    public const TYPES = [
        0x02 => 'source status',
        0x03 => 'picture and sound status',
        0x04 => 'light/control',
        0x05 => 'all standby',
        0x20 => 'virtual button',
        0x31 => 'login status',
        0x37 => 'pong',
        0x38 => 'configuration changed',
        0x3A => 'serial number',
    ];

    public function __construct(public readonly int $type, public readonly string $payload) {}

    public static function encode(int $type, string $payload = ''): string
    {
        return chr(self::SOH).chr($type).chr(strlen($payload)).chr(0).$payload;
    }

    public static function login(string $user, string $password): string
    {
        return self::encode(self::LOGIN_REQUEST, $user."\0".$password);
    }

    public function name(): string
    {
        return self::TYPES[$this->type] ?? sprintf('type 0x%02X', $this->type);
    }

    /** @return int[] */
    public function bytes(): array
    {
        return array_values(unpack('C*', $this->payload) ?: []);
    }

    public function hex(): string
    {
        return strtoupper(implode(' ', str_split(bin2hex($this->payload), 2)));
    }

    /** The decoded fields as `label=value` text, empty for a type with no known layout. */
    public function describe(): string
    {
        $b = $this->bytes();
        $at = fn (int $i): ?int => $b[$i] ?? null;
        $word = fn (int $i): ?int => isset($b[$i], $b[$i + 1]) ? ($b[$i] << 8) | $b[$i + 1] : null;

        $fields = match ($this->type) {
            0x02 => ['mln' => $at(0), 'source' => $at(1), 'medium' => $word(2), 'position' => $word(4), 'activity' => $at(6), 'picture' => $at(7)],
            0x03 => ['mln' => $at(0), 'sound' => $at(1), 'speaker_mode' => $at(2), 'volume' => $at(3), 'screen1_mute' => $at(4), 'screen1_active' => $at(5), 'screen2_mute' => $at(6), 'screen2_active' => $at(7), 'cinema' => $at(8), 'stereo' => $at(9)],
            0x04 => ['room' => $at(0), 'type' => $at(1), 'beo4' => $at(2)],
            0x20 => ['button' => $at(0), 'action' => $at(1)],
            0x31 => ['status' => $at(0)],
            0x3A => ['serial' => $this->text()],
            default => [],
        };

        $parts = [];
        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = $label.'='.(is_int($value) ? sprintf('%d (0x%02X)', $value, $value & 0xFFFF) : $value);
        }

        return implode(' ', $parts);
    }

    private function text(): string
    {
        return trim(preg_replace('/[^\x20-\x7E]/', '', $this->payload) ?? '');
    }
}
