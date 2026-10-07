<?php

namespace App\Support;

/** Builds the `{id}` URL template that `<x-play-button>` / `<x-device-picker>` fill with a device id, from a named route. */
class DeviceAction
{
    private const PLACEHOLDER = '__device__';

    /** @param  array<string, mixed>  $params  route parameters; extra keys become the query string */
    public static function template(string $route, array $params = []): string
    {
        return str_replace(self::PLACEHOLDER, '{id}', route($route, $params + ['device' => self::PLACEHOLDER]));
    }
}
