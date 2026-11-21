<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A place that is kept filled with a rule-defined part of the library (docs/architecture/offline-sync.md): the volume of
 * a node, the iOS app's offline library. The server keeps the rules and the last report, never the file list of a target.
 */
class SyncTarget extends Model
{
    public const APP = 'app';

    public const NODE = 'node';

    public const KINDS = [self::APP, self::NODE];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'kind', 'client_ref', 'node_id', 'rules', 'rules_version', 'last_report', 'last_report_at',
        'owned_bytes', 'owned_count', 'last_run', 'active_job_id',
    ];

    protected $casts = [
        'rules' => 'array',
        'last_report' => 'array',
        'last_run' => 'array',
        'last_report_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $target) {
            $target->id ??= (string) Str::ulid();
        });
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'node_id');
    }

    public function job(): ?NodeJob
    {
        return $this->active_job_id ? NodeJob::find($this->active_job_id) : null;
    }
}
