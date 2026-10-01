<?php

namespace App\Domain\Artwork;

use App\Domain\Media\NowPlaying;
use App\Domain\Media\Radio;
use App\Jobs\ProcessArtwork;
use App\Models\RadioStation;
use Throwable;

/**
 * Picks the artwork for what is playing and tags it with a `kind`, so the
 * REST API and the MQTT /data payload always carry the same artwork object:
 *
 *   album  — the track/album cover
 *   radio  — a radio station's image (from the stream, else the station's
 *            image_url in /radio), or the generated radio logo
 *   source — a generated logo for the source (Spotify, line-in, TV, …),
 *            when nothing playing has an image
 */
final class NowPlayingArtwork
{
    public const KIND_ALBUM = 'album';

    public const KIND_RADIO = 'radio';

    public const KIND_SOURCE = 'source';

    /**
     * The image to process for this playback (a real URL or a SourceLogo
     * pseudo URL) and the kind of artwork it is.
     *
     * @return array{url: string, kind: string}
     */
    public static function source(NowPlaying $nowPlaying): array
    {
        $isRadio = $nowPlaying->radio !== null || $nowPlaying->platform === 'radio';

        $url = ArtworkCache::extractImageUrl($nowPlaying);
        if ($url !== null) {
            return ['url' => $url, 'kind' => $isRadio ? self::KIND_RADIO : self::KIND_ALBUM];
        }

        if ($isRadio) {
            $url = $nowPlaying->radio ? self::stationImageUrl($nowPlaying->radio) : null;

            return ['url' => $url ?? SourceLogo::url('radio'), 'kind' => self::KIND_RADIO];
        }

        return ['url' => SourceLogo::url(SourceLogo::keyFor($nowPlaying)), 'kind' => self::KIND_SOURCE];
    }

    /**
     * The cached artwork entry plus `kind`, or null while a real image is
     * still being processed (first play of a URL). Logos are rendered on the
     * spot when missing: they are local, quick, and only a handful exist.
     */
    public static function resolve(NowPlaying $nowPlaying): ?array
    {
        ['url' => $url, 'kind' => $kind] = self::source($nowPlaying);

        $entry = ArtworkCache::get($url);

        if (SourceLogo::isLogoUrl($url) && !(is_array($entry) && ArtworkCache::isComplete($entry))) {
            $entry = self::renderLogo($url);
        }

        return is_array($entry) ? ['kind' => $kind] + $entry : null;
    }

    /** A generated logo's artwork entry (without `kind`), rendered if missing. */
    public static function logo(string $key): ?array
    {
        $url = SourceLogo::url($key);
        $entry = ArtworkCache::get($url);

        return is_array($entry) && ArtworkCache::isComplete($entry) ? $entry : self::renderLogo($url);
    }

    private static function renderLogo(string $url): ?array
    {
        try {
            (new ProcessArtwork($url))->handle();
        } catch (Throwable $e) {
            report($e);
        }

        return ArtworkCache::get($url);
    }

    /** The image_url of the matching station in /radio, by platform id, then by name. */
    private static function stationImageUrl(Radio $radio): ?string
    {
        $withImage = fn () => RadioStation::query()->whereNotNull('image_url')->where('image_url', '!=', '');

        if ($radio->id !== null && $radio->id !== '') {
            $url = $withImage()->whereHas('meta', fn ($q) => $q->where('value', $radio->id))->value('image_url');
            if ($url) {
                return $url;
            }
        }

        if ($radio->name !== null && $radio->name !== '') {
            return $withImage()->where('name', $radio->name)->value('image_url') ?: null;
        }

        return null;
    }
}
