<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A condition that is raised and later resolved; written only through App\Domain\Alerts\Alerts. */
class Alert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'raised_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isActive(): bool
    {
        return $this->resolved_at === null;
    }

    /** The shape of the REST API. */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'key' => $this->key,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'device_id' => $this->device_id,
            'data' => $this->data,
            'raised_at' => $this->raised_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'state' => $this->isActive() ? 'raised' : 'resolved',
        ];
    }
}
