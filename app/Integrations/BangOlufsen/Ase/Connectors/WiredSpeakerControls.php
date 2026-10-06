<?php

namespace App\Integrations\BangOlufsen\Ase\Connectors;

use App\Domain\Device\Settings\WiredSpeaker;
use App\Domain\Device\Settings\WiredSpeakersState;

/**
 * Wired speaker setup (`SpeakerWiredSetup`): two power-link outputs, each with
 * a speaker `type` and a test `sound`. Every ASE speaker answers this
 * endpoint; only a model with external speakers should offer it (HardwareFeatures).
 *
 * The Moment lists its selectable types; the Essence answers with an empty
 * list although its own web UI offers a choice, so the Moment's list is used
 * then. Unverified for the Essence: whether it accepts those names.
 */
trait WiredSpeakerControls
{
    private const WIRED_SETUP = 'BeoZone/Zone/Sound/SpeakerWiredSetup';

    /** The speaker types a Moment reports. */
    private const WIRED_TYPES = [
        'None', 'Beolab 4000', 'Beolab 6000', 'Beolab 6002', 'Beolab 8000', 'Beolab 8002', 'Beolab 1', 'Beolab 2', 'Beolab 3',
        'Beolab 4', 'Beolab 5', 'Beolab 7-1', 'Beolab 7-2', 'Beolab 7-4', 'Beolab 7-6', 'Beolab 9', 'Beolab 10', 'Beolab 11',
        'Beolab 12-1', 'Beolab 12-2', 'Beolab 12-3', 'Beolab 14 Sub', 'Beolab 14', 'Beolab 15', 'Beolab 16', 'Beolab 17',
        'Beolab 18', 'Beolab 19', 'Beolab 20', 'Beolab Penta', 'Beovox 1', 'Beovox 2', 'Other', 'Line',
    ];

    abstract protected function deviceApiClient(): \App\Integrations\Common\HttpConnector;

    public function getWiredSpeakers(): WiredSpeakersState
    {
        $setup = $this->deviceApiClient()->getStrict(self::WIRED_SETUP)['speakerWiredSetup'] ?? throw new \RuntimeException('The device did not report its wired speakers.');

        return new WiredSpeakersState(array_map(function (array $speaker) {
            $listedTypes = $speaker['_capabilities']['value']['type'] ?? [];
            $listedSounds = $speaker['_capabilities']['value']['sound'] ?? [];

            return new WiredSpeaker(
                (string) $speaker['id'],
                (string) ($speaker['connector']['allocation'] ?? ''),
                (bool) ($speaker['sense'] ?? false),
                (string) ($speaker['type'] ?? ''),
                array_values($listedTypes ?: self::WIRED_TYPES),
                (string) ($speaker['sound'] ?? 'none'),
                array_values($listedSounds ?: ['none', 'localizationNoise']),
            );
        }, $setup['speaker'] ?? []));
    }

    public function setWiredSpeaker(string $id, ?string $type = null, ?string $sound = null): void
    {
        $speaker = $this->getWiredSpeakers()->speaker($id) ?? throw new \InvalidArgumentException("This device has no wired speaker output '{$id}'.");

        if ($type !== null && !in_array($type, $speaker->types, true)) {
            throw new \InvalidArgumentException("Unknown speaker type '{$type}'.");
        }
        if ($sound !== null && !in_array($sound, $speaker->sounds, true)) {
            throw new \InvalidArgumentException("Unknown speaker sound '{$sound}'.");
        }

        $body = array_filter(['type' => $type, 'sound' => $sound], fn ($value) => $value !== null);
        if ($body === []) {
            return;
        }

        $this->deviceApiClient()->putStrict(self::WIRED_SETUP.'/'.$id, ['speaker' => $body]);

        $applied = $this->getWiredSpeakers()->speaker($id);
        if (($type !== null && $applied?->type !== $type) || ($sound !== null && $applied?->sound !== $sound)) {
            throw new \RuntimeException('The device did not apply the speaker change.');
        }
    }
}
