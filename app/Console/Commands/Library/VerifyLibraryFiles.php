<?php

namespace App\Console\Commands\Library;

use App\Domain\Storage\LibraryFiles;
use App\Models\File;
use Illuminate\Console\Command;

class VerifyLibraryFiles extends Command
{
    protected $signature = 'library:verify-files';

    protected $description = 'Re-hash stored files, flag missing ones and report duplicate content';

    public function handle(LibraryFiles $files): int
    {
        $counts = ['ok' => 0, 'missing' => 0, 'changed' => 0];

        File::with('location')->chunkById(100, function ($chunk) use ($files, &$counts) {
            foreach ($chunk as $file) {
                $counts[$files->verify($file)]++;
            }
        });

        $this->line(json_encode($counts));

        foreach ($files->duplicates() as $group) {
            $this->warn('Duplicate content: '.$group->map(fn (File $f) => "#{$f->id} {$f->path}")->implode(', '));
        }

        return self::SUCCESS;
    }
}
