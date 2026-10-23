<?php

namespace App\Domain\Library;

use App\Domain\Library\SmartPlaylist\SmartPlaylistBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tracks page's selection: the smart playlist rules (SmartPlaylistBuilder, so a filter set can be
 * saved as a smart playlist as it is) plus the page's own search text and track-favourites switch.
 */
final class TrackBrowse
{
    /** Every track the definition, the search text and the favourites switch match, for what $scope shows. */
    public static function query(array $definition, string $scope, string $search = '', bool $favorites = false): Builder
    {
        $search = trim($search);

        return SmartPlaylistBuilder::query($definition, $scope)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('tracks.name', 'like', "%{$search}%")
                ->orWhereHas('artist', fn ($a) => $a->where('name', 'like', "%{$search}%"))
                ->orWhereHas('album', fn ($a) => $a->where('name', 'like', "%{$search}%"))))
            ->when($favorites, fn (Builder $q) => $q->whereNotNull('tracks.favorited_at'));
    }
}
