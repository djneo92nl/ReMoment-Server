<?php

namespace App\Domain\Ripper;

use App\Domain\Alerts\Alerts;
use App\Domain\Catalogue\PhysicalRipLink;
use App\Domain\Node\NodeJobs;
use App\Jobs\LookupRipCandidates;
use App\Models\Client;
use App\Models\File;
use App\Models\NodeJob;
use App\Services\MusicBrainz\MusicBrainzClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Ripping a CD on a ReMoment Node (workstream 18.1, docs/architecture/node-ripper.md). A "rip" is two kinds of node
 * job, no table of its own: a `ripper.status` job (the disc check: is there a disc, its TOC and MusicBrainz disc id,
 * then the releases MusicBrainz knows for it, stored on the job's result) and a `ripper.rip` job (the rip of one chosen
 * release, with progress, the upload receipts and the rip log). The id of a rip is its job's id.
 *
 * Flow: check the disc (`start` without a release) → the card or the client reads the candidates → `start` with one of
 * them. REST is authoritative; the node only runs the two job types and uploads the FLAC files through the node upload.
 */
final class Rips
{
    public const PROBE = 'ripper.status';

    public const RIP = 'ripper.rip';

    private const OPEN = ['queued', 'accepted', 'running'];

    /** @return Collection<int, Client> approved nodes with the ripper role switched on */
    public static function nodes(): Collection
    {
        return Client::query()->where('kind', 'node')->where('status', 'approved')->orderBy('id')->get()
            ->filter(fn (Client $c) => in_array('ripper', $c->activeRoles(), true))->values();
    }

    public static function find(int $nodeId): ?Client
    {
        return self::nodes()->firstWhere('id', $nodeId);
    }

    public static function label(Client $node): string
    {
        return $node->name ?: "node #{$node->id}";
    }

    public static function online(Client $node): bool
    {
        return $node->last_seen_at !== null && $node->last_seen_at->gt(now()->subSeconds((int) config('alerts.node_offline_seconds', 90)));
    }

    // ---- nodes -------------------------------------------------------------------------------------------------

    /**
     * What `GET /api/nodes/rippers` lists per node: can it rip, is a disc in (as of the last check), is it busy.
     *
     * @return array<string, mixed>
     */
    public static function describeNode(Client $node): array
    {
        NodeJobs::sweep($node);
        $role = $node->node_status['roles']['ripper'] ?? null;
        $probe = self::lastProbe($node);
        $open = self::openRip($node);

        return [
            'id' => $node->id,
            'name' => self::label($node),
            'node_online' => self::online($node),
            'node_state' => is_array($role) ? ($role['state'] ?? 'unknown') : 'unknown',
            'detail' => is_array($role) ? ($role['detail'] ?? null) : null,
            'drive' => $node->capabilities['ripper']['drive'] ?? null,
            'engine' => $node->capabilities['ripper']['engine'] ?? null,
            'disc' => self::discOf($probe),
            'busy' => $open !== null,
            'rip_id' => $open?->id,
            'probe_id' => $probe?->id,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function rippers(): array
    {
        return self::nodes()->map(fn (Client $n) => self::describeNode($n))->all();
    }

    private static function lastProbe(Client $node): ?NodeJob
    {
        return NodeJob::where('client_id', $node->id)->where('type', self::PROBE)->latest('id')->first();
    }

    /** The disc as of the last check, or null when there was none or it is older than `ripper.disc_fresh_seconds`. @return array<string, mixed>|null */
    private static function discOf(?NodeJob $probe): ?array
    {
        if ($probe === null || $probe->state !== 'succeeded' || $probe->finished_at === null
            || $probe->finished_at->lt(now()->subSeconds((int) config('ripper.disc_fresh_seconds')))) {
            return null;
        }

        $r = (array) $probe->result;

        return [
            'present' => (bool) ($r['present'] ?? false),
            'audio' => (bool) ($r['audio'] ?? false),
            'disc_id' => $r['disc_id'] ?? null,
            'tracks' => (int) ($r['tracks'] ?? 0),
            'checked_at' => $probe->finished_at->toIso8601String(),
        ];
    }

    public static function openRip(Client $node): ?NodeJob
    {
        NodeJobs::sweep($node);

        return NodeJob::where('client_id', $node->id)->where('type', self::RIP)->whereIn('state', self::OPEN)->latest('id')->first();
    }

    // ---- starting ----------------------------------------------------------------------------------------------

    /**
     * Without a release: check the disc (an open check is reused). With one of the candidates of the last check (or any
     * release with as many tracks as the disc): queue the rip. @return array{0: NodeJob, 1: bool} the job and whether it is new
     *
     * @throws RipException
     */
    public static function start(Client $node, ?string $releaseMbid = null): array
    {
        if (!in_array('ripper', $node->activeRoles(), true)) {
            throw new RipException('unsupported', self::label($node).' has not got the ripper role switched on.');
        }
        if (!self::online($node)) {
            throw new RipException('node_offline', self::label($node).' is offline.', 503);
        }

        $releaseMbid = $releaseMbid === null ? null : strtolower(trim($releaseMbid));

        if ($open = self::openRip($node)) {
            if ($releaseMbid !== null && ($open->params['release_mbid'] ?? null) === $releaseMbid) {
                return [$open, false]; // a double click
            }

            throw new RipException('busy', self::label($node).' is ripping another disc.', 409);
        }

        if ($releaseMbid === null) {
            $probe = NodeJob::where('client_id', $node->id)->where('type', self::PROBE)->whereIn('state', self::OPEN)->latest('id')->first();

            return $probe ? [$probe, false] : [NodeJobs::create($node, self::PROBE), true];
        }

        $probe = self::lastProbe($node);
        $disc = self::discOf($probe);
        if ($disc === null) {
            throw new RipException('not_probed', 'Check the disc first: the last check is missing or older than '.(int) (config('ripper.disc_fresh_seconds') / 60).' minutes.');
        }
        if (!$disc['present']) {
            throw new RipException('no_disc', 'There is no disc in the drive.');
        }
        if (!$disc['audio']) {
            throw new RipException('not_audio', 'The disc in the drive is not an audio CD.');
        }

        $result = (array) $probe->result;
        $meta = collect($result['candidates'] ?? [])->firstWhere('release_mbid', $releaseMbid);
        if ($meta === null) {
            try {
                $meta = DiscLookup::release($releaseMbid, (string) $disc['disc_id'], (int) $disc['tracks']);
            } catch (\Throwable $e) {
                throw new RipException('lookup_failed', 'MusicBrainz could not be reached: '.$e->getMessage(), 502);
            }
            if ($meta === null) {
                throw new RipException('unknown_release', 'MusicBrainz has no such release, or it has no medium with '.$disc['tracks'].' tracks.');
            }
        }

        $job = NodeJobs::create($node, self::RIP, [
            'release_mbid' => $releaseMbid,
            'disc_id' => $disc['disc_id'],
            'meta' => [
                'album' => $meta['title'], 'artist' => $meta['artist'], 'year' => $meta['year'], 'barcode' => $meta['barcode'],
                'label' => $meta['label'], 'catalogue_number' => $meta['catalogue_number'],
                'tracks' => array_map(fn ($t) => ['number' => $t['number'], 'title' => $t['title'], 'artist' => $t['artist']], $meta['tracks']),
            ],
        ]);

        return [$job, true];
    }

    public static function cancel(NodeJob $job): NodeJob
    {
        return NodeJobs::cancel($job);
    }

    // ---- reading -----------------------------------------------------------------------------------------------

    /** The newest check or rip of a node, while it is open or finished less than six hours ago (what the card shows). @return array<string, mixed>|null */
    public static function latest(Client $node): ?array
    {
        NodeJobs::sweep($node);
        $job = NodeJob::where('client_id', $node->id)->whereIn('type', [self::PROBE, self::RIP])->latest('id')->first();

        return $job && ($job->finished_at === null || $job->finished_at->gt(now()->subHours(6))) ? self::payload($job) : null;
    }

    public static function findJob(string $id): ?NodeJob
    {
        return NodeJob::whereIn('type', [self::PROBE, self::RIP])->where('id', $id)->first();
    }

    /** @return list<array<string, mixed>> newest first */
    public static function list(int $limit = 30): array
    {
        NodeJobs::sweep();

        return NodeJob::whereIn('type', [self::PROBE, self::RIP])->latest('id')->limit($limit)->get()
            ->map(fn (NodeJob $j) => self::payload($j))->all();
    }

    /**
     * The job as a rip: `state` is probing, looking_up, no_disc, not_audio, choose_release (the check is in and the
     * candidates, maybe none, wait), queued, ripping, uploading, done, failed or cancelled.
     *
     * @return array<string, mixed>
     */
    public static function payload(NodeJob $job, bool $detail = false): array
    {
        $node = Client::find($job->client_id);
        $r = (array) $job->result;
        $progress = NodeJobs::progressOf($job);
        $isRip = $job->type === self::RIP;

        $out = [
            'id' => $job->id,
            'kind' => $isRip ? 'rip' : 'probe',
            'node_id' => $job->client_id,
            'node_name' => $node ? self::label($node) : null,
            'state' => self::state($job, $progress),
            'error' => $job->error,
            'created_at' => $job->created_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
        ];

        if (!$isRip) {
            return $out + [
                'disc' => $job->state === 'succeeded' ? [
                    'present' => (bool) ($r['present'] ?? false), 'audio' => (bool) ($r['audio'] ?? false),
                    'disc_id' => $r['disc_id'] ?? null, 'tracks' => (int) ($r['tracks'] ?? 0),
                ] : null,
                'lookup' => $r['lookup'] ?? null,
                'lookup_error' => $r['lookup_error'] ?? null,
                'candidates' => array_key_exists('candidates', $r) ? $r['candidates'] : null,
            ];
        }

        $p = (array) $job->params;
        $meta = (array) ($p['meta'] ?? []);
        $out += [
            'release' => [
                'release_mbid' => $p['release_mbid'] ?? null, 'artist' => $meta['artist'] ?? null, 'title' => $meta['album'] ?? null,
                'year' => $meta['year'] ?? null, 'barcode' => $meta['barcode'] ?? null, 'tracks' => count($meta['tracks'] ?? []),
            ],
            'disc_id' => $p['disc_id'] ?? null,
            'progress' => $progress ? array_intersect_key($progress, array_flip(['phase', 'track', 'tracks', 'percent', 'detail'])) : null,
            'percent' => self::percent($job->state, $progress),
            'files' => self::uploadedFileIds($job),
            'warnings' => $r['warnings'] ?? [],
        ];
        if ($detail) {
            $out['log'] = $r['log'] ?? null;
        }

        return $out;
    }

    /** @param  array<string, mixed>|null  $progress */
    private static function state(NodeJob $job, ?array $progress): string
    {
        if ($job->type === self::RIP) {
            return match (true) {
                $job->state === 'queued' => 'queued',
                in_array($job->state, ['accepted', 'running'], true) => ($progress['phase'] ?? 'ripping') === 'uploading' ? 'uploading' : 'ripping',
                $job->state === 'succeeded' => 'done',
                $job->state === 'cancelled' => 'cancelled',
                default => 'failed', // failed, lost, expired
            };
        }

        if (in_array($job->state, self::OPEN, true)) {
            return 'probing';
        }
        if ($job->state !== 'succeeded') {
            return $job->state === 'cancelled' ? 'cancelled' : 'failed';
        }

        $r = (array) $job->result;

        return match (true) {
            !($r['present'] ?? false) => 'no_disc',
            !($r['audio'] ?? false) => 'not_audio',
            array_key_exists('candidates', $r) || isset($r['lookup_error']) => 'choose_release',
            default => 'looking_up',
        };
    }

    /** Overall percent of a rip: the ripping phase is the first 90 %, the upload the rest. @param  array<string, mixed>|null  $progress */
    private static function percent(string $state, ?array $progress): ?int
    {
        if ($state === 'succeeded') {
            return 100;
        }
        if (!in_array($state, ['accepted', 'running'], true) || $progress === null) {
            return null;
        }

        $tracks = max(1, (int) ($progress['tracks'] ?? 0));
        $track = max(0, (int) ($progress['track'] ?? 0));
        $within = (float) ($progress['percent'] ?? 0) / 100;

        if (($progress['phase'] ?? 'ripping') === 'uploading') {
            return min(99, 90 + (int) floor($track / $tracks * 10));
        }

        return min(90, (int) floor((max(0, $track - 1) + $within) / $tracks * 90));
    }

    // ---- what the node uploaded --------------------------------------------------------------------------------

    /** The open rip job of the node that sent a file, or null: only then may a node upload (docs/architecture/node-ripper.md). */
    public static function uploadJob(Client $node): ?NodeJob
    {
        NodeJobs::sweep($node);

        return NodeJob::where('client_id', $node->id)->where('type', self::RIP)->whereIn('state', ['accepted', 'running'])->where('cancel_requested', false)->latest('id')->first();
    }

    public static function noteUpload(NodeJob $job, File $file): void
    {
        Cache::put(self::filesKey($job), array_values(array_unique([...self::uploadedFileIds($job), $file->id])), 86400 * 7);
    }

    /** @return list<int> */
    public static function uploadedFileIds(NodeJob $job): array
    {
        return Cache::get(self::filesKey($job), []);
    }

    private static function filesKey(NodeJob $job): string
    {
        return "rip:files:{$job->id}";
    }

    // ---- disc check results ------------------------------------------------------------------------------------

    /** After a node job finished (the result route): a disc check starts the MusicBrainz lookup, a rip ends its alert or raises one. */
    public static function afterFinish(NodeJob $job): void
    {
        if ($job->type === self::PROBE && $job->state === 'succeeded') {
            $r = (array) $job->result;
            if (($r['present'] ?? false) && ($r['audio'] ?? false) && !empty($r['disc_id'])) {
                LookupRipCandidates::dispatch($job->id);
            } else {
                self::storeResult($job, ['candidates' => []]);
            }

            return;
        }

        if ($job->type !== self::RIP) {
            return;
        }

        if ($job->state === 'succeeded') {
            self::linkPhysical($job);
            Alerts::resolve('rip.failed', $job->id);

            return;
        }

        self::raiseFailed($job);
    }

    /** The queued lookup: the releases for the checked disc, merged into the check's result. */
    public static function lookup(string $jobId, ?MusicBrainzClient $mb = null): void
    {
        $job = NodeJob::where('type', self::PROBE)->find($jobId);
        if ($job === null) {
            return;
        }

        $r = (array) $job->result;
        self::storeResult($job, DiscLookup::candidates((string) $r['disc_id'], $r['toc'] ?? null, (int) ($r['tracks'] ?? 0), $mb));
    }

    public static function lookupFailed(string $jobId, string $message): void
    {
        if ($job = NodeJob::where('type', self::PROBE)->find($jobId)) {
            self::storeResult($job, ['candidates' => [], 'lookup' => 'none', 'lookup_error' => mb_substr($message, 0, 300)]);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private static function storeResult(NodeJob $job, array $extra): void
    {
        $job->result = array_merge((array) $job->result, $extra);
        $job->save();
    }

    // ---- outcome -----------------------------------------------------------------------------------------------

    /**
     * Ties the uploaded files to the CD on the shelf. The node writes the release id and barcode into the tags, which
     * already links them when the file is indexed; this repeats it from what the server knows, so a ripper that did not
     * write the tags still links. Idempotent (PhysicalRipLink skips an item that is linked to the album already).
     */
    private static function linkPhysical(NodeJob $job): void
    {
        $p = (array) $job->params;
        $tags = ['release_mbid' => $p['release_mbid'] ?? null, 'barcode' => $p['meta']['barcode'] ?? null];

        foreach (File::whereIn('id', self::uploadedFileIds($job))->get() as $file) {
            if ($album = $file->tracks()->first()?->album) {
                PhysicalRipLink::linkQuietly($file, $album, $tags);
            }
        }
    }

    private static function raiseFailed(NodeJob $job): void
    {
        if (!in_array($job->state, ['failed', 'lost', 'expired'], true)) {
            return; // cancelled on purpose: no alert
        }

        $meta = (array) (((array) $job->params)['meta'] ?? []);
        $what = trim(($meta['artist'] ?? '').' – '.($meta['album'] ?? 'a disc'), ' –');
        $node = Client::find($job->client_id);
        $why = match ($job->state) {
            'lost' => 'The node stopped reporting while it ripped.',
            'expired' => 'The node never picked the rip up.',
            default => $job->error ?: 'The rip failed.',
        };

        Alerts::raise('rip.failed', $job->id, 'warning', "Rip of {$what} failed", $why.' ('.($node ? self::label($node) : 'node').')',
            data: ['job_id' => $job->id, 'node_id' => $job->client_id, 'state' => $job->state]);
    }

    /** alerts:check: raise for failed, lost and expired rips of the last `ripper.alert_hours` (the last two never reach the result route), resolve the rest. */
    public static function checkAlerts(): void
    {
        NodeJobs::sweep();
        $jobs = NodeJob::where('type', self::RIP)->where('created_at', '>=', now()->subHours((int) config('ripper.alert_hours')))->get();

        $live = [];
        foreach ($jobs as $job) {
            $laterOk = $jobs->contains(fn ($o) => $o->client_id === $job->client_id && $o->state === 'succeeded' && $o->id > $job->id);
            if (in_array($job->state, ['failed', 'lost', 'expired'], true) && !$laterOk) {
                self::raiseFailed($job);
                $live[] = $job->id;
            }
        }

        foreach (Alerts::active()->where('kind', 'rip.failed')->whereNotIn('key', $live) as $stale) {
            Alerts::resolve('rip.failed', $stale->key);
        }
    }

    // ---- task tray ---------------------------------------------------------------------------------------------

    /** @return list<array{key: string, kind: string, title: string, state: string, percent: ?int, estimated: bool, detail: ?string}> */
    public static function tasks(): array
    {
        return NodeJob::where('type', self::RIP)->whereIn('state', self::OPEN)->oldest('id')->limit(5)->get()->map(function (NodeJob $j) {
            $meta = (array) (((array) $j->params)['meta'] ?? []);
            $progress = NodeJobs::progressOf($j);

            return [
                'key' => "rip:{$j->id}", 'kind' => 'rip',
                'title' => 'Ripping '.trim(($meta['artist'] ?? '').' – '.($meta['album'] ?? 'a disc'), ' –'),
                'state' => $j->state === 'queued' ? 'queued' : 'running',
                'percent' => self::percent($j->state, $progress), 'estimated' => false,
                'detail' => $progress ? self::detail($progress) : null,
            ];
        })->all();
    }

    /** @param  array<string, mixed>  $progress */
    private static function detail(array $progress): string
    {
        $verb = ($progress['phase'] ?? 'ripping') === 'uploading' ? 'Uploading' : 'Ripping';

        return $verb.' track '.(int) ($progress['track'] ?? 0).' of '.(int) ($progress['tracks'] ?? 0);
    }
}
