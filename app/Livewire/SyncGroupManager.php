<?php

namespace App\Livewire;

use App\Domain\Playback\SyncGroupException;
use App\Domain\Playback\SyncGroups;
use App\Models\Device;
use App\Models\SyncGroup;
use Livewire\Component;

/** Sync groups ("fake multiroom") on /multiroom: make, trim and end groups of devices that follow a leader. */
class SyncGroupManager extends Component
{
    public bool $creating = false;

    public ?int $leaderId = null;

    /** @var array<int> */
    public array $memberIds = [];

    public string $name = '';

    public ?string $feedback = null;

    public bool $feedbackIsError = false;

    public function render()
    {
        $inGroup = SyncGroup::with('members')->get()->flatMap(fn (SyncGroup $g) => [$g->leader_device_id, ...$g->members->pluck('device_id')])->all();

        return view('livewire.sync-group-manager', [
            'groups' => SyncGroup::with('leader', 'members.device')->orderBy('id')->get(),
            'candidates' => Device::where('hidden', false)->orderBy('device_name')->get()
                ->filter(fn (Device $d) => !in_array($d->id, $inGroup, true) && SyncGroups::canFollow($d))
                ->values(),
        ]);
    }

    public function create(SyncGroups $groups): void
    {
        $this->validate([
            'leaderId' => 'required|integer|exists:devices,id',
            'memberIds' => 'required|array|min:1',
            'name' => 'nullable|string|max:100',
        ], [
            'leaderId.required' => 'Choose the device that leads.',
            'memberIds.required' => 'Choose at least one device to follow it.',
            'memberIds.min' => 'Choose at least one device to follow it.',
        ]);

        $this->run(function () use ($groups) {
            $result = $groups->create(Device::findOrFail($this->leaderId), array_map('intval', array_diff($this->memberIds, [$this->leaderId])), trim($this->name) ?: null);

            $this->reset('creating', 'leaderId', 'memberIds', 'name');

            return $this->started($result['failed'], 'Group made.');
        });
    }

    public function dissolve(SyncGroups $groups, int $groupId): void
    {
        if ($group = SyncGroup::find($groupId)) {
            $groups->dissolve($group);
            $this->say('Group ended.');
        }
    }

    public function removeMember(SyncGroups $groups, int $groupId, int $deviceId): void
    {
        if (($group = SyncGroup::find($groupId)) && ($device = Device::find($deviceId))) {
            $groups->removeMember($group, $device);
            $this->say('Removed.');
        }
    }

    /** Trims a member by `$deltaMs` milliseconds (the buttons step 250 ms). */
    public function nudge(SyncGroups $groups, int $groupId, int $deviceId, int $deltaMs): void
    {
        $group = SyncGroup::with('members')->find($groupId);
        $member = $group?->members->firstWhere('device_id', $deviceId);

        if ($member !== null) {
            $groups->setOffset($group, Device::findOrFail($deviceId), $member->offset_ms + $deltaMs);
        }
    }

    /** Starts the members again on what the leader plays now (after one stopped or lost the way). */
    public function resync(SyncGroups $groups, int $groupId): void
    {
        if ($group = SyncGroup::find($groupId)) {
            $this->run(fn () => $this->started($groups->start($group), 'Members restarted on what the leader plays.'));
        }
    }

    /** @param  array<int, array{device_id: int, message: string}>  $failed */
    private function started(array $failed, string $ok): array
    {
        return $failed === []
            ? [$ok, false]
            : ['Some devices did not start: '.collect($failed)->map(fn ($f) => (Device::find($f['device_id'])?->device_name ?? $f['device_id']).': '.$f['message'])->implode('; '), true];
    }

    /** @param  \Closure(): array{0: string, 1: bool}  $action */
    private function run(\Closure $action): void
    {
        try {
            [$message, $isError] = $action();
            $this->say($message, $isError);
        } catch (SyncGroupException|\RuntimeException $e) {
            $this->say($e->getMessage(), true);
        }
    }

    private function say(string $message, bool $isError = false): void
    {
        $this->feedback = $message;
        $this->feedbackIsError = $isError;
    }
}
