<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RadioStation extends Model
{
    protected $fillable = [
        'name',
        'image_url',
    ];

    public function plays(): HasMany
    {
        return $this->hasMany(Play::class);
    }

    public function meta(): HasMany
    {
        return $this->hasMany(RadioStationMeta::class);
    }

    public function getMeta(string $key): ?string
    {
        return $this->meta->firstWhere('key', $key)?->value;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->meta()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
        $this->unsetRelation('meta');
    }
}
