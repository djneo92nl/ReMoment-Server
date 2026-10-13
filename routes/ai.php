<?php

use App\Mcp\Servers\ReMomentServer;
use Laravel\Mcp\Facades\Mcp;

// Open like the REST API; the admin tools check the bearer token themselves (docs/api/mcp.md).
Mcp::web('/mcp', ReMomentServer::class)->middleware('throttle:120,1');
