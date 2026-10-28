<?php

namespace App\Domain\Library;

use App\Models\Media\Album;
use App\Models\Media\Artist;
use App\Models\Media\Playlist;
use App\Models\Media\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ids of library records that `library:merge-duplicates` merged away keep working (workstream 14.2, see
 * docs/architecture/library-identity.md): LibraryMerger writes an `id_aliases` row (entity, old id -> surviving id)
 * for every record it deletes, and every place that takes a library id asks this class which record the id means now.
 *
 * Invariants: at most one hop (record() re-points rows that pointed at a record that is merged later), and an alias
 * never points at an id that is itself an alias. A record that was REMOVED (LibraryRemover) is a real 404: forget()
 * drops the aliases that pointed at it. A live record always wins over an alias with the same id.
 *
 * Bound as a scoped service: the memo lives for one request.
 */
final class IdAliases
{
    public const MODELS = [
        'artist' => Artist::class,
        'album' => Album::class,
        'track' => Track::class,
        'playlist' => Playlist::class,
    ];

    /** @var array<string, int|null> "entity:id" => surviving id (null: not an alias) */
    private array $memo = [];

    /** The id a retired id was merged into, or null when $id is not an alias. Does not look at live records. */
    public function target(string $entity, int $id): ?int
    {
        $key = $entity.':'.$id;

        if (!array_key_exists($key, $this->memo)) {
            $new = DB::table('id_aliases')->where('entity', $entity)->where('old_id', $id)->value('new_id');
            $this->memo[$key] = $new === null ? null : (int) $new;
        }

        return $this->memo[$key];
    }

    /**
     * The id to use for a client's id: $id itself while that record exists, the surviving id when it was merged away,
     * and $id unchanged when it is really unknown (callers then answer 404/422 as before).
     */
    public function resolve(string $entity, int $id): int
    {
        $class = self::MODELS[$entity];

        if ($class::query()->whereKey($id)->exists()) {
            return $id;
        }

        $new = $this->target($entity, $id);

        return $new !== null && $class::query()->whereKey($new)->exists() ? $new : $id;
    }

    /** The model for an id as a client sent it (the alias followed), or null. */
    public function find(string $entity, mixed $id): mixed
    {
        if (!is_numeric($id) || (int) $id != $id) {
            return null;
        }

        return self::MODELS[$entity]::query()->find($this->resolve($entity, (int) $id));
    }

    /**
     * Replaces retired ids in the request input before validation, so `exists:` rules and finders see the survivor.
     *
     * @param  array<string, string>  $fields  input key => entity
     */
    public function resolveInput(Request $request, array $fields): void
    {
        $changed = [];

        foreach ($fields as $key => $entity) {
            $value = $request->input($key);
            if (is_numeric($value) && (int) $value == $value && (int) $value > 0) {
                $resolved = $this->resolve($entity, (int) $value);
                if ($resolved !== (int) $value) {
                    $changed[$key] = $resolved;
                }
            }
        }

        if ($changed !== []) {
            $request->merge($changed);
        }
    }

    /** Remembers that $oldId was merged into $newId, collapsing chains. Call inside the merge's transaction. */
    public function record(string $entity, int $oldId, int $newId): void
    {
        if ($oldId === $newId) {
            return;
        }

        // The survivor is itself an alias only if rows were written by hand; follow it so no alias points at an alias.
        $newId = DB::table('id_aliases')->where('entity', $entity)->where('old_id', $newId)->value('new_id') ?? $newId;

        // Rows that pointed at the record that goes away now point at its survivor: never a chain.
        DB::table('id_aliases')->where('entity', $entity)->where('new_id', $oldId)->update(['new_id' => $newId]);

        DB::table('id_aliases')->updateOrInsert(
            ['entity' => $entity, 'old_id' => $oldId],
            ['new_id' => $newId, 'merged_at' => now()],
        );

        $this->memo = [];
    }

    /** A record was removed for real: aliases to it would answer with a record that is gone, so they go too. */
    public function forget(string $entity, int $id): void
    {
        DB::table('id_aliases')->where('entity', $entity)->where('new_id', $id)->delete();
        $this->memo = [];
    }
}
