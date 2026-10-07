<?php

namespace Tests\Support;

use App\Integrations\Common\HttpConnector;
use App\Models\Device;

/** An ASE host as joinSession() sees it: only its API client matters. */
class FakeAseHostDriver
{
    public static ?HttpConnector $api = null;

    public function __construct(public Device $device) {}

    public function deviceApiClient(): HttpConnector
    {
        return self::$api;
    }
}
