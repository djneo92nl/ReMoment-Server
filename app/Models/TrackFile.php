<?php

namespace App\Models;

use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackFile extends Model
{
    public const ORIGINAL = 'original';

    public const PROXY = 'proxy';

    protected $fillable = ['track_id', 'file_id', 'role', 'profile', 'derived_from_file_id', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
