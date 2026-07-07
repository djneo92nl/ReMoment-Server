<?php

namespace Djneo92nl\BeoMozart\WebSocket;

use Djneo92nl\BeoMozart\Enums\RenderingStateValue;

/**
 * The Mozart OpenAPI spec types every WebSocketEvent*'s `eventType` field as
 * a plain string with no documented enum values — there's no discriminated
 * union tying a literal tag to its payload shape. This classifier is the
 * fallback for when a literal `eventType` guess doesn't match: it sniffs
 * `eventData`'s own keys against the known REST schema shapes instead.
 */
class EventClassifier
{
    public const PLAYBACK_METADATA = 'playback_metadata';

    public const PLAYBACK_PROGRESS = 'playback_progress';

    public const PLAYBACK_STATE = 'playback_state';

    public const VOLUME = 'volume';

    public const SOURCE_CHANGE = 'source_change';

    public const UNKNOWN = 'unknown';

    public static function classify(string $eventType, mixed $eventData): string
    {
        if (!is_array($eventData)) {
            return self::UNKNOWN;
        }

        // PlaybackProgress { id, progress, totalDuration }
        if (array_key_exists('progress', $eventData) && array_key_exists('totalDuration', $eventData)) {
            return self::PLAYBACK_PROGRESS;
        }

        // VolumeState { default, level, maximum, muted }
        if (array_key_exists('muted', $eventData) && array_key_exists('maximum', $eventData)) {
            return self::VOLUME;
        }

        // PlaybackContentMetadata is a large flat object — recognize it by
        // any of its more distinctive fields.
        if (array_key_exists('albumName', $eventData) || array_key_exists('artistName', $eventData) || array_key_exists('trackCount', $eventData)) {
            return self::PLAYBACK_METADATA;
        }

        // RenderingState { value: idle|buffering|started|paused|stopped|ended|error|unknown }.
        // Checked against the real enum values, not just shape, since
        // PowerStateEnum/SourceTypeEnum share the identical single-`value`
        // wrapper shape with different enum members.
        if (
            array_key_exists('value', $eventData)
            && count($eventData) === 1
            && is_string($eventData['value'])
            && RenderingStateValue::tryFrom($eventData['value']) !== null
        ) {
            return self::PLAYBACK_STATE;
        }

        // Source { id, isEnabled, isMultiroomAvailable, isPlayable, isSeekable, name, type }
        if (array_key_exists('isMultiroomAvailable', $eventData) || array_key_exists('isSeekable', $eventData)) {
            return self::SOURCE_CHANGE;
        }

        return self::UNKNOWN;
    }
}
