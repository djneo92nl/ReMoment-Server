<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsApi;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List all player devices with id, name, brand, state (playing|paused|standby|unreachable), capabilities, battery and multiroom role. Start here to find a device id; check `capabilities` before calling a feature.')]
#[IsReadOnly]
class ListDevices extends Tool
{
    use CallsApi;

    public function handle(Request $request): Response
    {
        return $this->api('GET', 'devices');
    }
}
