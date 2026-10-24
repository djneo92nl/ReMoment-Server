<?php

namespace App\Integrations\Radio\Nts;

use App\Models\RadioStation;

/** Creates (or refreshes the stream of) NTS' two live channels and 16 mixtapes as radio stations; stations are found by their `nts_id`. */
class NtsImporter
{
    public function __construct(private readonly NtsClient $client) {}

    /** @return array{created: int, updated: int} */
    public function import(): array
    {
        $stats = ['created' => 0, 'updated' => 0];

        foreach (NtsClient::LIVE_STREAMS as $channel => $url) {
            $this->sync("live-{$channel}", "NTS {$channel}", $url, NtsClient::LOGO, ['nts_channel' => (string) $channel], $stats);
        }

        foreach ($this->client->mixtapes() as $mixtape) {
            $this->sync("mixtape-{$mixtape['alias']}", "NTS {$mixtape['title']}", $mixtape['url'], $mixtape['image'], [], $stats);
        }

        return $stats;
    }

    /** @param array<string, string> $extra @param array{created: int, updated: int} $stats */
    private function sync(string $id, string $name, string $url, ?string $image, array $extra, array &$stats): void
    {
        $station = RadioStation::whereHas('meta', fn ($q) => $q->where('key', 'nts_id')->where('value', $id))->first();
        $stats[$station ? 'updated' : 'created']++;

        $station ??= RadioStation::create(['name' => $name, 'genre' => 'NTS', 'image_url' => $image]);

        if (blank($station->image_url) && $image !== null) {
            $station->update(['image_url' => $image]);
        }

        $station->setMeta('nts_id', $id);
        $station->setMeta('url', $url);

        foreach ($extra as $key => $value) {
            $station->setMeta($key, $value);
        }
    }
}
