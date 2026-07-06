<?php

namespace App\Models;

use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DlnaServer extends Model
{
    protected $fillable = [
        'friendly_name',
        'ip',
        'port',
        'control_url',
        'last_scanned_at',
    ];

    protected $casts = [
        'last_scanned_at' => 'datetime',
        'port' => 'integer',
    ];

    // metadata.source stores 'dlna:{id}' strings, so hasManyThrough cannot join correctly.
    public function tracks(): Builder
    {
        return Track::whereHas('metadata', fn ($q) => $q
            ->where('key', 'dlna_url')
            ->where('source', 'dlna:'.$this->id)
        );
    }

    public function trackCount(): int
    {
        return Media\Metadata::where('key', 'dlna_url')
            ->where('source', "dlna:{$this->id}")
            ->whereHasMorph('metadatable', Track::class)
            ->count();
    }
}
