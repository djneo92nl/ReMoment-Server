<?php

namespace App\Models\Media;

use App\Domain\Analysis\Camelot;
use App\Domain\Library\Normalizer;
use App\Domain\Storage\FileIndexer;
use App\Domain\Storage\StreamUrl;
use App\Models\Concerns\Favoritable;
use App\Models\FileAnalysis;
use App\Models\Media\Concerns\HasGenres;
use App\Models\Media\Concerns\ReadsMetadata;
use App\Models\Play;
use App\Models\TrackFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Track extends Model
{
    use Favoritable, HasGenres, ReadsMetadata;

    /** Metadata keys the album page reads for each track. */
    public const DISPLAY_METADATA = [
        'dlna_url', 'lyrics_plain', 'credits', 'isrc', 'explicit', 'spotify_popularity', 'mbid',
        'mime_type', 'bit_depth', 'sample_rate', 'bitrate', 'channels', 'file_size', 'track_number', 'disc_number',
        'lastfm_listeners', 'lastfm_playcount',
    ];

    protected $table = 'tracks';

    protected $fillable = [
        'album_id',
        'artist_id',
        'external_id',
        'name',
        'duration',
        'source',
        'images',
        'favorited_at',
    ];

    protected $casts = [
        'images' => 'array',
        'favorited_at' => 'datetime',
        'duration' => 'integer',
    ];

    /** Keeps `name_key` (the identity used to find this track again across sources) in step with `name`. */
    protected static function booted(): void
    {
        static::saving(function (self $track) {
            if ($track->isDirty('name') || $track->name_key === null) {
                $track->name_key = Normalizer::track($track->name);
            }
        });
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    /** The track's file links: the original and any proxies (see App\Models\TrackFile). */
    public function trackFiles(): HasMany
    {
        return $this->hasMany(\App\Models\TrackFile::class);
    }

    /** What the server computed from this track's primary file (loudness, BPM, key, …), when it has been analysed. */
    public function analysis(): HasOneThrough
    {
        return $this->hasOneThrough(FileAnalysis::class, TrackFile::class, 'track_id', 'file_id', 'id', 'file_id')
            ->where('track_files.is_primary', true);
    }

    public function metadata(): MorphMany
    {
        return $this->morphMany(Metadata::class, 'metadatable');
    }

    /**
     * A track that only exists because a radio stream announced it (source `radio`): no album, no
     * id, and the station's logo as its image. Matching it to a real track replaces it.
     */
    /**
     * The library sources this track is available from, `files`, `dlna` and/or `spotify`: its own source, or
     * a DLNA url / Spotify id kept from a merge.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $meta = $this->relationLoaded('metadata')
            ? $this->metadata->whereIn('key', ['dlna_url', 'external_id'])
            : $this->metadata()->whereIn('key', ['dlna_url', 'external_id'])->get();

        $sources = [];
        foreach (['files', 'dlna', 'spotify'] as $source) {
            if ($this->source === $source || $meta->contains(fn ($m) => $m->source === $source || str_starts_with((string) $m->source, $source.':'))) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /** Whether the track may be removed from the library: it isn't available from the Local or DLNA library. */
    public function isRemovable(): bool
    {
        return array_intersect(['files', 'dlna'], $this->sources()) === [];
    }

    public function isRadioStub(): bool
    {
        return $this->source === 'radio';
    }

    /** Whether this server streams the track from a file of its own (see FileIndexer). */
    public function isOwnFile(): bool
    {
        $rows = $this->relationLoaded('metadata') ? $this->metadata : $this->metadata()->where('key', 'dlna_url')->get();

        return $rows->contains(fn ($m) => $m->key === 'dlna_url' && $m->source === FileIndexer::URL_SOURCE);
    }

    public function getDlnaUrl(): ?string
    {
        $rows = $this->relationLoaded('metadata')
            ? $this->metadata->where('key', 'dlna_url')
            : $this->metadata()->where('key', 'dlna_url')->get();

        // A track in both the Local files and on a DLNA server plays from the file.
        $row = $rows->firstWhere('source', FileIndexer::URL_SOURCE) ?? $rows->first();

        // Our own files are addressed at play time, so the setting applies to tracks scanned before it.
        if ($row?->source === FileIndexer::URL_SOURCE) {
            return StreamUrl::for($this->id);
        }

        return $row?->value;
    }

    public function lyricsPlain(): ?string
    {
        $value = $this->relationLoaded('metadata')
            ? $this->metadata->firstWhere('key', 'lyrics_plain')?->value
            : $this->metadata()->where('key', 'lyrics_plain')->value('value');

        return ($value !== null && $value !== '') ? $value : null;
    }

    public function lyricsSynced(): ?string
    {
        $value = $this->relationLoaded('metadata')
            ? $this->metadata->firstWhere('key', 'lyrics_synced')?->value
            : $this->metadata()->where('key', 'lyrics_synced')->value('value');

        return ($value !== null && $value !== '') ? $value : null;
    }

    private const FORMATS = [
        'audio/flac' => 'FLAC', 'audio/x-flac' => 'FLAC', 'audio/mpeg' => 'MP3', 'audio/mp3' => 'MP3',
        'audio/mp4' => 'AAC', 'audio/x-m4a' => 'AAC', 'audio/aac' => 'AAC', 'audio/wav' => 'WAV', 'audio/x-wav' => 'WAV',
        'audio/ogg' => 'Ogg', 'audio/x-aiff' => 'AIFF', 'audio/aiff' => 'AIFF',
    ];

    /** File format and quality a DLNA server reported, e.g. "FLAC · 16-bit / 44.1 kHz · 1411 kbps · stereo · 31.2 MB". */
    public function audioQuality(): ?string
    {
        $mime = $this->metaValue('mime_type');
        $format = $mime ? (self::FORMATS[$mime] ?? strtoupper(preg_replace('~^audio/(x-)?~', '', $mime))) : null;

        $depth = $this->metaValue('bit_depth');
        $rate = $this->metaValue('sample_rate');
        $bitrate = $this->bitrateKbps();
        $channels = $this->metaValue('channels');
        $size = $this->metaValue('file_size');

        $parts = array_filter([
            $format,
            $rate ? trim(($depth ? "{$depth}-bit / " : '').rtrim(rtrim(number_format($rate / 1000, 1), '0'), '.').' kHz') : ($depth ? "{$depth}-bit" : null),
            $bitrate ? "{$bitrate} kbps" : null,
            $channels ? match ((int) $channels) {
                1 => 'mono', 2 => 'stereo', default => "{$channels} channels"
            } : null,
            $size ? number_format($size / 1048576, 1).' MB' : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /** The bitrate in kbps: servers report it as kbps (320) or as bits per second (320000). */
    private function bitrateKbps(): ?int
    {
        $bitrate = (int) $this->metaValue('bitrate');

        return $bitrate <= 0 ? null : ($bitrate > 10000 ? (int) round($bitrate / 1000) : $bitrate);
    }

    /**
     * A short badge for tracks worth flagging: lossless files ("FLAC 24/96", "FLAC") and high-bitrate lossy ones
     * ("320 kbps", from 256); null for everything else.
     */
    public function qualityBadge(): ?string
    {
        $mime = $this->metaValue('mime_type');
        $format = $mime ? (self::FORMATS[$mime] ?? null) : null;
        $depth = (int) $this->metaValue('bit_depth');
        $rate = (int) $this->metaValue('sample_rate');
        $bitrate = $this->bitrateKbps() ?? 0;

        if (in_array($format, ['FLAC', 'WAV', 'AIFF'], true)) {
            $hiRes = $depth > 16 || $rate > 48000;

            return $format.($hiRes && $depth && $rate ? ' '.$depth.'/'.rtrim(rtrim(number_format($rate / 1000, 1), '0'), '.') : '');
        }

        return $bitrate >= 256 ? $bitrate.' kbps' : null;
    }

    /** What the album page and the library API show about a track beyond its name and duration. */
    public function details(): array
    {
        return [
            'genres' => $this->genres(),
            'quality' => $this->audioQuality(),
            'credits' => (object) $this->credits(),
            'isrc' => $this->metaValue('isrc'),
            'explicit' => ($e = $this->metaValue('explicit')) !== null ? $e === '1' : null,
            'popularity' => $this->metaInt('spotify_popularity'),
            'listeners' => $this->metaInt('lastfm_listeners'),
            'playcount' => $this->metaInt('lastfm_playcount'),
            'analysis' => $this->sound(),
        ];
    }

    /**
     * What the server computed from the track's own audio file (App\Domain\Analysis): loudness, ReplayGain,
     * BPM, key, danceability, mood, …; null while it has no analysed file. Fractions are 0–1.
     */
    public function sound(): ?array
    {
        $a = $this->relationLoaded('analysis') ? $this->analysis : $this->analysis()->first();

        if ($a === null) {
            return null;
        }

        return [
            'bpm' => $a->bpm !== null ? round($a->bpm, 1) : null,
            'key' => $a->key,
            'mode' => $a->mode,
            'camelot' => Camelot::code($a->key, $a->mode),
            'loudness' => $a->loudness_lufs,
            'loudness_range' => $a->loudness_range,
            'true_peak' => $a->true_peak,
            'plr' => $a->true_peak !== null && $a->loudness_lufs !== null ? round($a->true_peak - $a->loudness_lufs, 1) : null,
            'replaygain_track_gain' => $a->replaygain_track_gain,
            'replaygain_album_gain' => $a->replaygain_album_gain,
            'danceability' => $a->danceability,
            'arousal' => $a->arousal,
            'valence' => $a->valence,
            'instrumental' => $a->instrumental,
            'mood' => array_column($a->mood_tags ?? [], 'name'),
            'recording_mbid' => $a->acoustid_recording_mbid,
        ];
    }

    /** @return list<int>|null peak values 0–100 of the track's own audio file, null until it is analysed */
    public function waveform(): ?array
    {
        $a = $this->relationLoaded('analysis') ? $this->analysis : $this->analysis()->first();

        return $a?->waveform ?: null;
    }

    /** The MusicBrainz recording id: the one found by name or by audio fingerprint (not the tag's release-track id). */
    public function recordingMbid(): ?string
    {
        $rows = $this->relationLoaded('metadata') ? $this->metadata : $this->metadata()->where('key', 'mbid')->get();

        return $rows->first(fn ($m) => $m->key === 'mbid' && in_array($m->source, ['musicbrainz', 'acoustid'], true))?->value;
    }
}
