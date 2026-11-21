<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The sound embedding of one stored file (App\Domain\Similar\Embeddings). The primary key is the file id. */
class FileEmbedding extends Model
{
    protected $primaryKey = 'file_id';

    public $incrementing = false;

    protected $guarded = [];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
