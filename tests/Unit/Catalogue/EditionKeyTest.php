<?php

namespace Tests\Unit\Catalogue;

use App\Domain\Catalogue\EditionKey;
use App\Domain\Library\Normalizer;
use Tests\TestCase;

class EditionKeyTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function editions(): array
    {
        return [
            'norwegian special with catalogue number' => ['One By One Special Norwegian Edition (82876 512002)', 'One By One'],
            'uk cd1 single' => ['Everlong UK CD1 (7243 8 84361 2 0)', 'Everlong'],
            'best buy exclusive ep' => ['Five Songs And A Cover US Best Buy Exclusive EP (BMK 96143)', 'Five Songs And A Cover'],
            'bracketed number' => ['The Colour and the Shape [CDEST 2253]', 'The Colour and the Shape'],
            'region word' => ['Wasting Light Japan', 'Wasting Light'],
            'bracketed edition words' => ['Echoes, Silence, Patience & Grace (Japanese Limited Edition)', 'Echoes, Silence, Patience & Grace'],
            'dash edition' => ['In Your Honor - European Tour Edition', 'In Your Honor'],
            'collector' => ["Sonic Highways (Collector's Edition)", 'Sonic Highways'],
            'disc marker' => ['Abbey Road Disc 2', 'Abbey Road'],
            'plain stays' => ['Nevermind', 'Nevermind'],
        ];
    }

    /** @dataProvider editions */
    public function test_release_names_get_the_key_of_the_album(string $release, string $album): void
    {
        $this->assertSame(Normalizer::album($album), EditionKey::of($release));
    }

    /** @return array<string, array{string, string}> */
    public static function different(): array
    {
        return [
            'live' => ['Live at Reading', 'Reading'],
            'volume' => ['Greatest Hits Vol 2', 'Greatest Hits'],
            'volumes' => ['Volume 1', 'Volume 2'],
            'acoustic' => ['Skin and Bones Acoustic', 'Skin and Bones'],
            'remix' => ['Wasting Light Remix', 'Wasting Light'],
            'unplugged' => ['Nirvana Unplugged (82876 512002)', 'Nirvana'],
            'bare special is a title word' => ['Something Special', 'Something'],
            'part number' => ['Songs Part 2', 'Songs'],
            'demo' => ['Foo Demo Edition', 'Foo Edition'],
        ];
    }

    /** @dataProvider different */
    public function test_different_works_keep_different_keys(string $a, string $b): void
    {
        $this->assertNotSame(EditionKey::of($a), EditionKey::of($b));
    }

    public function test_a_title_of_only_edition_words_falls_back_to_the_normalizer_key(): void
    {
        $this->assertSame(Normalizer::album('Deluxe Edition'), EditionKey::of('Deluxe Edition'));
        $this->assertSame(Normalizer::album('Us'), EditionKey::of('Us'));
        $this->assertSame(Normalizer::album('(Special)'), EditionKey::of('(Special)'));
    }

    public function test_a_year_in_brackets_is_not_a_catalogue_number(): void
    {
        $this->assertSame(Normalizer::album('Album (2011)'), EditionKey::of('Album (2011)'));
    }

    public function test_the_word_lists_come_from_config(): void
    {
        config(['availability.edition_matching.regions' => []]);
        $this->assertNotSame(Normalizer::album('Wasting Light'), EditionKey::of('Wasting Light Japan'));
    }
}
