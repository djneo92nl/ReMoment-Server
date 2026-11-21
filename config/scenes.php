<?php

/*
|--------------------------------------------------------------------------
| Scenes: triggers, alarm and the device sleep timer (workstream 8.1, docs/api/scenes.md)
|--------------------------------------------------------------------------
*/

return [
    'triggers' => [
        // Triggers on one scene.
        'max' => 10,

        // Seconds after a trigger starts a scene during which its further triggers are ignored (the scene's own
        // `trigger_debounce`, within these bounds).
        'debounce_default' => 10,
        'debounce_min' => 5,
        'debounce_max' => 3600,

        // Loop protection: while a scene runs, and this many seconds after it ended, its triggers are ignored, so
        // the device events its own steps cause (it switches a source, the device reports playing) do not start it again.
        'loop_guard_seconds' => 15,

        // MQTT topic filters that may not be triggers because the server publishes there itself (a scene's steps
        // change these topics, so a trigger on them could start the scene over and over). A trigger filter is
        // refused when any topic could match both it and one of these.
        'blocked_topics' => ['remoment/player/#'],

        // How often the subscriber (`scenes:listen`) looks for changed trigger filters, in seconds.
        'refresh_seconds' => 5,
    ],

    'alarm' => [
        'max_minutes' => 60,

        // Seconds between two volume steps of the ramp (the step count is capped at `max_steps`).
        'step_seconds' => 30,
        'max_steps' => 60,

        // The ramp ends when the device volume differs this much from the level it set last (someone took over).
        'takeover_margin' => 5,
    ],

    'sleep' => [
        'max_minutes' => 480,
        'max_fade_seconds' => 600,
        'default_fade_seconds' => 60,
        'fade_step_seconds' => 10,
        'max_fade_steps' => 12,
    ],

    // A queued timer job may arrive this many seconds before its event is due and still count as due.
    'timer_tolerance_seconds' => 2,
];
