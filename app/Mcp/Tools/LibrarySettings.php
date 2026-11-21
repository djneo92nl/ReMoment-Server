<?php

namespace App\Mcp\Tools;

use App\Domain\Library\LeadingSource;
use App\Mcp\Tools\Concerns\CallsApi;
use App\Mcp\Tools\Concerns\RequiresAdmin;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('ADMIN (needs the admin token; standard or full profile). Library settings of Settings › Storage › Library (docs/api/admin-library.md). Call with no arguments to read them; give any of leading_source (auto|files|dlna|all), add_played_tracks, playback_loudness, nts_enabled (NTS is unofficial data: only on the owner\'s say), history_keep_months (0 = keep forever) to change just those. Goes through the admin REST API.')]
class LibrarySettings extends Tool
{
    use CallsApi;
    use RequiresAdmin;

    public function schema(JsonSchema $schema): array
    {
        return [
            'leading_source' => $schema->string()->enum(LeadingSource::MODES)->description('Which library the pages and API show by default'),
            'add_played_tracks' => $schema->boolean()->description('Add a played track that is not in the library to it'),
            'playback_loudness' => $schema->boolean()->description('Level the loudness of converted streams'),
            'nts_enabled' => $schema->boolean()->description('NTS Radio (unofficial website data)'),
            'history_keep_months' => $schema->integer()->description('Months of play history to keep; 0 keeps everything')->min(0),
        ];
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->adminDenied()) {
            return $denied;
        }

        $data = $request->validate([
            'leading_source' => 'nullable|in:'.implode(',', LeadingSource::MODES), 'add_played_tracks' => 'nullable|boolean', 'playback_loudness' => 'nullable|boolean',
            'nts_enabled' => 'nullable|boolean', 'history_keep_months' => 'nullable|integer|min:0',
        ]);
        $changes = $this->filled($data);

        if ($changes === []) {
            return $this->api('GET', 'admin/library/settings');
        }
        if (array_key_exists('history_keep_months', $changes) && (int) $changes['history_keep_months'] === 0) {
            $changes['history_keep_months'] = null;
        }

        return $this->api('PUT', 'admin/library/settings', $changes);
    }
}
