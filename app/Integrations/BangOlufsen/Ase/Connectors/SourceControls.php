<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\AvailableSource;

trait SourceControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getSources(): array
    {
        $raw = $this->deviceApiClient()->get('BeoZone/Zone/Sources');

        return collect($raw['sources'] ?? [])
            ->map(fn ($pair) => new AvailableSource(
                sourceId: $pair[1]['id'],
                friendlyName: $pair[1]['friendlyName'],
                sourceType: $pair[1]['sourceType']['type'] ?? 'UNKNOWN',
                category: $pair[1]['category'] ?? 'MUSIC',
                inUse: (bool) ($pair[1]['inUse'] ?? true),
                borrowed: (bool) ($pair[1]['borrowed'] ?? false),
                providerJid: $pair[1]['product']['jid'] ?? null,
                providerName: $pair[1]['product']['friendlyName'] ?? null,
            ))
            ->values()
            ->all();
    }

    public function getActiveSources(): array
    {
        return $this->deviceApiClient()->get('BeoZone/Zone/ActiveSources');
    }

    public function activateSource(string $sourceId): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/ActiveSources', [
            'primaryExperience' => [
                'source' => ['id' => $sourceId],
            ],
        ]);
    }

    public function setActiveSourceById(string $sourceId): void
    {
        $this->activateSource($sourceId);
    }

    public function setActiveSourceByType(string $type, ?string $productName = null): void
    {
        $body = ['sourceType' => ['type' => $type]];
        if ($productName !== null) {
            $body['product'] = ['friendlyName' => $productName];
        }
        $this->deviceApiClient()->post('BeoZone/Zone/ActiveSourceType', $body);
    }

    public function getProducts(): array
    {
        return $this->deviceApiClient()->get('BeoZone/System/Products');
    }
}
