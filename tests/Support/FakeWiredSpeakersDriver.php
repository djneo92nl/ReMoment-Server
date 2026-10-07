<?php

namespace Tests\Support;

use App\Domain\Device\Settings\WiredSpeaker;
use App\Domain\Device\Settings\WiredSpeakersState;
use App\Integrations\Contracts\MusicPlayerDriverInterface;
use App\Integrations\Contracts\WiredSpeakersInterface;
use App\Models\Device;

/** Bound as a device's `device_driver` in wired speaker tests. */
class FakeWiredSpeakersDriver implements MusicPlayerDriverInterface, WiredSpeakersInterface
{
    /** @var array<int, array> */
    public static array $calls = [];

    public static ?\Exception $throw = null;

    /** @var array<string, array{type: string, sound: string}> */
    public static array $outputs = [];

    public const TYPES = ['None', 'Beolab 1', 'Beolab 3', 'Other', 'Line'];

    public function __construct(public Device $device) {}

    public static function reset(): void
    {
        self::$calls = [];
        self::$throw = null;
        self::$outputs = ['pl_1' => ['type' => 'Other', 'sound' => 'none'], 'pl_2' => ['type' => 'Other', 'sound' => 'none']];
    }

    public function getWiredSpeakers(): WiredSpeakersState
    {
        $this->failIfAsked();

        $speakers = [];
        foreach (self::$outputs as $id => $output) {
            $speakers[] = new WiredSpeaker($id, $id === 'pl_1' ? 'left' : 'right', true, $output['type'], self::TYPES, $output['sound'], ['none', 'localizationNoise']);
        }

        return new WiredSpeakersState($speakers);
    }

    public function setWiredSpeaker(string $id, ?string $type = null, ?string $sound = null): void
    {
        $this->failIfAsked();
        if (!isset(self::$outputs[$id])) {
            throw new \InvalidArgumentException("This device has no wired speaker output '{$id}'.");
        }
        if ($type !== null && !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown speaker type '{$type}'.");
        }

        self::$calls[] = [$id, $type, $sound];
        self::$outputs[$id]['type'] = $type ?? self::$outputs[$id]['type'];
        self::$outputs[$id]['sound'] = $sound ?? self::$outputs[$id]['sound'];
    }

    private function failIfAsked(): void
    {
        if (self::$throw) {
            throw self::$throw;
        }
    }
}
