<?php

namespace Tests\Feature;

use App\Domain\Alerts\AlertChecks;
use App\Domain\Alerts\Alerts;
use App\Domain\Catalogue\PhysicalItems;
use App\Domain\Ripper\Rips;
use App\Domain\Tasks\UserTasks;
use App\Livewire\RipDisc;
use App\Mcp\Servers\ReMomentServer;
use App\Mcp\Tools;
use App\Models\AdminToken;
use App\Models\Client;
use App\Models\File;
use App\Models\Media\Album;
use App\Models\NodeJob;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ripping a CD on a node (18.1, docs/architecture/node-ripper.md): the disc check, the MusicBrainz candidates, the rip job,
 * progress, the node upload, the physical link, the alert. No drive, no cyanrip, no real node: the node's calls are made
 * by hand with its Bearer token, MusicBrainz is faked.
 */
class RipperTest extends TestCase
{
    use RefreshDatabase;

    /** The release of tests/Fixtures/ripper-tagged.flac. */
    private const RELEASE = 'a060c352-c9d6-4717-aab8-95a2965fa8d7';

    private const DISC = 'Wn8eRBtfLDfM0qjYPdxrz.Zjs_U-';

    private string $base;

    /** @var list<array<string, mixed>>|null what MusicBrainz answers for the disc id (null: 404) */
    private ?array $byDisc = null;

    /** @var list<array<string, mixed>>|null for the TOC (null: 404) */
    private ?array $byToc = null;

    /** @var list<array<string, mixed>> answers for `release/{id}`, one per call */
    private array $releases = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->byDisc = [$this->release()];
        Http::fake([
            'musicbrainz.org/ws/2/discid/-*' => fn () => $this->byToc === null ? Http::response(['error' => 'Not found'], 404) : Http::response(['releases' => $this->byToc]),
            'musicbrainz.org/ws/2/discid/*' => fn () => $this->byDisc === null ? Http::response(['error' => 'Not found'], 404) : Http::response(['releases' => $this->byDisc]),
            'musicbrainz.org/ws/2/release/*' => fn () => Http::response(array_shift($this->releases) ?? ['error' => 'Not found']),
        ]);
        $this->base = sys_get_temp_dir().'/remoment-rips-'.uniqid();
        Fs::makeDirectory($this->base.'/library', 0777, true);
        StorageLocation::create(['name' => 'library', 'driver' => 'local', 'role' => 'library', 'config' => ['root' => $this->base.'/library']]);
    }

    protected function tearDown(): void
    {
        Fs::deleteDirectory($this->base);
        parent::tearDown();
    }

    private function node(array $roles = ['ripper'], array $enabled = ['ripper'], array $extra = []): Client
    {
        return Client::create($extra + [
            'name' => 'Pi with a drive', 'kind' => 'node', 'status' => 'approved', 'hardware_id' => 'n'.uniqid(), 'registration_token' => Client::generateToken(),
            'api_token' => Client::generateToken(), 'roles' => $roles, 'enabled_roles' => $enabled, 'last_seen_at' => now(),
            'capabilities' => ['ripper' => ['drive' => '/dev/sr0', 'engine' => 'cyanrip']],
        ]);
    }

    /** @return array<string, string> the Authorization header of a fresh admin token */
    private function adminHeaders(): array
    {
        User::query()->exists() || User::factory()->create();
        [, $plain] = AdminToken::issue('test');

        return ['Authorization' => 'Bearer '.$plain];
    }

    /** @return array<string, string> */
    private function bearer(Client $node): array
    {
        return ['Authorization' => 'Bearer '.$node->api_token];
    }

    /** A MusicBrainz discid answer: one release with a 3-track medium that carries the disc. */
    private function release(string $id = self::RELEASE, string $title = 'Fiction', string $barcode = '0602537531159'): array
    {
        return [
            'id' => $id, 'title' => $title, 'date' => '2005-05-17', 'country' => 'SE', 'barcode' => $barcode,
            'artist-credit' => [['name' => 'Dark Tranquillity', 'joinphrase' => '']],
            'release-group' => ['id' => 'rg-1'],
            'label-info' => [['label' => ['name' => 'Century Media'], 'catalog-number' => 'CM 1']],
            'media' => [['position' => 1, 'format' => 'CD', 'discs' => [['id' => self::DISC]], 'tracks' => [
                ['position' => 1, 'title' => 'Fiction', 'length' => 200000, 'artist-credit' => [['name' => 'Dark Tranquillity']]],
                ['position' => 2, 'title' => 'Focus Shift', 'length' => 210000],
                ['position' => 3, 'title' => 'Terminus', 'length' => 220000],
            ]]],
        ];
    }

    /** What the node does for a job: fetch it, accept it, answer. @return array<string, mixed> the job as the node fetched it */
    private function nodeRuns(Client $node, bool $ok, ?array $result = null, ?string $error = null, ?array $progress = null): array
    {
        $job = $this->getJson('/api/nodes/job', $this->bearer($node))->assertOk()->json('job');
        $this->assertNotNull($job, 'the node has a job');
        $this->postJson("/api/nodes/jobs/{$job['id']}/accept", [], $this->bearer($node))->assertOk();
        if ($progress) {
            $this->postJson("/api/nodes/jobs/{$job['id']}/progress", ['progress' => $progress], $this->bearer($node))->assertOk();
        }
        $this->postJson("/api/nodes/jobs/{$job['id']}/result", array_filter(['ok' => $ok, 'result' => $result, 'error' => $error], fn ($v) => $v !== null), $this->bearer($node))->assertOk();

        return $job;
    }

    private function discPresent(): array
    {
        return ['present' => true, 'audio' => true, 'disc_id' => self::DISC, 'tracks' => 3, 'toc' => '1 3 40000 150 15000 30000'];
    }

    /** A checked disc with the release as candidate; returns the check's id. */
    private function checked(Client $node, ?array $releases = null): string
    {
        $this->byDisc = $releases ?? [$this->release()];
        $this->travel(2)->seconds(); // the lookup job is behind the one-a-second `musicbrainz` limiter
        $id = $this->postJson('/api/admin/rips', ['node_id' => $node->id], $this->adminHeaders())->assertStatus(202)->json('id');
        $this->nodeRuns($node, true, $this->discPresent());

        return $id;
    }

    public function test_the_rippers_list_is_open_and_has_only_nodes_with_the_role_on(): void
    {
        $node = $this->node();
        $this->node(['ripper'], []);
        $this->node(['line-in'], ['line-in']);

        $this->getJson('/api/nodes/rippers')->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [[
            'id' => $node->id, 'name' => 'Pi with a drive', 'node_online' => true, 'drive' => '/dev/sr0', 'engine' => 'cyanrip', 'disc' => null, 'busy' => false,
        ]]]);
    }

    public function test_the_admin_routes_need_the_admin_token(): void
    {
        foreach ([['GET', '/api/admin/rips'], ['POST', '/api/admin/rips'], ['GET', '/api/admin/rips/x'], ['POST', '/api/admin/rips/x/cancel']] as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        }
    }

    public function test_checking_the_disc_queues_one_job_and_lists_the_candidates_when_the_node_answers(): void
    {
        $node = $this->node();

        $first = $this->postJson('/api/admin/rips', ['node_id' => $node->id], $this->adminHeaders())->assertStatus(202)->assertJson(['kind' => 'probe', 'state' => 'probing']);
        $this->postJson('/api/admin/rips', ['node_id' => $node->id], $this->adminHeaders())->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, NodeJob::where('type', 'ripper.status')->count());

        $this->nodeRuns($node, true, $this->discPresent());

        $show = $this->getJson('/api/admin/rips/'.$first->json('id'), $this->adminHeaders())->assertOk()
            ->assertJson(['state' => 'choose_release', 'lookup' => 'discid', 'disc' => ['present' => true, 'disc_id' => self::DISC, 'tracks' => 3]]);
        $c = $show->json('candidates.0');
        $this->assertSame([self::RELEASE, 'Dark Tranquillity', 'Fiction', 2005, '0602537531159', 3], [$c['release_mbid'], $c['artist'], $c['title'], $c['year'], $c['barcode'], count($c['tracks'])]);
        $this->assertSame('Terminus', $c['tracks'][2]['title']);

        $this->getJson('/api/nodes/rippers')->assertJsonPath('data.0.disc.disc_id', self::DISC)->assertJsonPath('data.0.disc.tracks', 3);
    }

    public function test_an_unknown_disc_id_falls_back_to_the_toc_and_then_to_no_candidates(): void
    {
        $node = $this->node();
        [$this->byDisc, $this->byToc] = [null, [$this->release()]];
        $id = $this->postJson('/api/admin/rips', ['node_id' => $node->id], $this->adminHeaders())->json('id');
        $this->nodeRuns($node, true, $this->discPresent());
        $this->getJson("/api/admin/rips/{$id}", $this->adminHeaders())->assertJson(['state' => 'choose_release', 'lookup' => 'toc'])->assertJsonCount(1, 'candidates');

        // a release whose medium has another track count is no candidate
        $odd = $this->release();
        $odd['media'][0]['tracks'] = [['position' => 1, 'title' => 'Only']];
        $other = $this->checked($node, [$odd]);
        $this->getJson("/api/admin/rips/{$other}", $this->adminHeaders())->assertJson(['state' => 'choose_release', 'lookup' => 'none', 'candidates' => []]);
    }

    public function test_no_disc_and_a_data_disc_end_the_check(): void
    {
        $node = $this->node();
        $id = $this->postJson('/api/admin/rips', ['node_id' => $node->id], $this->adminHeaders())->json('id');
        $this->nodeRuns($node, true, ['present' => false]);
        $this->getJson("/api/admin/rips/{$id}", $this->adminHeaders())->assertJson(['state' => 'no_disc']);
        $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => self::RELEASE], $this->adminHeaders())->assertStatus(422)->assertJson(['error' => 'no_disc']);

        $id = $this->postJson('/api/admin/rips', ['node_id' => $node->id], $this->adminHeaders())->json('id');
        $this->nodeRuns($node, true, ['present' => true, 'audio' => false]);
        $this->getJson("/api/admin/rips/{$id}", $this->adminHeaders())->assertJson(['state' => 'not_audio']);
    }

    public function test_a_failed_musicbrainz_lookup_still_lets_the_admin_choose(): void
    {
        $node = $this->node();
        Queue::fake();
        $id = $this->postJson('/api/admin/rips', ['node_id' => $node->id], $this->adminHeaders())->json('id');
        $this->nodeRuns($node, true, $this->discPresent());
        $this->getJson("/api/admin/rips/{$id}", $this->adminHeaders())->assertJson(['state' => 'looking_up']);

        Rips::lookupFailed($id, 'MusicBrainz is busy');
        $this->getJson("/api/admin/rips/{$id}", $this->adminHeaders())->assertJson(['state' => 'choose_release', 'lookup_error' => 'MusicBrainz is busy', 'candidates' => []]);
    }

    public function test_confirming_a_candidate_queues_the_rip_with_the_release_as_tags_and_not_a_command(): void
    {
        $node = $this->node();
        $this->checked($node);

        $rip = $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => strtoupper(self::RELEASE)], $this->adminHeaders())
            ->assertStatus(202)->assertJson(['kind' => 'rip', 'state' => 'queued', 'release' => ['release_mbid' => self::RELEASE, 'title' => 'Fiction', 'tracks' => 3]]);

        $job = NodeJob::findOrFail($rip->json('id'));
        $this->assertSame('ripper.rip', $job->type);
        $this->assertSame(['release_mbid', 'disc_id', 'meta'], array_keys($job->params));
        $this->assertSame(self::DISC, $job->params['disc_id']);
        $this->assertSame('Dark Tranquillity', $job->params['meta']['artist']);
        $this->assertSame('0602537531159', $job->params['meta']['barcode']);
        $this->assertSame('Focus Shift', $job->params['meta']['tracks'][1]['title']);

        // a double click is the same rip, another disc is refused while it runs
        $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => self::RELEASE], $this->adminHeaders())->assertOk()->assertJsonPath('id', $job->id);
        $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => '11111111-1111-1111-1111-111111111111'], $this->adminHeaders())->assertStatus(409)->assertJson(['error' => 'busy']);
        $this->getJson('/api/nodes/rippers')->assertJsonPath('data.0.busy', true)->assertJsonPath('data.0.rip_id', $job->id);
    }

    public function test_refusals_when_starting(): void
    {
        $node = $this->node();
        $h = $this->adminHeaders();

        $this->postJson('/api/admin/rips', ['node_id' => 999], $h)->assertStatus(404);
        $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => 'nope'], $h)->assertStatus(422);
        $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => self::RELEASE], $h)->assertStatus(422)->assertJson(['error' => 'not_probed']);

        $off = $this->node(['ripper'], []);
        $this->postJson('/api/admin/rips', ['node_id' => $off->id], $h)->assertStatus(422)->assertJson(['error' => 'unsupported']);
        $gone = $this->node(['ripper'], ['ripper'], ['last_seen_at' => now()->subHour()]);
        $this->postJson('/api/admin/rips', ['node_id' => $gone->id], $h)->assertStatus(503)->assertJson(['error' => 'node_offline']);

        // a release that is not a candidate is looked up; one with another track count is refused
        $this->checked($node);
        $odd = $this->release('22222222-2222-2222-2222-222222222222');
        $odd['media'][0]['tracks'] = [['position' => 1, 'title' => 'Only']];
        $this->releases = [$odd, $this->release('33333333-3333-3333-3333-333333333333', 'Fiction (bonus)')];
        $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => '22222222-2222-2222-2222-222222222222'], $h)->assertStatus(422)->assertJson(['error' => 'unknown_release']);

        $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => '33333333-3333-3333-3333-333333333333'], $h)->assertStatus(202)->assertJsonPath('release.title', 'Fiction (bonus)');
    }

    public function test_a_stale_check_is_not_trusted(): void
    {
        $node = $this->node();
        $this->checked($node);
        $this->travel(2)->hours();
        $node->update(['last_seen_at' => now()]);

        $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => self::RELEASE], $this->adminHeaders())->assertStatus(422)->assertJson(['error' => 'not_probed']);
        $this->getJson('/api/nodes/rippers')->assertJsonPath('data.0.disc', null);
    }

    /** @return array{0: Client, 1: NodeJob} a node that accepted a rip */
    private function ripping(): array
    {
        $node = $this->node();
        $this->checked($node);
        $id = $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => self::RELEASE], $this->adminHeaders())->json('id');
        $this->getJson('/api/nodes/job', $this->bearer($node))->assertOk()->assertJsonPath('job.id', $id);
        $this->postJson("/api/nodes/jobs/{$id}/accept", [], $this->bearer($node))->assertOk();

        return [$node, NodeJob::findOrFail($id)];
    }

    private function flac(string $name = '03 Terminus.flac'): UploadedFile
    {
        copy(__DIR__.'/../Fixtures/ripper-tagged.flac', $this->base.'/'.$name);

        return new UploadedFile($this->base.'/'.$name, $name, 'audio/flac', null, true);
    }

    public function test_progress_shows_in_the_rip_and_the_task_tray(): void
    {
        [$node, $job] = $this->ripping();

        $this->postJson("/api/nodes/jobs/{$job->id}/progress", ['progress' => ['phase' => 'ripping', 'track' => 2, 'tracks' => 3, 'percent' => 50]], $this->bearer($node))->assertOk();
        $rip = $this->getJson("/api/admin/rips/{$job->id}", $this->adminHeaders())->assertOk()->assertJson(['state' => 'ripping', 'progress' => ['track' => 2, 'tracks' => 3]]);
        $this->assertSame(45, $rip->json('percent')); // (1 + .5) / 3 of the first 90 %
        $task = collect(UserTasks::active())->firstWhere('kind', 'rip');
        $this->assertSame(['Ripping Dark Tranquillity – Fiction', 'running', 45], [$task['title'], $task['state'], $task['percent']]);

        $this->postJson("/api/nodes/jobs/{$job->id}/progress", ['progress' => ['phase' => 'uploading', 'track' => 1, 'tracks' => 3]], $this->bearer($node))->assertOk();
        $this->getJson("/api/admin/rips/{$job->id}", $this->adminHeaders())->assertJson(['state' => 'uploading', 'percent' => 93]);

        $this->postJson("/api/nodes/jobs/{$job->id}/progress", ['progress' => ['track' => 1000]], $this->bearer($node))->assertStatus(422);
    }

    public function test_the_node_uploads_through_its_own_token_only_during_a_rip(): void
    {
        $node = $this->node();
        $this->postJson('/api/nodes/files', ['file' => $this->flac()], $this->bearer($node))->assertStatus(403)->assertJson(['error' => 'no_rip_job']);
        $this->post('/api/nodes/files', ['file' => $this->flac()], ['Accept' => 'application/json'])->assertStatus(401);
        $this->post('/api/nodes/files', ['file' => $this->flac()], $this->adminHeaders() + ['Accept' => 'application/json'])->assertStatus(401);

        [$node, $job] = $this->ripping();
        $res = $this->post('/api/nodes/files', ['file' => $this->flac()], $this->bearer($node) + ['Accept' => 'application/json'])->assertCreated();
        $this->assertNotNull($res->json('track_id'));
        $fileId = $res->json('file.id');
        $this->assertSame([$fileId], Rips::uploadedFileIds($job));

        // the same bytes again (a retry) are the same file
        $again = $this->post('/api/nodes/files', ['file' => $this->flac()], $this->bearer($node) + ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame($fileId, $again->json('file.id'));
        $this->assertSame(1, File::count());

        // not audio is refused; a cancelled rip may not upload any more
        $this->post('/api/nodes/files', ['file' => UploadedFile::fake()->createWithContent('a.txt', 'x')], $this->bearer($node) + ['Accept' => 'application/json'])->assertStatus(422);
        $this->postJson("/api/admin/rips/{$job->id}/cancel", [], $this->adminHeaders())->assertOk();
        $this->post('/api/nodes/files', ['file' => $this->flac()], $this->bearer($node) + ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_a_finished_rip_marks_the_cd_ripped_and_links_the_album_even_when_the_tags_were_missing(): void
    {
        $cd = PhysicalItems::add(['artist' => 'Dark Tranquillity', 'title' => 'Fiction', 'medium' => 'cd', 'release_mbid' => self::RELEASE]);
        [$node, $job] = $this->ripping();
        $this->post('/api/nodes/files', ['file' => $this->flac()], $this->bearer($node) + ['Accept' => 'application/json'])->assertCreated();
        $cd->forceFill(['ripped_at' => null, 'file_id' => null, 'album_id' => null])->save(); // as if the tags had not linked it

        $this->postJson("/api/nodes/jobs/{$job->id}/result", ['ok' => true, 'result' => ['tracks' => 1, 'log' => 'AccurateRip: all tracks match', 'warnings' => ['track 2: no tags']]], $this->bearer($node))->assertOk();

        $album = Album::where('name', 'Fiction')->firstOrFail();
        $this->assertSame($album->id, $cd->fresh()->album_id);
        $this->assertNotNull($cd->fresh()->ripped_at);
        $rip = $this->getJson("/api/admin/rips/{$job->id}", $this->adminHeaders())->assertJson(['state' => 'done', 'percent' => 100, 'log' => 'AccurateRip: all tracks match', 'warnings' => ['track 2: no tags']]);
        $this->assertCount(1, $rip->json('files'));
        $this->assertSame([], UserTasks::active());
        $this->assertFalse(Alerts::isActive('rip.failed', $job->id));
    }

    public function test_a_failed_or_lost_rip_raises_an_alert_that_a_later_success_resolves(): void
    {
        [$node, $job] = $this->ripping();
        $this->postJson("/api/nodes/jobs/{$job->id}/result", ['ok' => false, 'error' => 'disc_changed'], $this->bearer($node))->assertOk();
        $this->assertTrue(Alerts::isActive('rip.failed', $job->id));
        $alert = Alerts::active()->firstWhere('kind', 'rip.failed');
        $this->assertStringContainsString('Dark Tranquillity – Fiction', $alert->title);
        $this->assertStringContainsString('disc_changed', $alert->message);
        $this->getJson("/api/admin/rips/{$job->id}", $this->adminHeaders())->assertJson(['state' => 'failed', 'error' => 'disc_changed']);

        // lost: the node died, the lease runs out, the check finds it
        $this->checked($node);
        $second = $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => self::RELEASE], $this->adminHeaders())->assertStatus(202)->json('id');
        $this->getJson('/api/nodes/job', $this->bearer($node));
        $this->postJson("/api/nodes/jobs/{$second}/accept", [], $this->bearer($node))->assertOk();
        $this->travel(5)->minutes();
        (new AlertChecks)->rips();
        $this->assertTrue(Alerts::isActive('rip.failed', $second));
        $this->getJson("/api/admin/rips/{$second}", $this->adminHeaders())->assertJson(['state' => 'failed']);

        // a rip that succeeds later clears both
        $node->update(['last_seen_at' => now()]);
        $this->checked($node);
        $third = $this->postJson('/api/admin/rips', ['node_id' => $node->id, 'release_mbid' => self::RELEASE], $this->adminHeaders())->json('id');
        $this->getJson('/api/nodes/job', $this->bearer($node));
        $this->postJson("/api/nodes/jobs/{$third}/accept", [], $this->bearer($node));
        $this->postJson("/api/nodes/jobs/{$third}/result", ['ok' => true, 'result' => ['tracks' => 3]], $this->bearer($node))->assertOk();
        (new AlertChecks)->rips();
        $this->assertFalse(Alerts::isActive('rip.failed', $job->id));
        $this->assertFalse(Alerts::isActive('rip.failed', $second));
    }

    public function test_a_cancelled_rip_is_cancelled_and_raises_nothing(): void
    {
        [$node, $job] = $this->ripping();
        $this->postJson("/api/admin/rips/{$job->id}/cancel", [], $this->adminHeaders())->assertOk();
        $this->postJson("/api/nodes/jobs/{$job->id}/progress", [], $this->bearer($node))->assertOk()->assertJson(['cancel_requested' => true]);
        $this->postJson("/api/nodes/jobs/{$job->id}/result", ['ok' => false, 'error' => 'cancelled'], $this->bearer($node))->assertOk();

        $this->getJson("/api/admin/rips/{$job->id}", $this->adminHeaders())->assertJson(['state' => 'cancelled']);
        $this->assertSame([], Alerts::active()->where('kind', 'rip.failed')->all());
    }

    public function test_the_list_has_checks_and_rips_newest_first(): void
    {
        [$node, $job] = $this->ripping();

        $list = $this->getJson('/api/admin/rips', $this->adminHeaders())->assertOk()->json('data');
        $this->assertSame([$job->id, 'probe'], [$list[0]['id'], $list[1]['kind']]);
        $this->assertArrayNotHasKey('log', $list[0]);
    }

    public function test_the_card_is_on_the_collection_page_and_starts_a_check_for_the_admin(): void
    {
        $this->get('/library/collection')->assertOk()->assertDontSee('data-test="rip-disc"', false);

        $node = $this->node();
        $this->get('/library/collection')->assertOk()->assertSee('Rip this disc')->assertSee('Sign in as the admin to rip a disc');

        Livewire::test(RipDisc::class)->call('check', $node->id)->assertStatus(403);

        $this->actingAs(User::factory()->create());
        Livewire::test(RipDisc::class)->assertSee('Check the disc')->call('check', $node->id)->assertSee('Reading the disc');
        $this->assertSame(1, NodeJob::where('type', 'ripper.status')->count());
    }

    public function test_the_card_offers_the_candidates_and_follows_the_rip(): void
    {
        $this->actingAs(User::factory()->create());
        $node = $this->node();
        $this->checked($node);

        Livewire::test(RipDisc::class)->assertSee('Choose the release this disc is')->assertSee('Fiction')->assertSee('Dark Tranquillity')
            ->call('rip', $node->id, self::RELEASE)->assertSee('Waiting for the node')
            ->call('rip', $node->id, '11111111-1111-1111-1111-111111111111')->assertSee('is ripping another disc');

        $job = NodeJob::where('type', 'ripper.rip')->firstOrFail();
        $this->getJson('/api/nodes/job', $this->bearer($node));
        $this->postJson("/api/nodes/jobs/{$job->id}/accept", [], $this->bearer($node));
        $this->postJson("/api/nodes/jobs/{$job->id}/progress", ['progress' => ['phase' => 'ripping', 'track' => 2, 'tracks' => 3, 'percent' => 0]], $this->bearer($node));
        Livewire::test(RipDisc::class)->assertSee('Ripping track 2 of 3')->call('cancel', $job->id);
        $this->assertTrue($job->fresh()->cancel_requested);
    }

    public function test_a_manual_release_id_must_be_a_uuid(): void
    {
        $this->actingAs(User::factory()->create());
        $node = $this->node();
        $this->checked($node);

        Livewire::test(RipDisc::class)->set('manual', [$node->id => 'not an id'])->call('ripManual', $node->id)->assertSee('not a MusicBrainz release id');
    }

    public function test_the_mcp_tool_is_admin_only_and_goes_through_the_api(): void
    {
        ReMomentServer::tool(Tools\Rips::class, ['action' => 'nodes'])->assertNotRegistered();

        User::factory()->create();
        [, $plain] = AdminToken::issue('test');
        request()->headers->set('Authorization', 'Bearer '.$plain);
        $node = $this->node();

        ReMomentServer::tool(Tools\Rips::class, ['action' => 'nodes'])->assertOk()->assertSee('Pi with a drive');
        ReMomentServer::tool(Tools\Rips::class, ['action' => 'check', 'node_id' => $node->id])->assertOk()->assertSee('probing');
        ReMomentServer::tool(Tools\Rips::class, ['action' => 'start', 'node_id' => $node->id])->assertHasErrors(['release_mbid is required']);
        ReMomentServer::tool(Tools\Rips::class, ['action' => 'start', 'node_id' => $node->id, 'release_mbid' => self::RELEASE])->assertHasErrors(['not_probed (422)']);
        ReMomentServer::tool(Tools\Rips::class, ['action' => 'list'])->assertOk()->assertSee('probe');
    }
}
