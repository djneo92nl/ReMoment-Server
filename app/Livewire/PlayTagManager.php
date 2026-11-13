<?php

namespace App\Livewire;

use App\Domain\Profile\Profile;
use App\Domain\Tags\PlayTags;
use App\Models\Device;
use App\Models\PlayTag;
use App\Support\QrCode;
use Livewire\Component;

/** Settings › Tags: assign an NFC tag or a new QR code to something to play (docs/api/tags.md). Admin only. */
class PlayTagManager extends Component
{
    public string $uid = '';

    public string $label = '';

    public string $targetType = 'album';

    public string $search = '';

    public ?int $targetId = null;

    public string $targetName = '';

    public ?int $deviceId = null;

    public bool $shuffle = false;

    /** The tag whose QR code is shown. */
    public ?int $showing = null;

    public function updatedTargetType(): void
    {
        $this->reset('search', 'targetId', 'targetName');
    }

    public function useLastScanned(): void
    {
        $this->uid = PlayTags::lastScanned()['uid'] ?? $this->uid;
    }

    public function choose(int $id): void
    {
        $target = (PlayTags::TYPES[$this->targetType] ?? null)::find($id);
        if ($target) {
            $this->targetId = $id;
            $this->targetName = (string) PlayTags::targetName($this->targetType, $target);
            $this->search = '';
        }
    }

    public function save(): void
    {
        abort_unless(auth()->check(), 403);

        $this->validate([
            'uid' => ['nullable', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:255'],
            'targetType' => ['required', 'in:'.implode(',', array_keys($this->types()))],
            'targetId' => ['required', 'integer'],
            'deviceId' => ['nullable', 'integer', 'exists:devices,id'],
        ], ['targetId.required' => 'Choose what the tag plays.']);

        $uid = PlayTags::normalise($this->uid) ?: PlayTags::newUid();
        if (PlayTag::where('uid', $uid)->exists()) {
            $this->addError('uid', 'This tag is already in use.');

            return;
        }

        $tag = PlayTag::create([
            'uid' => $uid,
            'label' => trim($this->label) ?: null,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'device_id' => $this->deviceId,
            'shuffle' => $this->shuffle,
        ]);

        $this->reset('uid', 'label', 'search', 'targetId', 'targetName', 'shuffle');
        $this->showing = $tag->id;
    }

    public function show(?int $id): void
    {
        $this->showing = $this->showing === $id ? null : $id;
    }

    public function remove(int $id): void
    {
        abort_unless(auth()->check(), 403);

        PlayTag::whereKey($id)->delete();
    }

    /** The target types this profile has: without a library only radio stations. */
    private function types(): array
    {
        $labels = ['album' => 'Album', 'artist' => 'Artist', 'playlist' => 'Playlist', 'track' => 'Track', 'radio' => 'Radio station', 'physical' => 'Item of the collection'];

        return array_filter($labels, fn (string $type) => match ($type) {
            'radio' => Profile::allows('radio'),
            'physical' => Profile::allows('catalogue'),
            default => Profile::allows('library'),
        }, ARRAY_FILTER_USE_KEY);
    }

    private function results(): array
    {
        $term = trim($this->search);
        $class = PlayTags::TYPES[$this->targetType] ?? null;
        if (mb_strlen($term) < 2 || !$class) {
            return [];
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        $query = $this->targetType === 'physical'
            ? $class::where(fn ($q) => $q->where('title', 'like', $like)->orWhere('artist', 'like', $like))
            : $class::where('name', 'like', $like);

        return $query->limit(8)->get()
            ->map(fn ($target) => ['id' => $target->id, 'name' => PlayTags::targetName($this->targetType, $target)])->all();
    }

    public function render()
    {
        $tags = PlayTag::with('device')->orderByDesc('id')->get();
        $types = $this->types();
        if (!isset($types[$this->targetType])) {
            $this->targetType = (string) array_key_first($types);
        }

        return view('livewire.play-tag-manager', [
            'tags' => $tags->map(fn (PlayTag $tag) => PlayTags::item($tag)),
            'types' => $types,
            'results' => $this->results(),
            'devices' => Device::orderBy('device_name')->get(['id', 'device_name']),
            'lastScanned' => PlayTags::lastScanned(),
            'qr' => ($shown = $tags->firstWhere('id', $this->showing)) ? QrCode::svg(url('/t/'.$shown->uid)) : null,
        ]);
    }
}
