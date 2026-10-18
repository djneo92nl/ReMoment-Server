<?php

namespace Tests\Feature\Library;

use App\Domain\Library\AlbumKind;
use App\Models\Media\Album;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlbumKindTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('cases')]
    public function test_kinds(string $name, int $tracks, string $expected): void
    {
        $this->assertSame($expected, AlbumKind::of(new Album(['name' => $name]), $tracks), $name);
    }

    public static function cases(): array
    {
        return [
            ['Concrete and Gold', 11, AlbumKind::ALBUM],
            ['In Your Honor', 22, AlbumKind::ALBUM],
            ['Us and Them', 12, AlbumKind::ALBUM],
            ['But Here We Are', 1, AlbumKind::SINGLE],
            ['Best Of You', 1, AlbumKind::SINGLE],
            ['Everlong UK CD1 (7243 8 84361 2 0)', 3, AlbumKind::SINGLE],
            ['Big Me UK CD Single (7243 8 82574 2 7)', 4, AlbumKind::SINGLE],
            ['Low (Australian CD Single)', 3, AlbumKind::SINGLE],
            ['Generator ECD EU Limited Edition Numbered EP (74321749582)', 5, AlbumKind::SINGLE],
            ['Best Of You EU CD2 (82876 70121 2)', 2, AlbumKind::SINGLE],
            ['Foo Fighters: Greatest Hits', 16, AlbumKind::COMPILATION],
            ['Greatest Hits US (88697-36921-2)', 16, AlbumKind::COMPILATION],
            ['Foo Fighters EU (RCA 82876 55496 2)', 12, AlbumKind::EDITION],
            ['In Your Honor One Japan (BVCM-35133-4)', 11, AlbumKind::EDITION],
            ['One By One (Expanded Edition)', 17, AlbumKind::EDITION],
            ['Medicine At Midnight (Track Commentary)', 9, AlbumKind::EDITION],
            ['Echoes, Silence, Patience & Grace (Japan, BVCP-21552 (88697-15501-2))', 14, AlbumKind::EDITION],
            ['Skin and Bones (Live)', 14, AlbumKind::LIVE],
            ['MTV Unplugged', 10, AlbumKind::LIVE],
            ['Wasting Light', 11, AlbumKind::ALBUM],
        ];
    }
}
