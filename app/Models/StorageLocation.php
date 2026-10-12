<?php

namespace App\Models;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class StorageLocation extends Model
{
    public const DRIVERS = ['local', 's3'];

    public const ROLE_LIBRARY = 'library';

    public const ROLE_INBOX = 'inbox';

    protected $fillable = ['name', 'driver', 'config', 'role', 'last_scanned_at'];

    protected $hidden = ['config'];

    protected $casts = [
        'config' => 'encrypted:array',
        'last_scanned_at' => 'datetime',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function isLocal(): bool
    {
        return $this->driver === 'local';
    }

    /** The Flysystem disk for this location; failures throw so a half-done move never goes unnoticed. */
    public function disk(): Filesystem
    {
        $config = $this->config ?? [];

        return Storage::build(match ($this->driver) {
            'local' => ['driver' => 'local', 'root' => $config['root'] ?? '', 'throw' => true],
            's3' => [
                'driver' => 's3',
                'key' => $config['key'] ?? null,
                'secret' => $config['secret'] ?? null,
                'region' => $config['region'] ?? 'us-east-1',
                'bucket' => $config['bucket'] ?? '',
                'endpoint' => $config['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
                'root' => $config['prefix'] ?? '',
                'throw' => true,
            ],
            default => throw new \InvalidArgumentException("Unknown storage driver {$this->driver}."),
        });
    }

    public static function library(): ?self
    {
        return self::where('role', self::ROLE_LIBRARY)->orderBy('id')->first();
    }

    public static function inbox(): ?self
    {
        return self::where('role', self::ROLE_INBOX)->orderBy('id')->first();
    }
}
