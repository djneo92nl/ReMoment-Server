<?php

namespace App\Domain\Playback;

use App\Domain\Device\DeviceCache;
use App\Domain\Device\SpotifyRouting;
use App\Domain\Device\State;
use App\Domain\Library\LibraryPlayback;
use App\Domain\Media\NowPlaying;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Jobs\SyncFollower;
use App\Models\Device;
use App\Models\SyncGroup;
use App\Models\SyncGroupMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * "Fake multiroom": devices without native multiroom play the same thing, kept together by the server
 * (docs/architecture/sync-groups.md). The leader plays as always; each member is started on the leader's
 * queue and position (PlaybackMover), and SyncGroupMirror keeps it there: state, track changes, drift.
 *
 * What can be shared: library tracks and radio. Spotify can't (it plays on one Connect device).
 */
final class SyncGroups
{
    private const LEADERS_KEY = 'sync:leaders';

    public function __construct(
        private readonly PlaybackMover $mover,
    ) {}

    /** @return array<int, int> group id by leader device id; cached, so a leader's progress costs no query */
    public static function leaders(): array
    {
        try {
            return Cache::rememberForever(self::LEADERS_KEY, fn () => SyncGroup::query()->pluck('id', 'leader_device_id')->all());
        } catch (QueryException) {
            // Before `migrate` has made the table: no groups.
            return [];
        }
    }

    public static function forgetLeaders(): void
    {
        Cache::forget(self::LEADERS_KEY);
    }

    /** The group $device is in, as leader or member. */
    public static function groupOf(Device $device): ?SyncGroup
    {
        return SyncGroup::query()->where('leader_device_id', $device->id)
            ->orWhereHas('members', fn ($q) => $q->where('device_id', $device->id))
            ->first();
    }

    /** Whether $device can follow a leader: it plays library tracks or radio stations. */
    public static function canFollow(Device $device): bool
    {
        return !SpotifyRouting::isSpotify($device)
            && (LibraryPlayback::canPlayDlna($device) || is_a((string) $device->device_driver, RadioControlInterface::class, true));
    }

    /**
     * Makes a group and, when the leader plays something, starts the members on it.
     *
     * @param  int[]  $memberIds
     * @return array{group: SyncGroup, failed: array<int, array{device_id: int, message: string}>}
     *
     * @throws SyncGroupException
     */
    public function create(Device $leader, array $memberIds, ?string $name = null): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));

        if ($memberIds === [] || in_array($leader->id, $memberIds, true)) {
            throw new SyncGroupException('A group needs a leader and at least one other device.');
        }

        $devices = Device::whereIn('id', [$leader->id, ...$memberIds])->get()->keyBy('id');

        foreach ($devices as $device) {
            $this->assertFree($device);
        }

        if (SpotifyRouting::isSpotify($leader)) {
            throw new SyncGroupException('The Spotify device cannot lead a group: Spotify plays on one speaker.');
        }

        foreach ($memberIds as $id) {
            $this->assertCanFollow($devices[$id]);
        }

        $group = SyncGroup::create(['name' => $name, 'leader_device_id' => $leader->id]);
        $group->members()->createMany(array_map(fn (int $id) => ['device_id' => $id], $memberIds));
        self::forgetLeaders();

        return ['group' => $group, 'failed' => $this->start($group)];
    }

    /**
     * Adds a member, starting it at once when the leader plays.
     *
     * @return array<int, array{device_id: int, message: string}>
     *
     * @throws SyncGroupException
     */
    public function addMember(SyncGroup $group, Device $device): array
    {
        $this->assertFree($device);
        $this->assertCanFollow($device);

        $group->members()->create(['device_id' => $device->id]);

        return $this->start($group->refresh(), only: $device->id);
    }

    /** The member stops following; its own playback is stopped unless `$stop` is false. */
    public function removeMember(SyncGroup $group, Device $device, bool $stop = true): void
    {
        $member = $group->members()->where('device_id', $device->id)->firstOrFail();
        $member->delete();

        if ($stop) {
            $this->control($device, 'stop');
        }

        // A group of the leader alone is no group.
        if (!$group->members()->exists()) {
            $group->delete();
            self::forgetLeaders();
        }
    }

    /** Ends the group; the members' playback is stopped unless `$stopMembers` is false. */
    public function dissolve(SyncGroup $group, bool $stopMembers = true): void
    {
        $devices = $group->members()->with('device')->get()->pluck('device');
        $group->delete();
        self::forgetLeaders();

        if ($stopMembers) {
            foreach ($devices as $device) {
                $this->control($device, 'stop');
            }
        }
    }

    public function setOffset(SyncGroup $group, Device $device, int $offsetMs): SyncGroupMember
    {
        $member = $group->members()->where('device_id', $device->id)->firstOrFail();
        $member->update(['offset_ms' => max(-30_000, min(30_000, $offsetMs))]);

        return $member;
    }

    /**
     * Starts the members (or one) on what the leader plays now; nothing happens while it plays nothing, the
     * mirror starts them when it does.
     *
     * @return array<int, array{device_id: int, message: string}> the members that couldn't start
     */
    public function start(SyncGroup $group, ?int $only = null): array
    {
        if (!in_array(DeviceCache::getState($group->leader_device_id), [State::Playing, State::Paused], true)) {
            return [];
        }

        $failed = [];

        foreach ($group->members()->with('device')->get() as $member) {
            if ($only !== null && $member->device_id !== $only) {
                continue;
            }

            try {
                $this->startMember($group, $member);
            } catch (\Throwable $e) {
                $failed[] = ['device_id' => $member->device_id, 'message' => $e->getMessage()];
            }
        }

        return $failed;
    }

    /**
     * Starts one member on the leader's queue and seeks it to where the leader is by then (plus its offset).
     *
     * @throws NotMovableException|SyncGroupException|\RuntimeException
     */
    public function startMember(SyncGroup $group, SyncGroupMember $member): void
    {
        $leader = $group->leader;
        $nowPlaying = DeviceCache::getNowPlaying($leader->id);

        if ($nowPlaying !== null && PlaybackCapture::isSpotify($leader, $nowPlaying)) {
            throw new SyncGroupException('Spotify cannot be shared: it plays on one speaker. Play something from the library or radio.');
        }

        $this->mover->move($leader, $member->device, stopSource: false, position: fn () => $this->expectedPosition($group, $member));
    }

    /** Restarts the member unless it already plays what the leader plays (it advanced by itself). */
    public function resyncIfNeeded(SyncGroup $group, SyncGroupMember $member): void
    {
        $leaderNow = DeviceCache::getNowPlaying($group->leader_device_id);

        if ($leaderNow === null || !in_array(DeviceCache::getState($group->leader_device_id), [State::Playing, State::Paused], true)) {
            return;
        }

        $memberNow = DeviceCache::getNowPlaying($member->device_id);

        if ($memberNow !== null && self::key($memberNow) === self::key($leaderNow)
            && in_array(DeviceCache::getState($member->device_id), [State::Playing, State::Paused], true)) {
            return;
        }

        $this->startMember($group, $member);
    }

    /**
     * Queues a seek for each member that drifted further than the threshold from the leader. Reads only the
     * cache, so it is cheap to run while the leader reports progress; a member just seeked is left alone for a while.
     */
    public function correctDrift(SyncGroup $group): void
    {
        $leaderNow = DeviceCache::getNowPlaying($group->leader_device_id);

        // Radio and live streams can't be seeked.
        if ($leaderNow === null || $leaderNow->radio !== null || DeviceCache::getState($group->leader_device_id) !== State::Playing) {
            return;
        }

        foreach ($group->members as $member) {
            $memberNow = DeviceCache::getNowPlaying($member->device_id);

            if ($memberNow === null || DeviceCache::getState($member->device_id) !== State::Playing || self::key($memberNow) !== self::key($leaderNow)) {
                continue;
            }

            $expected = $this->expectedPosition($group, $member);

            if (abs($memberNow->position - $expected) <= (int) config('sync.drift_threshold')
                || !Cache::add("sync:{$group->id}:seeked:{$member->device_id}", true, (int) config('sync.seek_cooldown'))) {
                continue;
            }

            SyncFollower::dispatch($group->id, $member->device_id, 'seek', $expected);
        }
    }

    /** Where a member should be now: the leader's last reported position plus its offset. */
    public function expectedPosition(SyncGroup $group, SyncGroupMember $member): int
    {
        $leaderPosition = DeviceCache::getNowPlaying($group->leader_device_id)?->position ?? 0;

        return max(0, $leaderPosition + (int) round($member->offset_ms / 1000));
    }

    /** What a device plays, as a comparable string: the station, or the track by name and artist. */
    public static function key(NowPlaying $nowPlaying): string
    {
        if ($nowPlaying->radio !== null) {
            return 'radio:'.mb_strtolower((string) $nowPlaying->radio->name);
        }

        return 'track:'.mb_strtolower(trim((string) $nowPlaying->track?->name)).'|'.mb_strtolower(trim((string) $nowPlaying->track?->artist?->name));
    }

    /** Sends play, pause or stop to a device, best effort. */
    public function control(Device $device, string $action): void
    {
        $driver = SpotifyRouting::driverFor($device, MediaControlsInterface::class);

        if (!($driver instanceof MediaControlsInterface)) {
            return;
        }

        try {
            $driver->{$action}();
        } catch (\Throwable) {
            // A member that doesn't answer is picked up again at the next track change.
        }
    }

    public function seek(Device $device, int $seconds): void
    {
        $driver = SpotifyRouting::driverFor($device, SeekInterface::class);

        if ($driver instanceof SeekInterface) {
            $driver->seek($seconds);
        }
    }

    private function assertFree(Device $device): void
    {
        if (self::groupOf($device) !== null) {
            throw new SyncGroupException("{$device->device_name} is already in a sync group.");
        }
    }

    private function assertCanFollow(Device $device): void
    {
        if (!self::canFollow($device)) {
            throw new SyncGroupException("{$device->device_name} can't play the library or radio, so it can't follow a leader.");
        }
    }
}
