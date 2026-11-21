<?php

namespace App\Domain\Tools;

use App\Domain\Profile\Profile;

/**
 * The tools of the app (docs/frontend/tools-menu.md): pages that do something with the library or the sound instead of
 * browsing it, such as the stem mixer. One list feeds the "Tools" entry of the main menu, its sub-entries and the
 * /tools page, so a new tool is one row here (plus its route and page). A tool is shown only where the active profile
 * has the feature it needs (`Profile::allows`), and the Tools entry disappears when no tool is available.
 */
final class Tools
{
    /**
     * @return list<array{key: string, label: string, icon: string, route: string, routes: list<string>, feature: string, description: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'mixer',
                'label' => 'Mixer',
                'icon' => 'wave-square',
                'route' => 'mixer',
                'routes' => ['mixer'],
                'feature' => 'deep_analysis',
                'description' => 'Mix the vocals, drums, bass and the rest of two tracks, in this browser.',
            ],
            [
                'key' => 'export',
                'label' => 'Export',
                'icon' => 'file-zipper',
                'route' => 'tools.export',
                'routes' => ['tools.export'],
                'feature' => 'file_storage',
                'description' => 'Take albums, artists and playlists as a zip for an MP3 player or car radio, and follow the exports.',
            ],
            [
                'key' => 'sync',
                'label' => 'Sync',
                'icon' => 'arrows-rotate',
                'route' => 'tools.sync',
                'routes' => ['tools.sync'],
                'feature' => 'file_storage',
                'description' => 'Keep a USB stick or an MP3 player filled with part of the library, and change only what is needed.',
            ],
        ];
    }

    /** The tools the active profile can run. */
    public static function available(): array
    {
        return array_values(array_filter(self::all(), fn (array $tool) => Profile::allows($tool['feature'])));
    }
}
