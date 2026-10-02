<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Artist;
use App\Services\AudioDb\AudioDbClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** An artist's TheAudioDB data: photo, fanart, logo and banner, mood, style and a biography; needs the MusicBrainz id. */
class EnrichArtistAudioDb implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Artist $artist) {}

    public function uniqueId(): string
    {
        return (string) $this->artist->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('audiodb')];
    }

    public function handle(AudioDbClient $audioDb): void
    {
        $artist = $this->artist;
        $mbid = $artist->metadata()->where('key', 'mbid')->where('source', Enrichment::MUSICBRAINZ)->value('value');

        if (!$mbid || Enrichment::isDone($artist, Enrichment::AUDIODB)) {
            return;
        }

        $data = $audioDb->get('artist-mb.php', $mbid)['artists'][0] ?? null;

        if ($data !== null) {
            $save = fn (string $key, ?string $value, string $type = 'string') => $value !== null && trim($value) !== ''
                ? Enrichment::save($artist, $key, trim($value), $type, Enrichment::AUDIODB) : null;

            $save('audiodb_id', $data['idArtist'] ?? null);
            $save('mood', $data['strMood'] ?? null);
            $save('style', $data['strStyle'] ?? null);
            $save('audiodb_bio', $data['strBiographyEN'] ?? null);

            $images = array_filter([
                'thumb' => $data['strArtistThumb'] ?? null,
                'fanart' => $data['strArtistFanart'] ?? null,
                'logo' => $data['strArtistLogo'] ?? null,
                'banner' => $data['strArtistBanner'] ?? null,
            ]);
            if ($images) {
                $save('images', json_encode($images), 'json');
            }

            Enrichment::fallbackGenres($artist, [$data['strGenre'] ?? null, $data['strStyle'] ?? null], Enrichment::AUDIODB);
        }

        Enrichment::markDone($artist, Enrichment::AUDIODB);
    }
}
