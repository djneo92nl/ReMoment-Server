<?php

namespace Tests\Unit\MasterLink;

use App\Integrations\BangOlufsen\MasterLink\BusTelegram;
use App\Integrations\BangOlufsen\MasterLink\MlgwFrame;
use App\Integrations\BangOlufsen\MasterLink\MlgwFrameBuffer;
use App\Integrations\BangOlufsen\MasterLink\TelnetFilter;
use PHPUnit\Framework\TestCase;

class MlgwProtocolTest extends TestCase
{
    public function test_a_login_frame_carries_user_and_password_split_by_a_zero(): void
    {
        $this->assertSame("\x01\x30\x0C\x00admin\0secret", MlgwFrame::login('admin', 'secret'));
    }

    public function test_frames_are_cut_from_a_stream_split_anywhere(): void
    {
        $stream = MlgwFrame::encode(0x02, "\x01\x6F\x00\x00\x00\x03\x02\x00").MlgwFrame::encode(0x05);
        $buffer = new MlgwFrameBuffer;

        $frames = [...$buffer->feed(substr($stream, 0, 5)), ...$buffer->feed(substr($stream, 5, 6)), ...$buffer->feed(substr($stream, 11))];

        $this->assertCount(2, $frames);
        $this->assertSame(0x02, $frames[0]->type);
        $this->assertSame('01 6F 00 00 00 03 02 00', $frames[0]->hex());
        $this->assertSame('all standby', $frames[1]->name());
    }

    public function test_bytes_before_a_start_byte_are_dropped(): void
    {
        $frames = (new MlgwFrameBuffer)->feed("\xFF\x00".MlgwFrame::encode(0x37));

        $this->assertCount(1, $frames);
        $this->assertSame('pong', $frames[0]->name());
    }

    public function test_source_status_fields_are_decoded(): void
    {
        $frame = new MlgwFrame(0x02, "\x01\x6F\x00\x00\x00\x03\x02\x00");

        $this->assertSame('mln=1 (0x01) source=111 (0x6F) medium=0 (0x00) position=3 (0x03) activity=2 (0x02) picture=0 (0x00)', $frame->describe());
    }

    public function test_a_short_payload_describes_what_it_has(): void
    {
        $this->assertSame('mln=1 (0x01) source=111 (0x6F)', (new MlgwFrame(0x02, "\x01\x6F"))->describe());
    }

    public function test_a_telegram_line_is_parsed_into_bytes_with_or_without_separators(): void
    {
        $spaced = BusTelegram::parse('20261021-12:00:01:123456: C1; F0; 00; 0B; 00; 00; 00; 87; 04;');
        $glued = BusTelegram::parse('20261021-12:00:01:123456: C1F0000B00000087 04');

        $this->assertSame([0xC1, 0xF0, 0x00, 0x0B, 0x00, 0x00, 0x00, 0x87, 0x04], $spaced->bytes);
        $this->assertSame($spaced->bytes, $glued->bytes);
        $this->assertSame('AUDIO_MASTER', BusTelegram::deviceName($spaced->to()));
        $this->assertSame('MLGW', BusTelegram::deviceName($spaced->from()));
        $this->assertSame(0x87, $spaced->payloadType());
    }

    public function test_lines_that_are_no_telegram_are_ignored(): void
    {
        $this->assertNull(BusTelegram::parse('MLGW >'));
        $this->assertNull(BusTelegram::parse(''));
    }

    public function test_display_text_is_read_from_a_text_telegram(): void
    {
        $bytes = array_merge([0xC1, 0xC0, 0, 0x0B, 0, 0, 0, 0x06, 0x0B + 5, 0, 0, 0, 0, 0, 0], array_map('ord', str_split('RADIO 1 ')));
        $line = '20261021-12:00:01:1: '.implode(' ', array_map(fn ($b) => sprintf('%02x', $b), $bytes));

        $telegram = BusTelegram::parse($line);

        $this->assertSame('RADIO 1', $telegram->text());
        $this->assertStringContainsString('display text', $telegram->describe());
        $this->assertStringContainsString('text="RADIO 1"', $telegram->describe());
    }

    public function test_telnet_negotiation_is_refused_and_removed(): void
    {
        $filter = new TelnetFilter;

        [$text, $reply] = $filter->filter("\xFF\xFD\x01\xFF\xFB\x03login: ");

        $this->assertSame('login: ', $text);
        $this->assertSame("\xFF\xFC\x01\xFF\xFE\x03", $reply);
    }

    public function test_a_negotiation_split_over_two_reads_is_completed(): void
    {
        $filter = new TelnetFilter;

        [$first] = $filter->filter("a\xFF");
        [$second, $reply] = $filter->filter("\xFD\x18b");

        $this->assertSame('a', $first);
        $this->assertSame('b', $second);
        $this->assertSame("\xFF\xFC\x18", $reply);
    }
}
