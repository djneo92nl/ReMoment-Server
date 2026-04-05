<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

trait SourceControls
{
    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getSources(): array
    {
        return $this->deviceApiClient()->get('BeoZone/Zone/Sources');
    }

    public function getActiveSources(): array
    {
        return $this->deviceApiClient()->get('BeoZone/Zone/ActiveSources');
    }

    public function setActiveSourceById(string $sourceId): void
    {
        $this->deviceApiClient()->post('BeoZone/Zone/ActiveSources', [
            'primaryExperience' => [
                'source' => ['id' => $sourceId],
            ],
        ]);
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
