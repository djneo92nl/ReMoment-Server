<?php

namespace App\Console\Commands;

use App\Domain\Library\LibraryMerger;
use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Track;
use Illuminate\Console\Command;

class MergeLibraryDuplicates extends Command
{
    protected $signature = 'library:merge-duplicates
        {--dry-run : List what would be merged without changing anything}
        {--force : Merge without asking for confirmation}';

    protected $description = 'Merge artists, albums and tracks that are the same across sources (DLNA, Spotify, plays) into one record each';

    public function handle(LibraryMerger $merger): int
    {
        $plan = $merger->plan();
        $total = count($plan['artists']) + count($plan['albums']) + count($plan['tracks']);

        $this->report('Artists', Artist::class, $plan['artists'], fn (Artist $a) => $a->name);
        $this->report('Albums', Album::class, $plan['albums'], fn (Album $a) => $a->name.' — '.($a->artist?->name ?? '?'), ['artist']);
        $this->report('Tracks', Track::class, $plan['tracks'], fn (Track $t) => $t->name.' — '.($t->artist?->name ?? '?'), ['artist']);

        if ($total === 0) {
            $this->info('No duplicates found.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d artist, %d album and %d track group(s) to merge.',
            count($plan['artists']), count($plan['albums']), count($plan['tracks']),
        ));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Merge these? Ids of merged-away records stop existing.', true)) {
            return self::FAILURE;
        }

        $merger->rekey();
        $merged = $merger->merge($plan);

        $this->info(sprintf(
            'Merged away %d artist(s), %d album(s) and %d track(s).',
            $merged['artists'], $merged['albums'], $merged['tracks'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Artist|Album|Track>  $model
     * @param  list<array{0: int, 1: list<int>}>  $groups
     */
    private function report(string $title, string $model, array $groups, \Closure $label, array $with = []): void
    {
        if ($groups === []) {
            return;
        }

        $ids = collect($groups)->flatMap(fn (array $g) => [$g[0], ...$g[1]])->unique()->values();
        $records = collect();
        foreach ($ids->chunk(500) as $chunk) {
            $records = $records->union($model::query()->with($with)->whereKey($chunk->all())->get()->keyBy('id'));
        }

        $describe = fn (int $id) => ($r = $records->get($id))
            ? sprintf('#%d %s (%s)', $id, $label($r), $r->source ?? 'no source')
            : "#{$id}";

        $this->newLine();
        $this->line("<comment>{$title}</comment>");
        foreach ($groups as [$survivor, $losers]) {
            $this->line('  keep '.$describe($survivor));
            foreach ($losers as $loser) {
                $this->line('    ← '.$describe($loser));
            }
        }
    }
}
