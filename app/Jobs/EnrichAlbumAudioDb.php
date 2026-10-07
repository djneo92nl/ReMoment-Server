<?php

namespace App\Jobs;

use App\Domain\Library\Enrichment;
use App\Jobs\Concerns\EnrichesFromSource;
use App\Models\Media\Album;
use App\Services\AudioDb\AudioDbClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;

/** An album's TheAudioDB data: mood, style, theme, a description and a rating; needs the release group's MusicBrainz id. */
class EnrichAlbumAudioDb implements ShouldBeUnique, ShouldQueue
{
    use EnrichesFromSource;

    public function __construct(public readonly Album $album) {}

    public function uniqueId(): string
    {
        return (string) $this->album->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('audiodb')];
    }

    public function handle(AudioDbClient $audioDb): void
    {
        $album = $this->album;
        $groupMbid = $album->metadata()->where('key', 'release_group_mbid')->value('value');

        if (!$groupMbid || Enrichment::isDone($album, Enrichment::AUDIODB)) {
            return;
        }

        $data = $audioDb->get('album-mb.php', $groupMbid)['album'][0] ?? null;

        if ($data !== null) {
            $save = Enrichment::saver($album, Enrichment::AUDIODB);

            $save('audiodb_id', $data['idAlbum'] ?? null);
            $save('mood', $data['strMood'] ?? null);
            $save('style', $data['strStyle'] ?? null);
            $save('theme', $data['strTheme'] ?? null);
            $save('audiodb_description', $data['strDescriptionEN'] ?? null);
            if (($score = (float) ($data['intScore'] ?? 0)) > 0) {
                $save('rating', (string) round($score, 1), 'float');
            }

            Enrichment::fallbackGenres($album, [$data['strGenre'] ?? null, $data['strStyle'] ?? null], Enrichment::AUDIODB);
        }

        Enrichment::markDone($album, Enrichment::AUDIODB);
    }
}
