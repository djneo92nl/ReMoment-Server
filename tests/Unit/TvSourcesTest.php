<?php

namespace Tests\Unit;

use App\Domain\Device\SourceInput;
use App\Domain\Device\TvSources;
use PHPUnit\Framework\TestCase;

class TvSourcesTest extends TestCase
{
    public function test_an_input_is_labelled_by_its_connection(): void
    {
        $this->assertSame('HDMI 2', SourceInput::label('HDMI', 2));
        $this->assertSame('HDMI 5', SourceInput::label('hdmi', '5'));
        $this->assertSame('HDMI', SourceInput::label('HDMI', ''));
        $this->assertSame('AV', SourceInput::label('AV IN', 1));
        $this->assertNull(SourceInput::label('TV'), 'the tuner is a label, not an invented input');
        $this->assertNull(SourceInput::label('SPOTIFY'));
        $this->assertNull(SourceInput::label(null));
    }

    public function test_sources_are_split_into_inputs_and_apps_and_the_rest_is_left_out(): void
    {
        $rows = [
            ['id' => 1, 'type' => 'TV'],
            ['id' => 2, 'type' => 'HDMI'],
            ['id' => 3, 'type' => 'HDMI', 'hidden' => true],
            ['id' => 4, 'type' => 'YOUTUBE'],
            ['id' => 5, 'type' => 'WEBMEDIA'],
            ['id' => 6, 'type' => 'DEEZER'],
            ['id' => 7, 'type' => 'NET RADIO'],
        ];

        $groups = TvSources::group($rows);

        $this->assertSame([1, 2], array_column($groups['inputs'], 'id'));
        $this->assertSame([4, 5], array_column($groups['apps'], 'id'));
    }
}
