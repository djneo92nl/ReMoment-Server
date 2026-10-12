<?php

namespace App\Console\Commands\Library;

use App\Services\Local\LocalLibraryScanner;
use Illuminate\Console\Command;

class ScanLocalFiles extends Command
{
    protected $signature = 'library:scan-files {path : Folder with audio files}';

    protected $description = 'Index the audio files of a folder into the library, streamed by this server';

    public function handle(LocalLibraryScanner $scanner): int
    {
        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current% tracks — %message%');
        $bar->start();

        try {
            $count = $scanner->scan($this->argument('path'), function (int $total, string $file) use ($bar) {
                $bar->setMessage(mb_strimwidth($file, 0, 50, '…'));
                $bar->setProgress($total);
            });
        } catch (\InvalidArgumentException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine();
        $this->line("  → {$count} tracks indexed, {$scanner->skipped()} unchanged or unreadable.");

        return self::SUCCESS;
    }
}
