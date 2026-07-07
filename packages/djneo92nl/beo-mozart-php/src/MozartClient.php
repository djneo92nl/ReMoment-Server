<?php

namespace Djneo92nl\BeoMozart;

use Djneo92nl\BeoMozart\Api\BeolinkApi;
use Djneo92nl\BeoMozart\Api\PlaybackApi;
use Djneo92nl\BeoMozart\Api\PowerApi;
use Djneo92nl\BeoMozart\Api\SourcesApi;
use Djneo92nl\BeoMozart\Api\VolumeApi;
use Djneo92nl\BeoMozart\Http\CurlHttpClient;
use Djneo92nl\BeoMozart\Http\HttpTransportInterface;
use Djneo92nl\BeoMozart\WebSocket\NotificationClient;

class MozartClient
{
    protected HttpTransportInterface $transport;

    protected NotificationClient $notifications;

    public function __construct(
        string $host,
        int $restPort = 8080,
        int $wsPort = 9000,
        ?HttpTransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? new CurlHttpClient($host, $restPort);
        $this->notifications = new NotificationClient($host, $wsPort);
    }

    public function playback(): PlaybackApi
    {
        return new PlaybackApi($this->transport);
    }

    public function sources(): SourcesApi
    {
        return new SourcesApi($this->transport);
    }

    public function volume(): VolumeApi
    {
        return new VolumeApi($this->transport);
    }

    public function power(): PowerApi
    {
        return new PowerApi($this->transport);
    }

    public function beolink(): BeolinkApi
    {
        return new BeolinkApi($this->transport);
    }

    public function notifications(): NotificationClient
    {
        return $this->notifications;
    }
}
