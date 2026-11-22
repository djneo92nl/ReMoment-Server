<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The sound embedding of one section of a stored file (App\Domain\Similar\SectionEmbeddings). */
class SectionEmbedding extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['start' => 'float', 'end' => 'float'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
