<?php

namespace App\Models;

use App\Domain\SubDevices\DeviceLinks;
use App\Domain\SubDevices\LinkIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sub device connected to a host (docs/architecture/sub-devices.md): `device_id` is the sub device, `host_device_id`
 * the device that selects it. One level deep: a device is a sub device or a host, never both (checked on save).
 *
 * @property int $id
 * @property int $device_id
 * @property int $host_device_id
 * @property string $kind
 * @property string $via
 * @property array|null $meta
 */
class DeviceLink extends Model
{
    public const KIND_BORROW = 'borrow';

    public const KIND_INPUT = 'input';

    public const KIND_SOURCE = 'source';

    public const KINDS = [self::KIND_BORROW, self::KIND_INPUT, self::KIND_SOURCE];

    protected $fillable = ['device_id', 'host_device_id', 'kind', 'via', 'meta'];

    protected $casts = ['meta' => 'array'];

    protected static function booted(): void
    {
        static::saving(fn (self $link) => DeviceLinks::validate($link));

        // The cached index of hosts is stale once a link changes.
        static::saved(fn () => LinkIndex::forget());
        static::deleted(fn () => LinkIndex::forget());
    }

    /** The sub device. */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'host_device_id');
    }
}
