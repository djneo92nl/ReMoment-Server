<?php

namespace App\Models\Media;

use App\Domain\Library\Normalizer;
use App\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A record, CD or tape on the shelf (docs/api/physical.md). Several items can share an album (a CD and a vinyl). */
class PhysicalItem extends Model
{
    public const MEDIA = ['cd', 'tape', 'vinyl', 'minidisc', 'other'];

    protected $table = 'physical_items';

    protected $guarded = [];

    protected $casts = ['acquired_on' => 'date', 'ripped_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            if ($item->isDirty('artist') || $item->artist_key === null) {
                $item->artist_key = Normalizer::artist($item->artist);
            }
            if ($item->isDirty('title') || $item->title_key === null) {
                $item->title_key = Normalizer::album($item->title);
            }
        });
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
