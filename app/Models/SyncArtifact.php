<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A file made for a sync (a transcode or remux), shared by every target with the same fingerprint. */
class SyncArtifact extends Model
{
    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $fillable = ['fingerprint', 'file_id', 'state', 'extension', 'size', 'sha256', 'error', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    /** Path on the artifact disk. */
    public function path(): string
    {
        return "sync-artifacts/{$this->fingerprint}.{$this->extension}";
    }
}
