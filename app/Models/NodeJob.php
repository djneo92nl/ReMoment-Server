<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One job for a ReMoment Node (docs/architecture/node-agent.md section 4). */
class NodeJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'client_id', 'role', 'type', 'params', 'state', 'idempotency_key', 'lease_until', 'deadline_at',
        'result', 'error', 'cancel_requested', 'accepted_at', 'finished_at',
    ];

    protected $casts = [
        'params' => 'array',
        'result' => 'array',
        'cancel_requested' => 'boolean',
        'lease_until' => 'datetime',
        'deadline_at' => 'datetime',
        'accepted_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->state, ['succeeded', 'failed', 'cancelled', 'expired', 'lost'], true);
    }

    /** What the node and the API see. */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'role' => $this->role,
            'params' => $this->params ?? new \stdClass,
            'state' => $this->state,
            'cancel_requested' => $this->cancel_requested,
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'lease_until' => $this->lease_until?->toIso8601String(),
            'progress' => \App\Domain\Node\NodeJobs::progressOf($this),
            'result' => $this->result,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
