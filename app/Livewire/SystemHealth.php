<?php

namespace App\Livewire;

use App\Domain\Device\ListenerStatus;
use App\Domain\Health\HealthSummary;
use App\Models\Device;
use Livewire\Component;

class SystemHealth extends Component
{
    public function render()
    {
        // The data is App\Domain\Health\HealthSummary's, the same as GET /api/admin/health (docs/api/admin-health.md).
        return view('livewire.system-health', app(HealthSummary::class)->report());
    }

    /** Admin only, like the API's POST /admin/alerts/{id}/ack. */
    public function acknowledge(int $alertId): void
    {
        abort_unless(auth()->check(), 403);

        if ($alert = \App\Models\Alert::find($alertId)) {
            \App\Domain\Alerts\Alerts::acknowledge($alert);
        }
    }

    /** Admin only: asks `device:listen` to stop this device's listener and start it again at once (skips any backoff). */
    public function restartListener(int $deviceId): void
    {
        abort_unless(auth()->check(), 403);

        if (Device::whereKey($deviceId)->exists()) {
            ListenerStatus::requestRestart($deviceId);
        }
    }
}
