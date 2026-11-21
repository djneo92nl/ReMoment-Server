<?php

namespace App\Http\Controllers\Api;

use App\Domain\Scenes\HaPackage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Home Assistant `rest_command` package as a download (docs/api/admin-ha.md): the same YAML `php artisan ha:package`
 * writes, from the same App\Domain\Scenes\HaPackage. Admin token only, like the button on /settings/mqtt.
 */
class AdminHaController extends Controller
{
    public function package(Request $request, HaPackage $package): Response
    {
        $data = $request->validate(['base_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:http,https']]);

        return response($package->yaml($data['base_url'] ?? null ?: HaPackage::defaultBaseUrl()), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="remoment.yaml"',
        ]);
    }
}
