<?php

namespace App\Domain\Sync;

use InvalidArgumentException;

/**
 * The diff of a sync (docs/architecture/offline-sync.md, section 3): what a target should hold (`wanted`) against what its
 * manifest says it holds (`have`). Pure: no database, clock or filesystem. Both lists hold entries
 * `{key, fingerprint, path, size}`; the key (`track:12`) survives renames, the fingerprint says which bytes, the path where.
 *
 * add: wanted only. replace: same key, other fingerprint. move: same key and fingerprint, other path (a rename on the
 * target, no download). keep: all equal. remove: in `have` only, so only ever something we wrote ourselves.
 * `pathConflicts`: a destination path that another entry staying where it is still occupies (the planner must avoid it).
 */
final readonly class SyncDiff
{
    /**
     * @param  list<array{key: string, fingerprint: string, path: string, size: int}>  $add
     * @param  list<array{key: string, fingerprint: string, path: string, size: int, from_path: string, from_size: int}>  $replace
     * @param  list<array{key: string, path: string, from_path: string}>  $move
     * @param  list<string>  $keep  keys
     * @param  list<array{key: string, path: string, size: int}>  $remove
     * @param  list<array{key: string, path: string, occupied_by: string}>  $pathConflicts
     */
    public function __construct(
        public array $add,
        public array $replace,
        public array $move,
        public array $keep,
        public array $remove,
        public array $pathConflicts,
    ) {}

    /**
     * @param  iterable<array<string, mixed>>  $wanted
     * @param  iterable<array<string, mixed>>  $have
     */
    public static function diff(iterable $wanted, iterable $have): self
    {
        $want = self::index($wanted, 'wanted');
        $own = self::index($have, 'have');

        $add = $replace = $move = $keep = $remove = [];

        foreach ($want as $key => $w) {
            $h = $own[$key] ?? null;

            if ($h === null) {
                $add[] = $w;
            } elseif ($h['fingerprint'] !== $w['fingerprint']) {
                $replace[] = $w + ['from_path' => $h['path'], 'from_size' => $h['size']];
            } elseif ($h['path'] !== $w['path']) {
                $move[] = ['key' => $key, 'path' => $w['path'], 'from_path' => $h['path']];
            } else {
                $keep[] = $key;
            }
        }

        foreach ($own as $key => $h) {
            if (!isset($want[$key])) {
                $remove[] = ['key' => $key, 'path' => $h['path'], 'size' => $h['size']];
            }
        }

        return new self($add, $replace, $move, $keep, $remove, self::conflicts($want, $own, $keep));
    }

    /** Bytes the target has to receive (adds and replacements). */
    public function bytesToAdd(): int
    {
        return array_sum(array_column($this->add, 'size')) + array_sum(array_column($this->replace, 'size'));
    }

    /** Bytes the target gets back (removals and the old copies of replacements). */
    public function bytesFreed(): int
    {
        return array_sum(array_column($this->remove, 'size')) + array_sum(array_column($this->replace, 'from_size'));
    }

    public function netBytes(): int
    {
        return $this->bytesToAdd() - $this->bytesFreed();
    }

    public function isEmpty(): bool
    {
        return $this->add === [] && $this->replace === [] && $this->move === [] && $this->remove === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'add' => $this->add,
            'replace' => $this->replace,
            'move' => $this->move,
            'keep' => count($this->keep),
            'remove' => $this->remove,
            'path_conflicts' => $this->pathConflicts,
            'bytes_to_add' => $this->bytesToAdd(),
            'bytes_freed' => $this->bytesFreed(),
        ];
    }

    /**
     * @param  iterable<array<string, mixed>>  $entries
     * @return array<string, array{key: string, fingerprint: string, path: string, size: int}>
     */
    private static function index(iterable $entries, string $label): array
    {
        $out = [];

        foreach ($entries as $entry) {
            foreach (['key', 'fingerprint', 'path'] as $field) {
                if (!isset($entry[$field]) || !is_string($entry[$field]) || $entry[$field] === '') {
                    throw new InvalidArgumentException("A {$label} entry needs a {$field}.");
                }
            }

            $key = $entry['key'];

            if (isset($out[$key])) {
                throw new InvalidArgumentException("Duplicate key {$key} in {$label}.");
            }

            $out[$key] = [
                'key' => $key, 'fingerprint' => $entry['fingerprint'], 'path' => $entry['path'],
                'size' => max(0, (int) ($entry['size'] ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * A moved, replaced-in-place or added entry wanting a path that a different key still holds and keeps.
     * Paths compare case-insensitively (FAT32, exFAT, default macOS).
     *
     * @param  array<string, array<string, mixed>>  $want
     * @param  array<string, array<string, mixed>>  $own
     * @param  list<string>  $keep
     * @return list<array{key: string, path: string, occupied_by: string}>
     */
    private static function conflicts(array $want, array $own, array $keep): array
    {
        $staying = [];

        foreach ($keep as $key) {
            $staying[mb_strtolower($own[$key]['path'])] = $key;
        }

        // An entry that is not removed or moved away from its path also stays (a replacement keeps its own key's path).
        $conflicts = [];

        foreach ($want as $key => $w) {
            $holder = $staying[mb_strtolower($w['path'])] ?? null;

            if ($holder !== null && $holder !== $key) {
                $conflicts[] = ['key' => $key, 'path' => $w['path'], 'occupied_by' => $holder];
            }
        }

        return $conflicts;
    }
}
