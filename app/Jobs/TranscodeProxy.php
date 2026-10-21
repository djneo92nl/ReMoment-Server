<?php

namespace App\Jobs;

use App\Domain\Storage\Proxies;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Makes one proxy (a smaller copy of a file in a profile of `config/proxies.php`) and tells the players waiting for it. */
class TranscodeProxy implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A file removed in the meantime is not an error. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 900;

    public function __construct(public readonly File $file, public readonly string $profile)
    {
        $this->timeout = (int) config('proxies.timeout');
        $this->onQueue(config('proxies.queue'));
    }

    public function uniqueId(): string
    {
        return "{$this->file->id}:{$this->profile}";
    }

    public function handle(): void
    {
        $file = $this->file->load('location');

        if ($file->status !== File::PRESENT || !Proxies::known($this->profile)) {
            Proxies::settle($file, $this->profile, false);

            return;
        }

        Proxies::generate($file, $this->profile);
        Proxies::settle($file, $this->profile, true);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning("Proxy {$this->profile} for file {$this->file->id} failed: {$e->getMessage()}");
        Proxies::settle($this->file, $this->profile, false);
    }
}
