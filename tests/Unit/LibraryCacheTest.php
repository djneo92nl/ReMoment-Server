<?php

namespace Tests\Unit;

use App\Domain\Library\LibraryCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LibraryCacheTest extends TestCase
{
    private int $computed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();   // the refresh after the response runs at once
    }

    private function value(): callable
    {
        return function () {
            return ['n' => ++$this->computed];
        };
    }

    public function test_computes_once_and_serves_the_cached_value(): void
    {
        $this->assertSame(['n' => 1], LibraryCache::remember('t:once', $this->value()));
        $this->assertSame(['n' => 1], LibraryCache::remember('t:once', $this->value()));
        $this->assertSame(1, $this->computed);
    }

    public function test_a_library_write_makes_it_stale_only_after_the_minimum_age_and_serves_the_old_value_meanwhile(): void
    {
        LibraryCache::remember('t:stale', $this->value());

        LibraryCache::observe('insert into `albums` (`name`) values (?)');
        $this->assertSame(['n' => 1], LibraryCache::remember('t:stale', $this->value()));
        $this->assertSame(1, $this->computed, 'inside the minimum age nothing is recomputed');

        $this->travel(LibraryCache::MIN_REFRESH + 1)->seconds();
        LibraryCache::touch();

        // The stale value comes back at once; the refresh runs after the response (at once in a test).
        $this->assertSame(['n' => 1], LibraryCache::remember('t:stale', $this->value()));
        $this->assertSame(['n' => 2], LibraryCache::remember('t:stale', $this->value()));
    }

    public function test_it_is_recomputed_after_the_max_age_without_any_write(): void
    {
        LibraryCache::remember('t:age', $this->value(), maxAge: 60);

        $this->travel(61)->seconds();
        LibraryCache::remember('t:age', $this->value(), maxAge: 60);

        $this->assertSame(['n' => 2], LibraryCache::remember('t:age', $this->value(), maxAge: 60));
    }

    public function test_only_writes_to_library_tables_count(): void
    {
        LibraryCache::remember('t:obs', $this->value());
        $this->travel(LibraryCache::MIN_REFRESH + 1)->seconds();

        foreach ([
            'select * from `albums`',
            'insert into `plays` (`track_id`) values (?)',
            'update `metadata` set `value` = ?',
            'update `playlists` set `last_played_at` = ? where `id` = ?',
            'update `albums` set `favorited_at` = ? where `id` = ?',
        ] as $sql) {
            LibraryCache::observe($sql);
        }
        $this->assertSame(['n' => 1], LibraryCache::remember('t:obs', $this->value()));
        $this->assertSame(['n' => 1], LibraryCache::remember('t:obs', $this->value()), 'no library write was seen');

        foreach ([
            'insert into `tracks` (`name`) values (?)',
            'update `albums` set `images` = ? where `id` = ?',
            'delete from `playlist_track` where `playlist_id` = ?',
            'insert ignore into `genreables` (`genre_id`) values (?)',
        ] as $sql) {
            Cache::forget('library:changed-at');
            $this->travel(1)->seconds();
            LibraryCache::observe($sql);
            $this->assertGreaterThan(0, (float) Cache::get('library:changed-at', 0), $sql);
        }
    }
}
