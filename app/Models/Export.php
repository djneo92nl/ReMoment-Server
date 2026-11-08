<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One export request (workstream 13): the selection, the plan's totals, progress, skipped tracks and the zip parts on disk. */
class Export extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'public_id', 'selection_type', 'selection_id', 'track_ids', 'profile', 'options', 'status', 'tracks', 'copied',
        'transcoded', 'skipped_count', 'estimated_bytes', 'planned_parts', 'files_total', 'files_done', 'bytes',
        'skipped', 'part_files', 'error', 'started_at', 'finished_at', 'expires_at',
    ];

    protected $casts = [
        'track_ids' => 'array',
        'options' => 'array',
        'skipped' => 'array',
        'part_files' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $export) {
            $export->public_id ??= (string) Str::uuid();
        });
    }

    public function directory(): string
    {
        return "exports/{$this->public_id}";
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return ($this->options ?? [])[$key] ?? $default;
    }
}
