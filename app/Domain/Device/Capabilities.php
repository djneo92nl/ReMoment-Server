<?php

namespace App\Domain\Device;

use App\Integrations\Contracts\LibraryPlaybackInterface;
use App\Integrations\Contracts\LikeInterface;
use App\Integrations\Contracts\MediaControlsInterface;
use App\Integrations\Contracts\MultiRoomInterface;
use App\Integrations\Contracts\PowerInterface;
use App\Integrations\Contracts\QueueInterface;
use App\Integrations\Contracts\QueueJumpInterface;
use App\Integrations\Contracts\RadioControlInterface;
use App\Integrations\Contracts\RepeatInterface;
use App\Integrations\Contracts\SeekInterface;
use App\Integrations\Contracts\ShuffleInterface;
use App\Integrations\Contracts\SourceActivationInterface;
use App\Integrations\Contracts\SourcesInterface;
use App\Integrations\Contracts\VolumeControlInterface;

/**
 * Maps driver contracts to the capability strings exposed by the REST API.
 * Resolved from the driver class name, so no driver (and no network
 * connection to the device) is instantiated.
 */
final class Capabilities
{
    private const MAP = [
        'media_controls' => MediaControlsInterface::class,
        'volume_control' => VolumeControlInterface::class,
        'radio_control' => RadioControlInterface::class,
        'source_control' => SourcesInterface::class,
        'source_activation' => SourceActivationInterface::class,
        'multi_room' => MultiRoomInterface::class,
        'library_playback' => LibraryPlaybackInterface::class,
        'seek' => SeekInterface::class,
        'queue' => QueueInterface::class,
        'queue_jump' => QueueJumpInterface::class,
        'shuffle' => ShuffleInterface::class,
        'repeat' => RepeatInterface::class,
        'like' => LikeInterface::class,
        'power' => PowerInterface::class,
    ];

    /**
     * @param  string[]  $contracts
     * @return string[]
     */
    public static function forContracts(array $contracts): array
    {
        return array_keys(array_intersect(self::MAP, $contracts));
    }

    /**
     * Unique capabilities in the canonical order.
     *
     * @param  string[]  $capabilities
     * @return string[]
     */
    public static function sort(array $capabilities): array
    {
        return array_values(array_intersect(array_keys(self::MAP), $capabilities));
    }

    /** @return string[] */
    public static function forDriver(?string $driverClass): array
    {
        if (!$driverClass) {
            return [];
        }

        return array_keys(array_filter(
            self::MAP,
            fn (string $contract) => is_a($driverClass, $contract, true)
        ));
    }
}
