<?php

namespace Tests\Unit;

use App\Domain\Sync\SyncDiff;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SyncDiffTest extends TestCase
{
    private function e(string $key, string $fp = 'a', ?string $path = null, int $size = 100): array
    {
        return ['key' => $key, 'fingerprint' => $fp, 'path' => $path ?? "{$key}.mp3", 'size' => $size];
    }

    public function test_empty_target_adds_everything(): void
    {
        $d = SyncDiff::diff([$this->e('track:1'), $this->e('track:2', size: 50)], []);

        $this->assertCount(2, $d->add);
        $this->assertSame(150, $d->bytesToAdd());
        $this->assertSame(0, $d->bytesFreed());
        $this->assertFalse($d->isEmpty());
    }

    public function test_equal_sets_are_an_empty_diff(): void
    {
        $list = [$this->e('track:1'), $this->e('track:2')];
        $d = SyncDiff::diff($list, $list);

        $this->assertTrue($d->isEmpty());
        $this->assertSame(['track:1', 'track:2'], $d->keep);
    }

    public function test_only_entries_of_have_can_be_removed(): void
    {
        $d = SyncDiff::diff([$this->e('track:1')], [$this->e('track:1'), $this->e('track:9', path: 'Old/9.mp3', size: 40)]);

        $this->assertSame([['key' => 'track:9', 'path' => 'Old/9.mp3', 'size' => 40]], $d->remove);
        $this->assertSame(-40, $d->netBytes());
    }

    public function test_other_fingerprint_replaces_and_counts_both_sizes(): void
    {
        $d = SyncDiff::diff([$this->e('track:1', 'new', size: 70)], [$this->e('track:1', 'old', size: 100)]);

        $this->assertCount(1, $d->replace);
        $this->assertSame('track:1.mp3', $d->replace[0]['from_path']);
        $this->assertSame(70, $d->bytesToAdd());
        $this->assertSame(100, $d->bytesFreed());
        $this->assertSame([], $d->add);
    }

    public function test_renamed_track_is_a_move_not_a_download(): void
    {
        $d = SyncDiff::diff([$this->e('track:1', path: 'A/New.mp3')], [$this->e('track:1', path: 'A/Old.mp3')]);

        $this->assertSame([['key' => 'track:1', 'path' => 'A/New.mp3', 'from_path' => 'A/Old.mp3']], $d->move);
        $this->assertSame(0, $d->bytesToAdd());
        $this->assertSame([], $d->add);
        $this->assertSame([], $d->remove);
    }

    public function test_path_taken_by_a_staying_entry_is_a_conflict_case_insensitively(): void
    {
        $d = SyncDiff::diff(
            [$this->e('track:1', path: 'a/Song.mp3'), $this->e('track:2', path: 'A/SONG.mp3')],
            [$this->e('track:1', path: 'a/Song.mp3')],
        );

        $this->assertSame([['key' => 'track:2', 'path' => 'A/SONG.mp3', 'occupied_by' => 'track:1']], $d->pathConflicts);
    }

    public function test_duplicate_keys_and_missing_fields_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SyncDiff::diff([$this->e('track:1'), $this->e('track:1')], []);
    }

    public function test_entry_without_fingerprint_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SyncDiff::diff([], [['key' => 'track:1', 'path' => 'x.mp3']]);
    }

    public function test_to_array_counts_keeps(): void
    {
        $a = SyncDiff::diff([$this->e('track:1'), $this->e('track:2')], [$this->e('track:1')])->toArray();

        $this->assertSame(0, $a['keep'] - 1);
        $this->assertCount(1, $a['add']);
        $this->assertSame(100, $a['bytes_to_add']);
    }
}
