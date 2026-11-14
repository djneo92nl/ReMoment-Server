<?php

/*
|--------------------------------------------------------------------------
| Scene-capable operations
|--------------------------------------------------------------------------
|
| The operations of docs/api/openapi.yaml that a scene step may call (docs/architecture/action-layer.md,
| "What a scene step is"). Derived: every guest-level operation that changes something (not GET, no `security`
| in the contract) is available, except the ids under `exclude`. An operation behind `admin.token` is never
| available, because a scene also runs for guests, from the scheduler or from MQTT.
|
|   exclude      operationIds that are guest-level but make no sense in a scene (sign-in, client/player registration
|                and heartbeats) or are too destructive to run unattended
|   operations   per-operation overrides, keyed by operationId; the rest get their label from the contract's summary
|                and a `device` path parameter as the device input
|
|   label        what the scene editor shows
|   device_param the input (a path parameter or a body property of the operation) that is the device the step acts
|                on, filled from the step's `device_id`; null when the operation is not about one device
|   enabled      false keeps the entry listed but refuses it in steps and leaves it out of the HA package
|
| The same list feeds the Home Assistant `rest_command` package (`php artisan ha:package`).
|
| Not here yet: multiroom presets (web only, no REST operation) and scene activation (scenes are not built).
*/

return [
    'exclude' => [
        'adminLogin', 'registerClient', 'registerPlayer', 'putPlayerState', 'clientHeartbeat', 'removePhysical',
    ],

    'operations' => [
        // Playback controls
        'transport' => ['label' => 'Play, pause, stop, next or previous', 'device_param' => 'device', 'enabled' => true],
        'setVolume' => ['label' => 'Set the volume', 'device_param' => 'device', 'enabled' => true],
        'setMute' => ['label' => 'Mute or unmute', 'device_param' => 'device', 'enabled' => true],
        'setShuffle' => ['label' => 'Shuffle on or off', 'device_param' => 'device', 'enabled' => true],
        'setRepeat' => ['label' => 'Set repeat', 'device_param' => 'device', 'enabled' => true],
        'setPower' => ['label' => 'Switch on or to standby', 'device_param' => 'device', 'enabled' => true],
        'activateSource' => ['label' => 'Activate a source', 'device_param' => 'device', 'enabled' => true],
        'movePlayback' => ['label' => 'Move playback to another device', 'device_param' => 'device', 'enabled' => true],
        'announce' => ['label' => 'Play an announcement clip', 'device_param' => null, 'enabled' => true],

        // Queue
        'addToQueue' => ['label' => 'Add to the queue', 'device_param' => 'device', 'enabled' => true],
        'clearQueue' => ['label' => 'Clear the queue', 'device_param' => 'device', 'enabled' => true],

        // Multiroom and sync groups
        'multiroomJoin' => ['label' => 'Join a multiroom host', 'device_param' => 'device', 'enabled' => true],
        'multiroomLeave' => ['label' => 'Leave multiroom', 'device_param' => 'device', 'enabled' => true],
        'createSyncGroup' => ['label' => 'Create a sync group', 'device_param' => 'leader_device_id', 'enabled' => true],
        'deleteSyncGroup' => ['label' => 'Delete a sync group', 'device_param' => null, 'enabled' => true],
        'addSyncGroupMember' => ['label' => 'Add a device to a sync group', 'device_param' => 'device_id', 'enabled' => true],
        'removeSyncGroupMember' => ['label' => 'Remove a device from a sync group', 'device_param' => 'device', 'enabled' => true],

        // TV
        'pressRemoteKey' => ['label' => 'Press a remote key', 'device_param' => 'device', 'enabled' => true],
        'sendDigits' => ['label' => 'Send number keys', 'device_param' => 'device', 'enabled' => true],

        // Play something on a device
        'playRadio' => ['label' => 'Play a radio station', 'device_param' => 'device', 'enabled' => true],
        'playPlaylist' => ['label' => 'Play a playlist', 'device_param' => 'device', 'enabled' => true],
        'playAlbum' => ['label' => 'Play an album', 'device_param' => 'device', 'enabled' => true],
        'playArtist' => ['label' => 'Play an artist', 'device_param' => 'device', 'enabled' => true],
        'playTrack' => ['label' => 'Play a track', 'device_param' => 'device', 'enabled' => true],
        'playTracks' => ['label' => 'Shuffle the whole library', 'device_param' => 'device', 'enabled' => true],
        'playListeningMode' => ['label' => 'Play a listening mode', 'device_param' => 'device', 'enabled' => true],
        'playExternal' => ['label' => 'Play from a streaming service', 'device_param' => 'device', 'enabled' => true],
    ],
];
