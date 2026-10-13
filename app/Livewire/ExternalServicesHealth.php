<?php

namespace App\Livewire;

use App\Domain\Health\ExternalServices;
use Livewire\Component;

/** The "External services" block of /settings/health: cached probe results and a button to run them again. */
class ExternalServicesHealth extends Component
{
    public function check(ExternalServices $services): void
    {
        $services->refresh();
    }

    public function render(ExternalServices $services)
    {
        $results = $services->all();

        return view('livewire.external-services-health', [
            'groups' => collect($results)->groupBy('group'),
            'checkedAt' => $services->checkedAt(),
            'problems' => collect($results)->whereIn('status', ['error', 'warning'])->count(),
        ]);
    }
}
