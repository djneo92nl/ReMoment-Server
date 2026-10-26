<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\AvailableSource;
use App\Domain\Device\SourceInput;

trait SourceControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getSources(): array
    {
        $raw = $this->deviceApiClient()->get('BeoZone/Zone/Sources');
        $activeId = $this->activeSourceId();

        return collect($raw['sources'] ?? [])
            ->filter(fn ($pair) => isset($pair[1]['friendlyName']))
            ->map(fn ($pair) => new AvailableSource(
                sourceId: $pair[1]['id'] ?? $pair[0],
                friendlyName: $pair[1]['friendlyName'],
                sourceType: $pair[1]['sourceType']['type'] ?? 'UNKNOWN',
                category: $pair[1]['category'] ?? 'MUSIC',
                inUse: (bool) ($pair[1]['inUse'] ?? true),
                borrowed: (bool) ($pair[1]['borrowed'] ?? false),
                providerJid: $pair[1]['product']['jid'] ?? null,
                providerName: $pair[1]['product']['friendlyName'] ?? null,
                input: SourceInput::label($pair[1]['sourceType']['type'] ?? null, $pair[1]['sourceType']['connector'] ?? null),
                active: $activeId === null ? null : $activeId === ($pair[1]['id'] ?? $pair[0]),
                signal: isset($pair[1]['signalSensed']) ? (bool) $pair[1]['signalSensed'] : null,
            ))
            ->values()
            ->all();
    }

    /**
     * Id of the selected source, or null when the device does not say. `inUse`
     * on the sources list is not this (it is true for nearly every source).
     * Unverified on hardware: `activeSources.primary` is the id (an empty
     * string when idle), with `primaryExperience.source.id` as a second place.
     */
    private function activeSourceId(): ?string
    {
        try {
            $data = $this->getActiveSources();
        } catch (\Throwable) {
            return null;
        }

        $id = $data['activeSources']['primary'] ?? $data['primaryExperience']['source']['id'] ?? '';

        return is_string($id) && $id !== '' ? $id : null;
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
