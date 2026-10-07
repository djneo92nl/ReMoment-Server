<?php

namespace App\Support;

use App\Models\Device;
use Illuminate\Support\Facades\Cookie;

/**
 * The player pinned in the sticky bar of this browser: the default target of
 * every library play button. Kept in a cookie (there are no accounts), so
 * Blade and controllers can read it server-side. Registered scoped, so the
 * device is looked up once per request.
 */
class SelectedDevice
{
    public const COOKIE = 'remoment_player';

    private bool $resolved = false;

    private ?Device $device = null;

    public function device(): ?Device
    {
        if (!$this->resolved) {
            $id = (int) request()->cookie(self::COOKIE);
            $this->device = $id > 0 ? Device::find($id) : null;
            $this->resolved = true;
        }

        return $this->device;
    }

    public function set(Device $device): void
    {
        Cookie::queue(Cookie::forever(self::COOKIE, (string) $device->id));
        $this->device = $device;
        $this->resolved = true;
    }

    public function clear(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
        $this->device = null;
        $this->resolved = true;
    }
}
