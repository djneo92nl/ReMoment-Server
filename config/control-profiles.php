<?php

/*
 * Source control profiles: the controls a source needs beyond play/pause
 * (change disc, tune presets, …), described once and reused for every device
 * that plays such a source: a B&O converter's own CD, the same CD borrowed by
 * an ASE/Mozart player, or a CD changer on an AUX input behind an ESP.
 *
 * `match` are the words (see SourceLogo::firstMatch) looked for in the active
 * source's type, name, connector and category, first profile wins. `commands`
 * are abstract: a ControlTransport turns an id into the real call for a device.
 * `type` is button (default) or select; `group` lets the UI cluster buttons.
 * `source_types` (optional) names source types matched exactly, for sources
 * whose words are too common to match on ("RADIO" would also hit "NET RADIO").
 *
 * A transport lists the commands it can really send (ControlTransport::commands),
 * so a profile can hold more commands than any one transport delivers.
 */
return [
    'profiles' => [
        'cd' => [
            'label' => 'CD',
            'match' => ['cd', 'cdchanger'],
            'commands' => [
                ['id' => 'prev_disc', 'label' => 'Previous disc', 'icon' => 'fa-backward-fast', 'group' => 'disc'],
                ['id' => 'next_disc', 'label' => 'Next disc', 'icon' => 'fa-forward-fast', 'group' => 'disc'],
                ['id' => 'prev_track', 'label' => 'Previous track', 'icon' => 'fa-backward-step', 'group' => 'track'],
                ['id' => 'next_track', 'label' => 'Next track', 'icon' => 'fa-forward-step', 'group' => 'track'],
            ],
        ],
        'tape' => [
            'label' => 'Tape',
            'match' => ['tape', 'tape2'],
            'commands' => [
                ['id' => 'rewind', 'label' => 'Rewind', 'icon' => 'fa-backward', 'group' => 'transport'],
                ['id' => 'fast_forward', 'label' => 'Fast forward', 'icon' => 'fa-forward', 'group' => 'transport'],
                ['id' => 'reverse_side', 'label' => 'Reverse side', 'icon' => 'fa-rotate', 'group' => 'side'],
            ],
        ],
        'fm' => [
            'label' => 'FM',
            'match' => ['fm'],
            'source_types' => ['RADIO'],
            'commands' => [
                ['id' => 'prev_preset', 'label' => 'Previous preset', 'icon' => 'fa-backward-step', 'group' => 'preset'],
                ['id' => 'next_preset', 'label' => 'Next preset', 'icon' => 'fa-forward-step', 'group' => 'preset'],
                ['id' => 'tune_down', 'label' => 'Tune down', 'icon' => 'fa-minus', 'group' => 'tune'],
                ['id' => 'tune_up', 'label' => 'Tune up', 'icon' => 'fa-plus', 'group' => 'tune'],
                ...array_map(
                    fn (int $preset) => ['id' => "preset_{$preset}", 'label' => (string) $preset, 'group' => 'preset_select'],
                    range(1, 9),
                ),
            ],
        ],
    ],

    /*
     * ControlTransport classes, first that supports a device wins. The ASE
     * one-way command transport and the ESP transport don't exist yet.
     */
    'transports' => [
        App\Integrations\BangOlufsen\Ase\Control\ConverterDigitTransport::class,
    ],
];
