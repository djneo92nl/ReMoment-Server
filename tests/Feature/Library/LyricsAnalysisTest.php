<?php

namespace Tests\Feature\Library;

use App\Domain\Library\LeadingSource;
use App\Domain\Library\SmartPlaylist\SmartFields;
use App\Domain\Lyrics\LyricsAnalysis;
use App\Domain\Lyrics\LyricsExplicit;
use App\Domain\Lyrics\LyricsLanguage;
use App\Jobs\EnrichTrackLyrics;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Metadata;
use App\Models\Media\Track;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MakesLibraryTracks;
use Tests\TestCase;

/** Lyrics extras (roadmap 12.8): language and explicit flag detected locally from the stored lyrics, and their filters. */
class LyricsAnalysisTest extends TestCase
{
    use MakesLibraryTracks;
    use RefreshDatabase;

    private const SAMPLES = [
        'en' => "I don't know what you're doing but I just can't stop the way that you move with me. When the night is over and we are all alone, you know I'll be there for you, it's all that I have got.",
        'nl' => 'Ik weet niet wat je wilt maar ik blijf altijd bij jou. Het is niet gemakkelijk, maar wij hebben nog alles wat we nodig hebben voor de nacht, en dat is een mooi gevoel voor ons.',
        'de' => 'Ich weiß nicht, was du willst, aber ich bin immer bei dir. Es ist nicht leicht, doch wir haben noch alles, was wir brauchen, und das ist ein schönes Gefühl für uns beide.',
        'fr' => "Je ne sais pas ce que tu veux, mais je suis toujours avec toi. C'est pas facile, nous avons tout ce qu'il faut, et c'est une belle chose pour nous dans la nuit.",
        'es' => 'Yo no sé lo que quieres pero siempre estoy contigo. Todo está bien, nunca hay nada más que el corazón, y los días son muy largos cuando no estás aquí, mis amigos.',
        'it' => 'Io non so cosa vuoi ma sono sempre con te. Non è facile, ma abbiamo ancora tutto quello che serve, senza paura, nel cuore della notte, amore mio, anche se sei solo.',
        'pt' => 'Eu não sei o que você quer mas estou sempre com você. Não é fácil, mas nós temos ainda tudo, e isso é muito bom, vou ficar também, já que você tem o meu coração.',
        'sv' => 'Jag vet inte vad du vill men jag är alltid med dig. Det är inte lätt, men vi har ännu allt som vi behöver, och det är en fin känsla för oss alla, aldrig ensam, bara kärlek.',
        'ru' => 'Я не знаю, чего ты хочешь, но я всегда с тобой. Это нелегко, но у нас есть всё, что нужно, и это прекрасное чувство для нас обоих в ночи',
        'ja' => '何を望んでいるのか分からないけれど いつもあなたのそばにいる 簡単ではないけれど 私たちには必要なものがすべてある',
        'ko' => '네가 무엇을 원하는지 나는 모르지만 항상 네 곁에 있을 거야 쉽지 않지만 우리에게는 필요한 모든 것이 있어',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LeadingSource::SETTING, 'all');
    }

    private function track(string $name, ?string $plain = null, ?string $synced = null): Track
    {
        $artist = Artist::firstOrCreate(['name' => 'Someone'], ['source' => 'files']);
        $track = $this->makeLibraryTrack(Album::firstOrCreate(['artist_id' => $artist->id, 'name' => 'LP'], ['source' => 'files']), $name);
        $plain !== null && $this->meta($track, 'lyrics_plain', $plain, 'lrclib');
        $synced !== null && $this->meta($track, 'lyrics_synced', $synced, 'lrclib');

        return $track;
    }

    private function meta(Track $track, string $key, string $value, string $source): void
    {
        Metadata::create(['metadatable_type' => Track::class, 'metadatable_id' => $track->id, 'key' => $key, 'value' => $value, 'type' => 'string', 'source' => $source]);
    }

    public function test_the_detector_tells_the_languages_apart(): void
    {
        foreach (self::SAMPLES as $code => $text) {
            $this->assertSame($code, LyricsLanguage::detect($text), "sample for {$code}");
        }
        $this->assertSame('uk', LyricsLanguage::detect('Я не знаю, чого ти хочеш, але я завжди з тобою. Це нелегко, але в нас є все, що потрібно, і це гарне почуття їх'));
    }

    public function test_short_or_unclear_text_gives_null(): void
    {
        $this->assertNull(LyricsLanguage::detect('la la la la la'));
        $this->assertNull(LyricsLanguage::detect(str_repeat('qwxz vbnm lkjh ', 12)));
        $this->assertNull(LyricsLanguage::detect(''));
    }

    public function test_explicit_needs_a_whole_strong_word(): void
    {
        $this->assertTrue(LyricsExplicit::in("What the fuck is going on\nand you know it"));
        $this->assertTrue(LyricsExplicit::in('Dat is echt kut man'));
        $this->assertFalse(LyricsExplicit::in('Damn it, we went to hell and back, my ass is tired'));
        $this->assertFalse(LyricsExplicit::in('The Scunthorpe classic, assassin of shiitake mushrooms'), 'part of a longer word');
    }

    public function test_apply_stores_the_language_and_a_positive_guess_only_when_the_source_gave_none(): void
    {
        $lines = self::SAMPLES['en'];
        $swears = $this->track('Swears', $lines.' Oh shit, here we go.');
        $clean = $this->track('Clean', $lines);
        $spotifySaysClean = $this->track('Spotify', $lines.' Oh shit, here we go.');
        $this->meta($spotifySaysClean, 'explicit', '0', 'spotify');
        $synced = $this->track('Synced only', null, '[00:12.30] '.self::SAMPLES['nl']);

        foreach ([$swears, $clean, $spotifySaysClean, $synced] as $t) {
            LyricsAnalysis::apply($t);
        }

        $this->assertSame('en', LyricsAnalysis::language($swears));
        $this->assertSame('1', $swears->metadata()->where('key', 'explicit')->value('value'));
        $this->assertSame('lyrics', $swears->metadata()->where('key', 'explicit')->value('source'));
        $this->assertNull($clean->metadata()->where('key', 'explicit')->value('value'), 'a clean flag is never derived');
        $this->assertSame(['0'], $spotifySaysClean->metadata()->where('key', 'explicit')->pluck('value')->all(), 'the source wins');
        $this->assertSame('nl', LyricsAnalysis::language($synced), 'LRC time tags do not count as words');
        $this->assertTrue($swears->fresh()->details()['explicit']);
        $this->assertSame('en', $swears->fresh()->details()['lyrics_language']);
    }

    public function test_the_lyrics_job_runs_the_analysis(): void
    {
        Http::fake(['lrclib.net/*' => Http::response(['plainLyrics' => self::SAMPLES['de'], 'syncedLyrics' => null, 'instrumental' => false])]);
        $track = $this->track('Lied');

        (new EnrichTrackLyrics($track))->handle();

        $this->assertSame('de', LyricsAnalysis::language($track->fresh()));
    }

    public function test_the_command_reads_lyrics_stored_before_and_skips_the_done_ones(): void
    {
        $old = $this->track('Old', self::SAMPLES['fr']);
        $none = $this->track('No lyrics');
        $done = $this->track('Done', self::SAMPLES['en']);
        $this->meta($done, 'lyrics_language', 'xx', 'lyrics');

        $this->artisan('library:lyrics-analysis')->expectsOutputToContain('1 tracks read')->assertSuccessful();

        $this->assertSame('fr', LyricsAnalysis::language($old));
        $this->assertNull(LyricsAnalysis::language($none));
        $this->assertSame('xx', LyricsAnalysis::language($done));
    }

    public function test_the_tracks_list_filters_by_language_and_explicit_and_the_mcp_kind_passes_them(): void
    {
        $nl = $this->track('Nederlands', self::SAMPLES['nl'].' kut');
        $en = $this->track('English', self::SAMPLES['en']);
        $this->track('Silent');
        LyricsAnalysis::apply($nl);
        LyricsAnalysis::apply($en);

        $names = fn (string $q) => array_column($this->getJson("/api/library/tracks?{$q}")->assertOk()->json('data'), 'name');

        $this->assertSame(['Nederlands'], $names('lyrics_language=nl'));
        $this->assertSame(['English'], $names('lyrics_language=EN'));
        $this->assertSame(['Nederlands'], $names('explicit=1'));
        $this->assertEqualsCanonicalizing(['English', 'Silent'], $names('explicit=0'));
        $this->assertSame([], $names('lyrics_language=nl&explicit=0'));
        $this->getJson('/api/library/tracks?lyrics_language=dutch')->assertStatus(422);
        $this->assertSame(1, $this->getJson('/api/library/tracks?lyrics_language=nl&count_only=1')->json('total'));

        ReMomentServer::tool(Tools\BrowseLibrary::class, ['kind' => 'tracks', 'lyrics_language' => 'nl'])->assertOk()->assertSee('Nederlands')->assertDontSee('English');
    }

    public function test_the_smart_playlist_field_offers_the_languages_in_the_library(): void
    {
        $this->track('Nederlands', self::SAMPLES['nl']);
        LyricsAnalysis::apply(Track::where('name', 'Nederlands')->first());

        $field = SmartFields::find('lyrics_language');

        $this->assertSame(['nl' => 'Dutch'], $field->options());
        $query = Track::query();
        $field->apply($query, 'is', 'nl');
        $this->assertSame(['Nederlands'], $query->pluck('name')->all());
    }
}
