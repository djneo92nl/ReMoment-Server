<?php

namespace Tests\Support;

use App\Integrations\Common\HttpConnector;
use App\Integrations\Contracts\SessionHostInterface;
use App\Models\Device;

/** An ASE host as joinSession() sees it: only its API client matters. */
class FakeAseHostDriver implements SessionHostInterface
{
    public static ?HttpConnector $api = null;

    public function __construct(public Device $device) {}

    public function addListener(string $peerId): void
    {
        self::$api->post('BeoZone/Zone/ActiveSources/primaryExperience', ['listener' => ['jid' => $peerId]]);
    }

    public function deviceApiClient(): HttpConnector
    {
        return self::$api;
    }
}
