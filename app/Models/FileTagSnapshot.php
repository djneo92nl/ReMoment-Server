<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a file looked like before its last tag edit, so the edit can be undone (App\Domain\Storage\TagEditor). */
class FileTagSnapshot extends Model
{
    protected $fillable = ['file_id', 'tags', 'had_cover', 'cover_changed', 'path_before', 'hash_after'];

    protected $casts = ['tags' => 'array', 'had_cover' => 'boolean', 'cover_changed' => 'boolean'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** Where the previous cover's bytes are kept (the private disk); meaningful only with `had_cover`. */
    public function coverPath(): string
    {
        return 'tag-snapshots/'.$this->file_id;
    }
}
