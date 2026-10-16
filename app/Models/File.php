<?php

namespace App\Models;

use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A stored file; the bytes live on its StorageLocation. Tracks reach it through track_files. */
class File extends Model
{
    public const PRESENT = 'present';

    public const MISSING = 'missing';

    protected $fillable = [
        'storage_location_id', 'path', 'hash', 'size', 'mtime', 'mime_type', 'extension', 'codec',
        'bitrate', 'sample_rate', 'bit_depth', 'channels', 'duration', 'status', 'hidden_at',
    ];

    protected $casts = ['hidden_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (self $file) {
            if ($file->isDirty('path') || $file->path_hash === null) {
                $file->path_hash = hash('sha256', $file->path);
            }
        });
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }

    public function trackFiles(): HasMany
    {
        return $this->hasMany(TrackFile::class);
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(FileAnalysis::class);
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'track_files')->withPivot(['role', 'profile', 'is_primary']);
    }
}
