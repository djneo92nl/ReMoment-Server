<?php

namespace App\Livewire\Developer;

use App\Services\MqttService;
use Livewire\Component;

/** /settings/developer: publish any MQTT message; the live feed of remoment/# is browser JS (Alpine) next to it. */
class MqttConsole extends Component
{
    public string $topic = 'remoment/test';

    public string $payload = '';

    public int $qos = 0;

    public bool $retain = false;

    public ?string $message = null;

    public function publish(MqttService $mqtt): void
    {
        $topic = trim($this->topic);

        if ($topic === '' || str_contains($topic, '#') || str_contains($topic, '+')) {
            $this->message = 'Give a plain topic (no # or + wildcards).';

            return;
        }

        $mqtt->publish($topic, $this->payload, max(0, min(1, $this->qos)), $this->retain);
        $this->message = ($this->payload === '' && $this->retain ? 'Cleared retained message on ' : 'Published to ').$topic.'.';
    }

    public function render()
    {
        return view('livewire.developer.mqtt-console');
    }
}
