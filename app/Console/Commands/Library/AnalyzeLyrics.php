<?php

namespace App\Console\Commands\Library;

use App\Domain\Lyrics\LyricsAnalysis;
use App\Models\Media\Track;
use Illuminate\Console\Command;

class AnalyzeLyrics extends Command
{
    protected $signature = 'library:lyrics-analysis
        {--limit=2000 : Most tracks to read this run}
        {--redo : Also tracks that already have a language (after the word lists changed)}';

    protected $description = 'Detect the language (and a guessed explicit flag) of stored lyrics that have not been looked at yet; local, no requests';

    public function handle(): int
    {
        $query = Track::query()
            ->whereHas('metadata', fn ($q) => $q->whereIn('key', ['lyrics_plain', 'lyrics_synced'])->where('value', '!=', ''))
            ->orderBy('id');
        if (!$this->option('redo')) {
            $query->whereDoesntHave('metadata', fn ($q) => $q->where('key', LyricsAnalysis::LANGUAGE));
        }

        $n = 0;
        foreach ($query->limit(max(1, (int) $this->option('limit')))->get() as $track) {
            LyricsAnalysis::apply($track);
            $n++;
        }
        $this->info("{$n} tracks read.");

        return self::SUCCESS;
    }
}
