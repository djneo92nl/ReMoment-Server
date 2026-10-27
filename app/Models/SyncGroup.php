<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A leader device and the devices that follow it (docs/architecture/sync-groups.md). */
class SyncGroup extends Model
{
    protected $fillable = ['name', 'leader_device_id'];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'leader_device_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SyncGroupMember::class)->orderBy('id');
    }

    /** The SyncGroup shape of the API (docs/api/sync-groups.md). */
    public function apiItem(): array
    {
        $this->loadMissing('leader', 'members.device');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'leader' => ['id' => $this->leader->id, 'name' => $this->leader->device_name],
            'members' => $this->members->map(fn (SyncGroupMember $m) => [
                'id' => $m->device->id,
                'name' => $m->device->device_name,
                'offset_ms' => $m->offset_ms,
            ])->values()->all(),
        ];
    }
}
