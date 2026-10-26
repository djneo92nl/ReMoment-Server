<?php

namespace App\Domain\Device;

/**
 * Splits a TV's visible sources for its page: the physical inputs (HDMI, AV, the tuner) and the apps. Everything else
 * (streaming services, radio) stays out of both grids, as docs/ux/sources.md asks; the full picker still lists it.
 */
final class TvSources
{
    private const INPUTS = ['HDMI', 'TV', 'AV IN', 'AV'];

    private const APPS = ['WEBMEDIA', 'YOUTUBE', 'HOMEMEDIA', 'APP'];

    /**
     * @param  iterable<array{type: ?string, hidden?: bool}>  $sources  rows with a `type`, in the server's order
     * @return array{inputs: array, apps: array}
     */
    public static function group(iterable $sources): array
    {
        $groups = ['inputs' => [], 'apps' => []];

        foreach ($sources as $source) {
            if ($source['hidden'] ?? false) {
                continue;
            }

            $type = strtoupper((string) ($source['type'] ?? ''));

            if (in_array($type, self::INPUTS, true)) {
                $groups['inputs'][] = $source;
            } elseif (in_array($type, self::APPS, true)) {
                $groups['apps'][] = $source;
            }
        }

        return $groups;
    }
}
