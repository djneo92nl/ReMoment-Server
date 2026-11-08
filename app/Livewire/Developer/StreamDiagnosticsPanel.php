<?php

namespace App\Livewire\Developer;

use App\Domain\Developer\StreamDiagnostics;
use Livewire\Component;

/** /settings/developer: the `/stream` request log and fault injection (see StreamDiagnostics). */
class StreamDiagnosticsPanel extends Component
{
    public string $mode = 'delay';

    public int $percent = 100;

    public int $delayMs = 2000;

    public function toggleLog(): void
    {
        StreamDiagnostics::setLogging(!StreamDiagnostics::logging());
    }

    public function clearLog(): void
    {
        StreamDiagnostics::clear();
    }

    public function injectFault(): void
    {
        StreamDiagnostics::setFault($this->mode, $this->percent, $this->delayMs);
    }

    public function stopFault(): void
    {
        StreamDiagnostics::setFault(null);
    }

    public function render()
    {
        return view('livewire.developer.stream-diagnostics-panel', [
            'logging' => StreamDiagnostics::logging(),
            'entries' => StreamDiagnostics::entries(),
            'fault' => StreamDiagnostics::fault(),
            'modes' => StreamDiagnostics::FAULT_MODES,
            'logMinutes' => StreamDiagnostics::LOG_MINUTES,
            'faultMinutes' => StreamDiagnostics::FAULT_MINUTES,
        ]);
    }
}
