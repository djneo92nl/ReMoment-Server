<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An NFC tag or QR code that plays something (docs/api/tags.md); see App\Domain\Tags\PlayTags. */
class PlayTag extends Model
{
    protected $guarded = [];

    protected $casts = ['shuffle' => 'boolean', 'last_used_at' => 'datetime', 'target_id' => 'integer'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
