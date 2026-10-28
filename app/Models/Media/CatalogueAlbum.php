<?php

namespace App\Models\Media;

use App\Domain\Library\Normalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A MusicBrainz release-group (album or EP) of a library artist; `album_id` is set when the library has it. */
class CatalogueAlbum extends Model
{
    protected $table = 'catalogue_albums';

    protected $guarded = [];

    protected $casts = [
        'linked_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'wanted_at' => 'datetime',
        'ignored_at' => 'datetime',
        'seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $row) {
            if ($row->isDirty('title') || $row->name_key === null) {
                $row->name_key = Normalizer::album($row->title);
            }
            if (mb_strlen((string) $row->title) > 255) {
                $row->title = Normalizer::storedName($row->title);
            }
            // A wish and "I don't want this" exclude each other: the one set last wins.
            if ($row->isDirty('wanted_at') && $row->wanted_at !== null) {
                $row->ignored_at = null;
            } elseif ($row->isDirty('ignored_at') && $row->ignored_at !== null) {
                $row->wanted_at = null;
            }
        });
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(AlbumAvailability::class);
    }
}
