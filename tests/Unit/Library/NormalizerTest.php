<?php

namespace Tests\Unit\Library;

use App\Domain\Library\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    public static function sameArtists(): array
    {
        return [
            'case' => ['Coldplay', 'COLDPLAY'],
            'leading the' => ['The Beatles', 'Beatles'],
            'trailing , the' => ['Beatles, The', 'The Beatles'],
            'diacritics' => ['Beyoncé', 'Beyonce'],
            'more diacritics' => ['Sigur Rós', 'Sigur Ros'],
            'letters without decomposition' => ['Mø', 'Mo'],
            'whitespace' => ['  Daft   Punk ', 'Daft Punk'],
            'ampersand' => ['Simon & Garfunkel', 'Simon and Garfunkel'],
            'periods' => ['R.E.M.', 'REM'],
            'apostrophe' => ['Guns N\' Roses', 'Guns N Roses'],
            'typographic apostrophe' => ['Guns N’ Roses', 'Guns N\' Roses'],
            'feat.' => ['Daft Punk feat. Pharrell Williams', 'Daft Punk'],
            'ft.' => ['Calvin Harris ft. Rihanna', 'Calvin Harris'],
            'featuring' => ['Santana featuring Rob Thomas', 'Santana'],
            '(feat.)' => ['Mark Ronson (feat. Bruno Mars)', 'Mark Ronson'],
            'hyphen as space' => ['Jay-Z', 'Jay Z'],
        ];
    }

    #[DataProvider('sameArtists')]
    public function test_artist_names_that_are_the_same(string $a, string $b): void
    {
        $this->assertSame(Normalizer::artist($a), Normalizer::artist($b));
    }

    public static function differentArtists(): array
    {
        return [
            'duo vs member' => ['Simon & Garfunkel', 'Simon'],
            'comma list' => ['Crosby, Stills & Nash', 'Crosby'],
            'x collab' => ['Bad Bunny x Drake', 'Bad Bunny'],
            'with' => ['Elton John with Kiki Dee', 'Elton John'],
        ];
    }

    #[DataProvider('differentArtists')]
    public function test_artist_names_that_differ(string $a, string $b): void
    {
        $this->assertNotSame(Normalizer::artist($a), Normalizer::artist($b));
    }

    public static function sameAlbums(): array
    {
        return [
            'remastered year' => ['Abbey Road', 'Abbey Road (Remastered 2019)'],
            'year remaster' => ['Abbey Road', 'Abbey Road (2019 Remaster)'],
            'dash remastered' => ['Help!', 'Help! - Remastered 2009'],
            'deluxe edition' => ['Parachutes', 'Parachutes (Deluxe Edition)'],
            'brackets deluxe' => ['Parachutes', 'Parachutes [Deluxe]'],
            'super deluxe' => ['Sticky Fingers', 'Sticky Fingers (Super Deluxe)'],
            'anniversary' => ['Nevermind', 'Nevermind (20th Anniversary Edition)'],
            'expanded' => ['Rumours', 'Rumours (Expanded Edition)'],
            'digitally remastered' => ['Thriller', 'Thriller (Digitally Remastered)'],
            'two markers' => ['OK Computer', 'OK Computer (Remastered) [Deluxe Edition]'],
            'bonus tracks' => ['Back to Black', 'Back to Black (Bonus Track Version)'],
            'collectors' => ['Purple Rain', "Purple Rain (Collector's Edition)"],
            'case and diacritics' => ['Agaetis Byrjun', 'Ágætis byrjun'],
        ];
    }

    #[DataProvider('sameAlbums')]
    public function test_album_names_that_are_the_same(string $a, string $b): void
    {
        $this->assertSame(Normalizer::album($a), Normalizer::album($b));
    }

    public static function differentAlbums(): array
    {
        return [
            'live' => ['Abbey Road', 'Abbey Road (Live)'],
            'live remastered' => ['Unplugged', 'Unplugged (Live Remastered)'],
            'acoustic' => ['Parachutes', 'Parachutes (Acoustic)'],
            'mono' => ['Revolver', 'Revolver (Mono)'],
            'regional edition' => ['Kid A', 'Kid A (Japanese Edition)'],
            'year only' => ['1989', '1989 (2014)'],
            'remix album' => ['Thriller', 'Thriller (Remix)'],
            'demos' => ['Nevermind', 'Nevermind - Demos'],
            'the prefix is kept' => ['The Wall', 'Wall'],
            'volume' => ['Greatest Hits', 'Greatest Hits Vol. 2'],
        ];
    }

    #[DataProvider('differentAlbums')]
    public function test_album_names_that_differ(string $a, string $b): void
    {
        $this->assertNotSame(Normalizer::album($a), Normalizer::album($b));
    }

    public static function sameTracks(): array
    {
        return [
            'dash remastered' => ['Help!', 'Help! - Remastered 2009'],
            'paren remaster' => ['Something', 'Something (2019 Remaster)'],
            'feat.' => ['Get Lucky', 'Get Lucky (feat. Pharrell Williams)'],
            'feat. brackets' => ['Get Lucky', 'Get Lucky [feat. Pharrell Williams]'],
            'dash feat.' => ['Uptown Funk', 'Uptown Funk - feat. Bruno Mars'],
            'bonus track' => ['Hidden', 'Hidden (Bonus Track)'],
            'punctuation' => ['Don’t Stop Me Now', "Don't Stop Me Now"],
            'case' => ['yellow', 'Yellow'],
        ];
    }

    #[DataProvider('sameTracks')]
    public function test_track_names_that_are_the_same(string $a, string $b): void
    {
        $this->assertSame(Normalizer::track($a), Normalizer::track($b));
    }

    public static function differentTracks(): array
    {
        return [
            'live' => ['Yellow', 'Yellow - Live'],
            'live at' => ['Yellow', 'Yellow (Live at Glastonbury)'],
            'radio edit' => ['Levels', 'Levels (Radio Edit)'],
            'acoustic' => ['Creep', 'Creep (Acoustic)'],
            'remix' => ['Blue Monday', 'Blue Monday - 1988 Remix'],
            'unbracketed feat. stays' => ['Feat. of Strength', 'Strength'],
        ];
    }

    #[DataProvider('differentTracks')]
    public function test_track_names_that_differ(string $a, string $b): void
    {
        $this->assertNotSame(Normalizer::track($a), Normalizer::track($b));
    }

    public function test_a_name_of_only_punctuation_keys_as_itself(): void
    {
        $this->assertSame('!!!', Normalizer::artist('!!!'));
        $this->assertNotSame(Normalizer::artist('!!!'), Normalizer::artist('???'));
    }

    public function test_non_latin_names_are_kept(): void
    {
        $this->assertSame(Normalizer::artist('坂本龍一'), Normalizer::artist(' 坂本龍一 '));
        $this->assertNotSame('', Normalizer::album('東京'));
        $this->assertSame('방탄소년단', Normalizer::artist('방탄소년단'), 'Hangul is not left decomposed');
    }

    public function test_a_title_that_is_only_an_edition_marker_is_kept(): void
    {
        $this->assertNotSame('', Normalizer::album('(Deluxe)'));
        $this->assertSame(Normalizer::album('Remastered'), 'remastered');
    }
}
