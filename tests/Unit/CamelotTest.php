<?php

namespace Tests\Unit;

use App\Domain\Analysis\Camelot;
use PHPUnit\Framework\TestCase;

class CamelotTest extends TestCase
{
    public function test_codes_follow_the_wheel(): void
    {
        $this->assertSame('8A', Camelot::code('A', 'minor'));
        $this->assertSame('8B', Camelot::code('C', 'major'));
        $this->assertSame('1A', Camelot::code('G#', 'minor'));
        $this->assertSame('1B', Camelot::code('B', 'major'));
        $this->assertSame('5B', Camelot::code('D#', 'major'));
        $this->assertSame('12A', Camelot::code('C#', 'minor'));
    }

    public function test_an_unknown_key_or_mode_has_no_code(): void
    {
        $this->assertNull(Camelot::code(null, 'major'));
        $this->assertNull(Camelot::code('C', null));
        $this->assertNull(Camelot::code('H', 'major'));
        $this->assertNull(Camelot::code('C', 'dorian'));
    }

    public function test_every_code_parses_back_to_its_key(): void
    {
        foreach (Camelot::options() as $code => $label) {
            $parsed = Camelot::parse($code);
            $this->assertSame($code, Camelot::code($parsed['key'], $parsed['mode']), $label);
        }

        $this->assertCount(24, Camelot::options());
        $this->assertSame(['key' => 'A', 'mode' => 'minor'], Camelot::parse('8a'));
        $this->assertNull(Camelot::parse('13A'));
        $this->assertNull(Camelot::parse('8C'));
        $this->assertNull(Camelot::parse(null));
    }

    public function test_compatible_codes_wrap_around_the_wheel(): void
    {
        $this->assertSame(['8A', '7A', '9A', '8B'], Camelot::compatible('8A'));
        $this->assertSame(['1B', '12B', '2B', '1A'], Camelot::compatible('1B'));
        $this->assertSame(['12A', '11A', '1A', '12B'], Camelot::compatible('12A'));
        $this->assertSame([], Camelot::compatible('nope'));
    }
}
