<?php

namespace App\Models;

use App\Domain\Credentials\ServiceCredentials;
use Illuminate\Database\Eloquent\Model;

/** One media server (docs/architecture/media-servers.md); its driver comes from config/media-servers.php. */
class MediaServer extends Model
{
    protected $fillable = ['driver', 'name', 'base_url', 'credential', 'enabled', 'meta'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'meta' => 'array'];
    }

    /** The ServiceCredentials service the key is read from. */
    public function credentialId(): string
    {
        return $this->credential ?: (string) config("media-servers.drivers.{$this->driver}.credentials", $this->driver);
    }

    /** The server's own address, else the one of its credentials (Settings › Services); without a trailing slash. */
    public function baseUrl(): string
    {
        return $this->base_url ? rtrim($this->base_url, '/') : ServiceCredentials::baseUrl($this->credentialId());
    }
}
