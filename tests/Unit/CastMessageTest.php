<?php

namespace Tests\Unit;

use App\Integrations\Cast\CastMessage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CastMessageTest extends TestCase
{
    public function test_round_trips_a_message(): void
    {
        $message = CastMessage::json('sender-0', 'receiver-0', 'urn:x-cast:com.google.cast.receiver', ['type' => 'GET_STATUS', 'requestId' => 1]);

        $decoded = CastMessage::decode($message->encode());

        $this->assertSame('sender-0', $decoded->sourceId);
        $this->assertSame('receiver-0', $decoded->destinationId);
        $this->assertSame('urn:x-cast:com.google.cast.receiver', $decoded->namespace);
        $this->assertSame(['type' => 'GET_STATUS', 'requestId' => 1], $decoded->data());
    }

    public function test_encodes_the_known_wire_format(): void
    {
        $bytes = (new CastMessage('a', 'b', 'n', '{}'))->encode();

        // protocol_version=0, source, destination, namespace, payload_type=0, payload
        $this->assertSame("\x08\x00\x12\x01a\x1a\x01b\x22\x01n\x28\x00\x32\x02{}", $bytes);
    }

    public function test_long_payloads_use_multi_byte_lengths(): void
    {
        $payload = json_encode(['text' => str_repeat('x', 400)]);

        $decoded = CastMessage::decode((new CastMessage('a', 'b', 'n', $payload))->encode());

        $this->assertSame($payload, $decoded->payload);
    }

    public function test_truncated_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CastMessage::decode(substr((new CastMessage('a', 'b', 'n', '{}'))->encode(), 0, -1));
    }
}
