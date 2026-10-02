import './bootstrap';

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
window.Alpine = Alpine;
Livewire.start();

console.log(
    '%c♪ ReMoment%c\nLooking under the hood? The REST API lives at /api, no auth needed.\nTry ↑↑↓↓←→←→BA on /receiver.',
    'font: 600 20px monospace; color: #e879f9',
    'font: 12px monospace; color: #9ca3af',
);
