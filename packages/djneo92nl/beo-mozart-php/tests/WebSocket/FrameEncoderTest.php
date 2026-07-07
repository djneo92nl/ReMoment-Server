<?php

namespace Djneo92nl\BeoMozart\Tests\WebSocket;

use Djneo92nl\BeoMozart\WebSocket\FrameEncoder;
use PHPUnit\Framework\TestCase;

class FrameEncoderTest extends TestCase
{
    protected function decode(string $frame): array
    {
        $byte1 = ord($frame[0]);
        $byte2 = ord($frame[1]);

        $fin = ($byte1 & 0x80) !== 0;
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $length = $byte2 & 0x7F;

        $offset = 2;

        if ($length === 126) {
            $length = unpack('n', substr($frame, $offset, 2))[1];
            $offset += 2;
        } elseif ($length === 127) {
            $length = unpack('J', substr($frame, $offset, 8))[1];
            $offset += 8;
        }

        $maskKey = substr($frame, $offset, 4);
        $offset += 4;

        $payload = substr($frame, $offset, $length);
        $unmasked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
        }

        return compact('fin', 'opcode', 'masked', 'length', 'unmasked');
    }

    public function test_encodes_a_short_masked_text_frame(): void
    {
        $frame = FrameEncoder::encode('hello', FrameEncoder::OPCODE_TEXT);
        $decoded = $this->decode($frame);

        $this->assertTrue($decoded['fin']);
        $this->assertTrue($decoded['masked']);
        $this->assertSame(FrameEncoder::OPCODE_TEXT, $decoded['opcode']);
        $this->assertSame(5, $decoded['length']);
        $this->assertSame('hello', $decoded['unmasked']);
    }

    public function test_encodes_a_pong_frame_with_empty_payload(): void
    {
        $frame = FrameEncoder::encode('', FrameEncoder::OPCODE_PONG);
        $decoded = $this->decode($frame);

        $this->assertSame(FrameEncoder::OPCODE_PONG, $decoded['opcode']);
        $this->assertSame(0, $decoded['length']);
        $this->assertSame('', $decoded['unmasked']);
    }

    public function test_encodes_a_medium_payload_using_16_bit_length(): void
    {
        $payload = str_repeat('a', 200);
        $frame = FrameEncoder::encode($payload, FrameEncoder::OPCODE_TEXT);
        $decoded = $this->decode($frame);

        $this->assertSame(200, $decoded['length']);
        $this->assertSame($payload, $decoded['unmasked']);
    }

    public function test_masking_key_is_random_per_frame(): void
    {
        $frame1 = FrameEncoder::encode('same payload', FrameEncoder::OPCODE_TEXT);
        $frame2 = FrameEncoder::encode('same payload', FrameEncoder::OPCODE_TEXT);

        $this->assertNotSame($frame1, $frame2);
        $this->assertSame('same payload', $this->decode($frame1)['unmasked']);
        $this->assertSame('same payload', $this->decode($frame2)['unmasked']);
    }
}
