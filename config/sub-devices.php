<?php

/*
| Sub devices (workstream 2a, docs/architecture/sub-devices.md): timings of the follow rule.
*/

return [

    // Apps start and stop several times in a row: the follow rule waits this long after a cast session starts, then checks
    // that it is still playing before it touches the host.
    'follow_debounce_seconds' => (int) env('SUB_DEVICE_FOLLOW_DEBOUNCE', 5),

    // The default-input-on-wake (SwitchToDefaultSource) leaves a host alone this long after the follow rule switched it, or
    // it would take the screen back from the input the rule just selected.
    'follow_switch_guard_seconds' => 60,

];
