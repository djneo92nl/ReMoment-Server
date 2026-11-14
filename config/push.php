<?php

/*
| Background Web Push (docs/frontend/pwa.md). The VAPID key pair is not here: it is generated once by `php artisan push:vapid`
| (or on first use) and kept in the settings table, the private half encrypted.
*/
return [
    // The VAPID "sub" claim: a mailto: or https: contact the push service may use. No personal address is ever filled in.
    'subject' => env('PUSH_VAPID_SUBJECT', 'mailto:remoment@localhost'),

    // A guest may post a subscription, and the server then calls its endpoint, so only the browsers' push services are accepted:
    // the host must equal one of these or end in ".{entry}". Add one with PUSH_ALLOWED_HOSTS (comma separated).
    'allowed_hosts' => array_values(array_filter(array_merge(
        ['googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'],
        array_map('trim', explode(',', (string) env('PUSH_ALLOWED_HOSTS', '')))
    ))),

    // Stored subscriptions are capped so an open endpoint cannot fill the table.
    'max_subscriptions' => (int) env('PUSH_MAX_SUBSCRIPTIONS', 100),

    // Seconds the push service keeps an undelivered message (a phone that is off).
    'ttl' => 3600,
];
