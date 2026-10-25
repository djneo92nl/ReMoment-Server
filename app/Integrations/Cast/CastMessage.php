<?php

namespace App\Integrations\Cast;

use InvalidArgumentException;

/**
 * One Google Cast v2 message: the protobuf `CastMessage` (cast_channel.proto) with a string payload, which is all the
 * connection, heartbeat, receiver and media namespaces use. Encoded by hand so no protobuf library is needed.
 * On the wire each message is preceded by its length as a 4-byte big-endian integer (see CastConnection).
 */
final class CastMessage
{
    public function __construct(
        public readonly string $sourceId,
        public readonly string $destinationId,
        public readonly string $namespace,
        public readonly string $payload,
    ) {}

    /** A JSON payload, as the namespaces send it. */
    public static function json(string $sourceId, string $destinationId, string $namespace, array $payload): self
    {
        return new self($sourceId, $destinationId, $namespace, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** The decoded JSON payload, or an empty array when it is not JSON. */
    public function data(): array
    {
        $data = json_decode($this->payload, true);

        return is_array($data) ? $data : [];
    }

    public function encode(): string
    {
        return self::varintField(1, 0) // protocol_version CASTV2_1_0
            .self::stringField(2, $this->sourceId)
            .self::stringField(3, $this->destinationId)
            .self::stringField(4, $this->namespace)
            .self::varintField(5, 0) // payload_type STRING
            .self::stringField(6, $this->payload);
    }

    public static function decode(string $bytes): self
    {
        $fields = [];
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $key = self::readVarint($bytes, $offset);
            $field = $key >> 3;
            $wire = $key & 7;

            if ($wire === 0) {
                self::readVarint($bytes, $offset);
            } elseif ($wire === 2) {
                $size = self::readVarint($bytes, $offset);
                if ($offset + $size > $length) {
                    throw new InvalidArgumentException('Truncated Cast message.');
                }
                $fields[$field] = substr($bytes, $offset, $size);
                $offset += $size;
            } else {
                throw new InvalidArgumentException("Unsupported wire type {$wire} in Cast message.");
            }
        }

        // A binary payload (field 7) is only used for device authentication; it reads as an empty string payload.
        return new self($fields[2] ?? '', $fields[3] ?? '', $fields[4] ?? '', $fields[6] ?? '');
    }

    private static function varintField(int $field, int $value): string
    {
        return self::varint($field << 3).self::varint($value);
    }

    private static function stringField(int $field, string $value): string
    {
        return self::varint(($field << 3) | 2).self::varint(strlen($value)).$value;
    }

    private static function varint(int $value): string
    {
        $out = '';
        while ($value > 0x7F) {
            $out .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }

        return $out.chr($value);
    }

    private static function readVarint(string $bytes, int &$offset): int
    {
        $value = 0;
        $shift = 0;

        do {
            if ($offset >= strlen($bytes) || $shift > 63) {
                throw new InvalidArgumentException('Invalid varint in Cast message.');
            }
            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $value;
    }
}
