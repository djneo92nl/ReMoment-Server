<?php

namespace App\Livewire;

use App\Domain\Profile\Profile;
use App\Models\Client;
use App\Models\Device;
use App\Models\DlnaServer;
use App\Models\Setting;
use App\Services\LastfmSessionService;
use App\Services\SpotifyTokenService;
use Livewire\Component;

class SetupWizard extends Component
{
    public function skipClients(): void
    {
        Setting::set('setup_step_clients_skipped', '1');
    }

    public function skipLibrary(): void
    {
        Setting::set('setup_step_library_skipped', '1');
    }

    public function finish(): void
    {
        Setting::set('setup_wizard_dismissed', '1');
        $this->redirect(route('devices.index'), navigate: true);
    }

    public function render()
    {
        $deviceCount = Device::count();
        $clientCount = Client::count();
        $pendingClientCount = Client::where('status', 'pending')->count();
        $dlnaServerCount = DlnaServer::count();
        $spotifyConnected = app(SpotifyTokenService::class)->isConnected();
        $lastfmConnected = app(LastfmSessionService::class)->isConnected();

        $devicesDone = $deviceCount > 0;
        $clientsDone = $clientCount > 0 || Setting::get('setup_step_clients_skipped') !== null;
        $libraryDone = $dlnaServerCount > 0 || $spotifyConnected || Setting::get('setup_step_library_skipped') !== null;

        // Control has no library, so its wizard has no library step (docs/architecture/profiles.md).
        $hasLibrary = Profile::allows('library');
        $steps = $hasLibrary ? [$devicesDone, $clientsDone, $libraryDone] : [$devicesDone, $clientsDone];
        $totalSteps = count($steps);
        $doneCount = collect($steps)->filter()->count();

        return view('livewire.setup-wizard', [
            'deviceCount' => $deviceCount,
            'clientCount' => $clientCount,
            'pendingClientCount' => $pendingClientCount,
            'dlnaServerCount' => $dlnaServerCount,
            'spotifyConnected' => $spotifyConnected,
            'lastfmConnected' => $lastfmConnected,
            'devicesDone' => $devicesDone,
            'clientsDone' => $clientsDone,
            'libraryDone' => $libraryDone,
            'doneCount' => $doneCount,
            'totalSteps' => $totalSteps,
            'hasLibrary' => $hasLibrary,
            'allDone' => $doneCount === $totalSteps,
        ]);
    }
}
