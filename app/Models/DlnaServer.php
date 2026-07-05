<?php

namespace App\Models;

use App\Models\Media\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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

    public function tracks(): HasManyThrough
    {
        return $this->hasManyThrough(
            Track::class,
            Media\Metadata::class,
            'source',
            'id',
            'id',
            'metadatable_id',
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
