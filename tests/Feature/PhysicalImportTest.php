<?php

namespace Tests\Feature;

use App\Domain\Catalogue\PhysicalImport;
use App\Domain\Catalogue\PhysicalItems;
use App\Jobs\LookupPhysicalBarcode;
use App\Livewire\PhysicalCollection;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\Client;
use App\Models\Media\Artist;
use App\Models\Media\CatalogueAlbum;
use App\Models\Media\PhysicalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

/** CSV import of the physical collection: parsing, duplicates, preview and confirm, the queued barcode lookups (docs/api/physical.md). */
class PhysicalImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Http::preventStrayRequests();
    }

    private const DISCOGS = <<<'CSV'
Catalog#,Artist,Title,Label,Format,Rating,Released,release_id,CollectionFolder,Date Added,Collection Media Condition,Collection Sleeve Condition,Collection Notes
AMB 3922,Aphex Twin,Selected Ambient Works 85-92,Apollo,"Vinyl, LP, Album",0,1992,123,Uncategorized,2020-05-01 12:34:56,Near Mint (NM or M-),Very Good Plus (VG+),first pressing
GED 24,Nirvana (2),Nevermind,Geffen,CD,0,1991,456,Shelf B,2021-01-02 10:00:00,,,
,Some Band,Tape Demo,Self,Cassette,0,0,789,Uncategorized,2021-01-03 10:00:00,,,
CSV;

    private function row(array $rows, int $number): array
    {
        return collect($rows)->firstWhere('row', $number);
    }

    private function release(string $id = 'rel-1', string $artist = 'Band', string $title = 'First Album', string $format = 'CD'): array
    {
        return ['id' => $id, 'title' => $title, 'date' => '1999-05-01', 'country' => 'NL', 'release-group' => ['id' => '11111111-1111-1111-1111-111111111111'],
            'artist-credit' => [['name' => $artist]], 'label-info' => [['catalog-number' => 'CAT 1', 'label' => ['name' => 'Label']]], 'media' => [['format' => $format]]];
    }

    public function test_a_generic_csv_is_read_by_its_headers(): void
    {
        $csv = "\xEF\xBB\xBFArtist;Album;Format;Release Year;UPC;Shelf;Notes\nBand;First Album;CD;1999;0602 537-531159;B2;signed\nMan;Demo;Cassette (C60);;;Box 4;\nBlur;Parklife;12\" vinyl;1994-04-25;;;\n";

        $parsed = PhysicalImport::parse($csv);

        $this->assertSame('generic', $parsed['format']);
        $this->assertSame(['artist' => 'Artist', 'title' => 'Album', 'medium' => 'Format', 'year' => 'Release Year', 'barcode' => 'UPC', 'location' => 'Shelf', 'notes' => 'Notes'], $parsed['mapping']);
        [$a, $b, $c] = $parsed['rows'];
        $this->assertSame(['Band', 'First Album', 'cd', 1999, '0602537531159', 'B2', 'signed'], [$a['artist'], $a['title'], $a['medium'], $a['year'], $a['barcode'], $a['location'], $a['notes']]);
        $this->assertSame(['tape', null, null, 'Box 4'], [$b['medium'], $b['year'], $b['barcode'], $b['location']]);
        $this->assertSame(['vinyl', 1994], [$c['medium'], $c['year']]);
        $this->assertSame([2, 3, 4], array_column($parsed['rows'], 'row'));
    }

    public function test_the_medium_is_read_from_free_text(): void
    {
        foreach (['CD' => 'cd', '2xCD, Album' => 'cd', 'Vinyl, LP, Album' => 'vinyl', 'LP' => 'vinyl', '7" Single' => 'vinyl', 'Cassette' => 'tape', 'MC' => 'tape', 'MiniDisc' => 'minidisc', 'MD' => 'minidisc', 'DVD' => 'other', 'File' => 'other'] as $text => $medium) {
            $this->assertSame($medium, PhysicalImport::medium($text), $text);
        }
        $this->assertNull(PhysicalImport::medium(' '));
    }

    public function test_a_discogs_collection_export_is_recognised(): void
    {
        $parsed = PhysicalImport::parse(self::DISCOGS);

        $this->assertSame('discogs', $parsed['format']);
        $this->assertContains('Label', $parsed['unmapped']);
        [$vinyl, $cd, $tape] = $parsed['rows'];
        $this->assertSame(['Aphex Twin', 'vinyl', 1992, 'AMB 3922', 'Near Mint (NM or M-)', '2020-05-01', null], [$vinyl['artist'], $vinyl['medium'], $vinyl['year'], $vinyl['catalogue_number'], $vinyl['condition'], $vinyl['acquired_on'], $vinyl['location']]);
        $this->assertSame("first pressing\nSleeve: Very Good Plus (VG+)", $vinyl['notes']);
        $this->assertSame(['Nirvana', 'cd', 'Shelf B'], [$cd['artist'], $cd['medium'], $cd['location']], 'Discogs disambiguation "(2)" is dropped, a real folder becomes the location');
        $this->assertSame(['tape', null], [$tape['medium'], $tape['year']], 'a year of 0 is no year');
    }

    public function test_the_mapping_names_a_column_explicitly(): void
    {
        $parsed = PhysicalImport::parse("Who,What,How\nBand,First Album,vinyl\n", null, ['artist' => 'Who', 'title' => 'what', 'medium' => 'HOW']);

        $this->assertSame(['Band', 'First Album', 'vinyl'], [$parsed['rows'][0]['artist'], $parsed['rows'][0]['title'], $parsed['rows'][0]['medium']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("no column 'Nope'");
        PhysicalImport::parse("Who,What\nBand,x\n", null, ['artist' => 'Nope']);
    }

    public function test_a_file_without_usable_columns_or_rows_is_refused(): void
    {
        foreach (["Foo,Bar\n1,2\n", '', "\n\n"] as $text) {
            try {
                PhysicalImport::parse($text);
                $this->fail('refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rows_that_cannot_be_imported_say_why(): void
    {
        $csv = "artist,title,medium,barcode\nBand,Album,cd,6.02537E+12\nBand,Album,,\nBand,,cd,\n,,cd,\nBand,Album,laserdisc,\nBand,Short,cd,123\n";
        $preview = PhysicalImport::preview($csv);

        $this->assertSame(['new' => 1, 'invalid' => 5], array_filter($preview['summary'], fn ($n, $k) => $k !== 'rows' && $n > 0, ARRAY_FILTER_USE_BOTH));
        $this->assertSame('new', $this->row($preview['data'], 6)['status'], 'an unknown medium is "other", not invalid');
        $this->assertStringContainsString('scientific notation', $this->row($preview['data'], 2)['reason']);
        $this->assertStringContainsString('No medium', $this->row($preview['data'], 3)['reason']);
        $this->assertStringContainsString('Title is missing', $this->row($preview['data'], 4)['reason']);
        $this->assertStringContainsString('no barcode', $this->row($preview['data'], 5)['reason']);
        $this->assertSame('other', PhysicalImport::parse($csv)['rows'][4]['medium']);
        $this->assertStringContainsString('6 to 20 digits', $this->row($preview['data'], 7)['reason']);

        $this->assertSame('cd', PhysicalImport::parse("artist,title\nBand,Album\n", 'cd')['rows'][0]['medium'], 'the default medium fills the gap');
    }

    public function test_duplicates_are_found_by_barcode_by_name_and_inside_the_file(): void
    {
        PhysicalItems::add(['artist' => 'The Band', 'title' => 'First Album (Remastered)', 'medium' => 'cd']);
        PhysicalItems::add(['artist' => 'Other', 'title' => 'Other Album', 'medium' => 'cd', 'barcode' => '602537531159']);
        $existing = PhysicalItem::orderBy('id')->pluck('id')->all();

        $csv = implode("\n", [
            'artist,title,medium,barcode',
            'Band,First Album,cd,',                    // 2: same artist, title and medium (keys ignore "The" and edition markers)
            'Band,First Album,vinyl,',                 // 3: another medium: new
            'Whoever,Different,cd,0602537531159',      // 4: the barcode of an item, written as EAN-13 with a leading zero
            'New,New Album,cd,',                       // 5: new
            'new,NEW ALBUM,cd,',                       // 6: same as row 5
            'Looked,Up,cd,5024396991122',              // 7: lookup
            'Looked Again,Up Again,cd,5024396991122',  // 8: same barcode as row 7
        ])."\n";

        $rows = PhysicalImport::preview($csv)['data'];

        $this->assertSame(['duplicate', 'new', 'duplicate', 'new', 'duplicate', 'lookup', 'duplicate'], array_column($rows, 'status'));
        $this->assertSame([$existing[0], null, $existing[1], null], [$rows[0]['duplicate_of'], $rows[1]['duplicate_of'], $rows[2]['duplicate_of'], $rows[3]['duplicate_of']]);
        $this->assertStringContainsString('this artist, title and medium', $rows[0]['reason']);
        $this->assertStringContainsString('this barcode', $rows[2]['reason']);
        $this->assertStringContainsString('row 5 of the file', $rows[4]['reason']);
        $this->assertStringContainsString('row 7 of the file', $rows[6]['reason']);
    }

    public function test_the_preview_writes_nothing_and_the_confirm_adds_the_rows(): void
    {
        Queue::fake();
        $csv = "artist,title,medium,year,barcode,location\nBand,First Album,cd,1999,,Shelf B2\nMan,Demo,tape,,,\nNamed,Has Barcode,cd,2001,5024396991122,\n,,cd,,0602537531159,\nBand,First Album,cd,,,\n,,,,,\nBad,Row,,,,\n";

        $preview = $this->postJson('/api/library/physical/import', ['csv' => $csv])->assertOk()
            ->assertJsonPath('status', 'preview')->assertJsonPath('format', 'generic')
            ->assertJsonPath('summary', ['rows' => 6, 'new' => 2, 'lookup' => 2, 'duplicate' => 1, 'invalid' => 1])
            ->assertJsonPath('truncated', false)->json();

        $this->assertSame(0, PhysicalItem::count(), 'a preview adds nothing');
        $this->assertSame(['new', 'new', 'lookup', 'lookup', 'duplicate', 'invalid'], array_column($preview['data'], 'status'));
        Queue::assertNothingPushed();

        $this->postJson("/api/library/physical/import/{$preview['token']}/confirm")->assertOk()
            ->assertJson(['status' => 'ok', 'added' => 3, 'queued_lookups' => 2, 'skipped_duplicates' => 1, 'skipped_invalid' => 1]);

        $this->assertSame(['Demo', 'First Album', 'Has Barcode'], PhysicalItem::orderBy('title')->pluck('title')->all(), 'the barcode-only row is added by its lookup, later');
        $this->assertSame(['Shelf B2', 1999], [PhysicalItem::where('title', 'First Album')->value('location'), PhysicalItem::where('title', 'First Album')->value('year')]);
        Queue::assertPushed(LookupPhysicalBarcode::class, 2);

        $delays = [];
        Queue::assertPushed(LookupPhysicalBarcode::class, function (LookupPhysicalBarcode $job) use (&$delays) {
            $delays[] = (int) round(now()->diffInSeconds($job->delay, false));

            return true;
        });
        $this->assertGreaterThanOrEqual(PhysicalImport::LOOKUP_SPACING - 1, $delays[1] - $delays[0], 'lookups are spread out, one every few seconds');

        $this->postJson("/api/library/physical/import/{$preview['token']}/confirm")->assertNotFound()->assertJson(['error' => 'not_found']);
        $this->assertSame(3, PhysicalItem::count(), 'a preview is used up');
    }

    public function test_the_confirm_classifies_again_so_a_row_added_meanwhile_is_not_doubled(): void
    {
        Queue::fake();
        $preview = $this->postJson('/api/library/physical/import', ['csv' => "artist,title,medium\nBand,First Album,cd\n"])->json();
        $this->postJson('/api/library/physical', ['artist' => 'Band', 'title' => 'First Album', 'medium' => 'cd'])->assertCreated();

        $this->postJson("/api/library/physical/import/{$preview['token']}/confirm")->assertJson(['added' => 0, 'skipped_duplicates' => 1]);
        $this->assertSame(1, PhysicalItem::count());
    }

    public function test_the_api_takes_a_multipart_file_a_default_medium_and_a_mapping(): void
    {
        $file = UploadedFile::fake()->createWithContent('shelf.csv', "Who,What\nBand,First Album\n");

        $this->post('/api/library/physical/import', ['file' => $file, 'default_medium' => 'vinyl', 'mapping' => ['artist' => 'Who', 'title' => 'What']], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.0.medium', 'vinyl')->assertJsonPath('mapping.artist', 'Who');

        $this->postJson('/api/library/physical/import', ['csv' => "a,b\n1,2\n"])->assertStatus(422)->assertJson(['error' => 'invalid']);
        $this->postJson('/api/library/physical/import', [])->assertStatus(422);
        $this->postJson('/api/library/physical/import', ['csv' => "artist,title\nBand,x\n", 'default_medium' => 'laserdisc'])->assertStatus(422);
        $this->assertCount(1, $this->postJson('/api/library/physical/import', ['csv' => "artist,title\nBand,x\nBand,y\n", 'default_medium' => 'cd', 'rows' => 1])->assertJsonPath('truncated', true)->json('data'));
    }

    public function test_client_devices_are_refused(): void
    {
        Client::create(['name' => 'Touch', 'type' => 'multi', 'status' => 'approved', 'api_token' => 'tok', 'registration_token' => 'reg']);

        $this->postJson('/api/library/physical/import?client=tok', ['csv' => "artist,title,medium\nA,B,cd\n"])->assertForbidden();
        $this->postJson('/api/library/physical/import/abc/confirm?client=tok')->assertForbidden();
    }

    public function test_an_expired_preview_is_not_found(): void
    {
        $token = $this->postJson('/api/library/physical/import', ['csv' => "artist,title,medium\nA,B,cd\n"])->json('token');
        Cache::flush();

        $this->postJson("/api/library/physical/import/{$token}/confirm")->assertNotFound();
    }

    public function test_the_lookup_ties_an_added_item_to_its_release_and_closes_the_wish(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::response(['releases' => [$this->release('rel-vinyl', 'Band', 'First Album', 'Vinyl'), $this->release('rel-cd', 'Band', 'First Album', 'CD')]])]);
        $artist = Artist::create(['name' => 'Band']);
        $wish = CatalogueAlbum::create(['artist_id' => $artist->id, 'release_group_mbid' => '11111111-1111-1111-1111-111111111111', 'title' => 'First Album', 'type' => 'album', 'seen_at' => now(), 'wanted_at' => now()]);
        $item = PhysicalItems::add(['artist' => 'The Band', 'title' => 'First Album', 'medium' => 'cd', 'barcode' => '5024396991122']);

        (new LookupPhysicalBarcode($item, ['barcode' => '5024396991122', 'medium' => 'cd']))->handle();

        $item->refresh();
        $this->assertSame(['rel-cd', '11111111-1111-1111-1111-111111111111', 'CAT 1', 1999], [$item->release_mbid, $item->release_group_mbid, $item->catalogue_number, $item->year], 'the release of the same medium');
        $this->assertNotNull($wish->fresh()->fulfilled_at, 'the release-group the item now belongs to is owned');

        Http::assertSentCount(1);
        (new LookupPhysicalBarcode($item, ['barcode' => '5024396991122', 'medium' => 'cd']))->handle();
        Http::assertSentCount(1); // already tied to a release: no second request
    }

    public function test_the_lookup_leaves_an_item_alone_when_the_release_is_by_someone_else(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::response(['releases' => [$this->release('rel-x', 'Somebody Else', 'Other Title')]])]);
        $item = PhysicalItems::add(['artist' => 'Band', 'title' => 'First Album', 'medium' => 'cd', 'barcode' => '5024396991122']);

        (new LookupPhysicalBarcode($item, ['barcode' => '5024396991122', 'medium' => 'cd']))->handle();

        $this->assertNull($item->fresh()->release_mbid);
    }

    public function test_a_barcode_only_row_is_added_from_the_release_and_keeps_what_the_row_said(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::response(['releases' => [$this->release('rel-1', 'Band', 'First Album', 'CD')]])]);
        $fields = ['barcode' => '0602537531159', 'medium' => 'vinyl', 'location' => 'Shelf B2', 'condition' => 'VG+'];

        (new LookupPhysicalBarcode(null, $fields))->handle();

        $item = PhysicalItem::firstOrFail();
        $this->assertSame(['Band', 'First Album', 'vinyl', 'Shelf B2', 'VG+', 'rel-1', 1999], [$item->artist, $item->title, $item->medium, $item->location, $item->condition, $item->release_mbid, $item->year]);

        (new LookupPhysicalBarcode(null, $fields))->handle(); // a retry
        $this->assertSame(1, PhysicalItem::count());
    }

    public function test_a_barcode_musicbrainz_does_not_know_is_kept_under_a_placeholder_name(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::response(['releases' => []])]);

        (new LookupPhysicalBarcode(null, ['barcode' => '0602537531159', 'medium' => 'cd', 'notes' => 'from a friend']))->handle();

        $item = PhysicalItem::firstOrFail();
        $this->assertSame(['Unknown artist', 'Barcode 0602537531159'], [$item->artist, $item->title]);
        $this->assertStringContainsString('from a friend', $item->notes);
        $this->assertStringContainsString('knows no release', $item->notes);
        $this->assertCount(1, $this->getJson('/api/library/physical?q=0602537531159')->json('data'), 'searchable by its barcode');
    }

    public function test_a_musicbrainz_outage_throws_so_the_job_is_retried_and_a_failed_job_keeps_the_row(): void
    {
        Http::fake(['musicbrainz.org/ws/2/release*' => Http::response('busy', 503)]);
        $job = new LookupPhysicalBarcode(null, ['barcode' => '0602537531159', 'medium' => 'cd']);

        try {
            $job->handle();
            $this->fail('a 503 must throw');
        } catch (\Throwable) {
            $this->assertSame(0, PhysicalItem::count());
        }

        $job->failed(new \RuntimeException('gave up'));

        $this->assertSame('Barcode 0602537531159', PhysicalItem::firstOrFail()->title);
        $this->assertStringContainsString('could not be reached', PhysicalItem::firstOrFail()->notes);
    }

    public function test_the_lookup_job_is_rate_limited_like_the_other_musicbrainz_jobs(): void
    {
        $job = new LookupPhysicalBarcode(null, ['barcode' => '0602537531159']);

        $limiter = $job->middleware()[0];
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\RateLimited::class, $limiter);
        $this->assertSame('musicbrainz', (new \ReflectionProperty($limiter, 'limiterName'))->getValue($limiter));
        $this->assertSame(0, $job->tries, 'a release by the limiter is not a failed attempt');
    }

    public function test_the_web_page_previews_and_imports_a_csv(): void
    {
        Queue::fake();
        $csv = "artist,title,medium,barcode\nBand,First Album,cd,\nMan,Demo,tape,\nBand,First Album,cd,\n,,cd,0602537531159\n";

        Livewire::test(PhysicalCollection::class)
            ->set('csvText', $csv)->call('previewImport')
            ->assertSee('Nothing has been added yet')->assertSee('4 rows')->assertSee('Duplicate: 1')->assertSee('Look up barcode: 1')
            ->assertSeeHtml('data-test="import-row-3"')
            ->set('importFilter', 'duplicate')->assertSeeHtml('data-test="import-row-4"')->assertDontSeeHtml('data-test="import-row-3"')
            ->set('importFilter', '')->call('confirmImport')
            ->assertSee('Imported 2 rows')->assertSee('1 barcodes are looked up')->assertSee('Skipped 1 duplicates')
            ->assertDontSeeHtml('data-test="preview-result"');

        $this->assertSame(['Demo', 'First Album'], PhysicalItem::orderBy('title')->pluck('title')->all());
        Queue::assertPushed(LookupPhysicalBarcode::class, 1);
    }

    public function test_the_web_page_reads_an_uploaded_file_and_reports_a_file_it_cannot_use(): void
    {
        Livewire::test(PhysicalCollection::class)
            ->set('csvFile', UploadedFile::fake()->createWithContent('discogs.csv', self::DISCOGS))->call('previewImport')
            ->assertSee('Discogs collection export')->assertSee('Selected Ambient Works 85-92')->assertSee('Nirvana')
            ->call('cancelImport')->assertDontSee('Nirvana');

        Livewire::test(PhysicalCollection::class)->call('previewImport')->assertSee('Choose a CSV file or paste its text first.')
            ->set('csvText', "foo,bar\n1,2\n")->call('previewImport')->assertSee('No artist and title columns');

        Livewire::test(PhysicalCollection::class)->set('csvText', "artist,title\nBand,Album\n")->call('previewImport')->assertSee('No medium')
            ->set('importMedium', 'cd')->call('previewImport')->assertSee('New: 1');
        $this->assertSame(0, PhysicalItem::count());
    }

    public function test_the_mcp_tool_previews_and_confirms(): void
    {
        Queue::fake();
        ReMomentServer::tool(Tools\Physical::class, ['action' => 'import'])->assertHasErrors(['needs csv']);

        ReMomentServer::tool(Tools\Physical::class, ['action' => 'import', 'csv' => "artist,title\nBand,Album\nMan,Demo\n", 'default_medium' => 'cd'])
            ->assertOk()->assertSee('"new":2', false)->assertSee('"token"', false);
        $this->assertSame(0, PhysicalItem::count(), 'the preview adds nothing');

        $token = PhysicalImport::preview("artist,title\nBand,Album\nMan,Demo\n", 'cd')['token'];
        ReMomentServer::tool(Tools\Physical::class, ['action' => 'import', 'token' => $token])->assertOk()->assertSee('"added":2', false);
        $this->assertSame(2, PhysicalItem::count());
        ReMomentServer::tool(Tools\Physical::class, ['action' => 'import', 'token' => $token])->assertHasErrors(['not_found']);
    }
}
