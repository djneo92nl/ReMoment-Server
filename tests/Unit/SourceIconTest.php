<?php

namespace Tests\Unit;

use App\Domain\Device\SourceIcon;
use PHPUnit\Framework\TestCase;

class SourceIconTest extends TestCase
{
    public function test_a_source_type_maps_to_its_icon_ignoring_case(): void
    {
        $this->assertSame('fa-display', SourceIcon::for('hdmi'));
        $this->assertSame('fa-tower-broadcast', SourceIcon::for('TUNEIN'));
        $this->assertSame('fa-compact-disc', SourceIcon::for('CD'));
    }

    public function test_line_in_and_unknown_types_get_the_plug(): void
    {
        $this->assertSame('fa-plug', SourceIcon::for('LINEIN'));
        $this->assertSame('fa-plug', SourceIcon::for('whatever'));
        $this->assertSame('fa-plug', SourceIcon::for(null));
    }
}
