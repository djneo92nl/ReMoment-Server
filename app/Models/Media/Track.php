<?php

namespace App\Models\Media;

use App\Domain\Library\Normalizer;
use App\Models\Media\Concerns\HasGenres;
use App\Models\Media\Concerns\ReadsMetadata;
use App\Models\Play;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Track extends Model
{
    use HasGenres, ReadsMetadata;

    /** Metadata keys the album page reads for each track. */
    public const DISPLAY_METADATA = [
        'dlna_url', 'lyrics_plain', 'credits', 'isrc', 'explicit', 'spotify_popularity', 'mbid',
        'mime_type', 'bit_depth', 'sample_rate', 'bitrate', 'channels', 'file_size', 'track_number', 'disc_number',
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
    ];

    protected $casts = [
        'images' => 'array',
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

    public function metadata(): MorphMany
    {
        return $this->morphMany(Metadata::class, 'metadatable');
    }

    public function getDlnaUrl(): ?string
    {
        if ($this->relationLoaded('metadata')) {
            return $this->metadata->firstWhere('key', 'dlna_url')?->value;
        }

        return $this->metadata()->where('key', 'dlna_url')->value('value');
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

    private const CREDIT_ORDER = ['composer', 'lyricist', 'writer', 'arranger', 'producer', 'co-producer', 'executive producer', 'engineer', 'recording', 'mix', 'mastering', 'programming', 'conductor', 'vocal', 'instrument', 'performer', 'remixer', 'DJ-mix'];

    /** File format and quality a DLNA server reported, e.g. "FLAC · 16-bit / 44.1 kHz · 1411 kbps · stereo · 31.2 MB". */
    public function audioQuality(): ?string
    {
        $mime = $this->metaValue('mime_type');
        $format = $mime ? (self::FORMATS[$mime] ?? strtoupper(preg_replace('~^audio/(x-)?~', '', $mime))) : null;

        $depth = $this->metaValue('bit_depth');
        $rate = $this->metaValue('sample_rate');
        $bitrate = $this->metaValue('bitrate');
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

    /**
     * People credited on the recording (MusicBrainz), grouped by role in a fixed order.
     *
     * @return array<string, list<string>> role => names, an instrument or other detail in brackets
     */
    public function credits(): array
    {
        $grouped = [];

        foreach ($this->metaJson('credits') as $credit) {
            $grouped[$credit['role']][] = $credit['name'].(isset($credit['detail']) ? " ({$credit['detail']})" : '');
        }

        uksort($grouped, fn ($a, $b) => (array_search($a, self::CREDIT_ORDER) === false ? 99 : array_search($a, self::CREDIT_ORDER))
            <=> (array_search($b, self::CREDIT_ORDER) === false ? 99 : array_search($b, self::CREDIT_ORDER)));

        return $grouped;
    }
}
