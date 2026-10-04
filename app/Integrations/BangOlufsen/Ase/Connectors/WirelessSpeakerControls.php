<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\Settings\WirelessSpeaker;
use App\Domain\Device\Settings\WirelessSpeakersState;

/**
 * Wireless (WiSA) speaker setup. Only a model with the radio should offer it
 * (HardwareFeatures); every ASE speaker answers these endpoints.
 *
 * Unverified on hardware: the scan states and the shape of a speaker entry
 * (the Moment was unreachable when this was written). Entries are read
 * defensively and a scan state this does not know counts as "scanning".
 */
trait WirelessSpeakerControls
{
    private const WIRELESS_SETUP = 'BeoZone/Zone/Sound/SpeakerWirelessSetup';

    private const SCAN_IDLE = ['notinitiated', 'stopped', 'stop', 'finished', 'done', 'completed', 'idle', 'none', ''];

    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getWirelessSpeakers(): WirelessSpeakersState
    {
        $setup = $this->deviceApiClient()->getStrict(self::WIRELESS_SETUP)['speakerWirelessSetup'] ?? throw new \RuntimeException('The device did not report its wireless speakers.');
        $scan = (string) ($setup['scan'] ?? '');

        return new WirelessSpeakersState(
            $scan,
            !in_array(strtolower($scan), self::SCAN_IDLE, true),
            array_map(function (array $speaker) {
                $id = (string) ($speaker['id'] ?? $speaker['address'] ?? $speaker['name'] ?? '');

                return new WirelessSpeaker(
                    $id,
                    (string) ($speaker['friendlyName'] ?? $speaker['name'] ?? $id),
                    isset($speaker['state']) ? (string) $speaker['state'] : (isset($speaker['status']) ? (string) $speaker['status'] : null),
                );
            }, $setup['speaker'] ?? []),
        );
    }

    public function startWirelessScan(): void
    {
        $this->deviceApiClient()->putStrict(self::WIRELESS_SETUP, ['speakerWirelessSetup' => ['scan' => 'start']]);
    }

    public function stopWirelessScan(): void
    {
        $this->deviceApiClient()->putStrict(self::WIRELESS_SETUP, ['speakerWirelessSetup' => ['scan' => 'stop']]);
    }
}
