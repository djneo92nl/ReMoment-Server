<?php

namespace App\Domain\Similar;

use App\Models\File;
use App\Models\FileEmbedding;
use Illuminate\Support\Facades\Cache;

/**
 * The sound embeddings of the own files (workstream 4, docs/architecture/similar-tracks.md): the vector the Essentia
 * sidecar's Discogs-EffNet network makes of a track, kept in `file_embeddings` as the source of truth.
 *
 * A vector is stored L2-normalised as little-endian float32 (`pack('g*')`), so the cosine similarity of two tracks is
 * their dot product. It belongs to the file content it was made from (`content_hash`): when the file changes the
 * stored vector is ignored until the analysis makes a new one.
 */
final class Embeddings
{
    /** The network the sidecar runs; stored with every vector so a later model never gets mixed with this one. */
    public const MODEL = 'discogs-effnet-bs64-1';

    public const DIMENSIONS = 1280;

    /** Bumped on every write, so cached results of the similarity query are not served after the vectors changed. */
    private const GENERATION_KEY = 'similar:generation';

    /**
     * Normalises a sidecar vector and packs it. Null for anything that is not a finite, non-zero list of the
     * expected length (a sidecar that answers something else must not poison the index).
     *
     * @param  mixed  $values  a list of numbers
     */
    public static function pack(mixed $values, int $dimensions = self::DIMENSIONS): ?string
    {
        if (!is_array($values) || count($values) !== $dimensions) {
            return null;
        }

        $floats = [];
        $norm = 0.0;
        foreach ($values as $value) {
            if (!is_int($value) && !is_float($value) || !is_finite((float) $value)) {
                return null;
            }
            $floats[] = (float) $value;
            $norm += $value * $value;
        }

        if ($norm <= 0.0) {
            return null;
        }

        $norm = sqrt($norm);

        return pack('g*', ...array_map(fn (float $v) => $v / $norm, $floats));
    }

    /** @return list<float> */
    public static function unpack(string $packed): array
    {
        return array_values(unpack('g*', $packed) ?: []);
    }

    /** Stores (or replaces) the embedding of a file for its current content. False when the vector is unusable. */
    public static function store(File $file, ?string $contentHash, mixed $values, string $model = self::MODEL): bool
    {
        $packed = self::pack($values);
        if ($packed === null) {
            return false;
        }

        FileEmbedding::query()->updateOrCreate(
            ['file_id' => $file->id],
            ['model' => $model, 'dimensions' => self::DIMENSIONS, 'vector' => $packed, 'content_hash' => $contentHash],
        );
        self::touch();

        return true;
    }

    public static function forget(File $file): void
    {
        FileEmbedding::query()->whereKey($file->id)->delete();
        self::touch();
    }

    /** Whether any file has an embedding: what `features.similar_tracks` says. */
    public static function any(): bool
    {
        return FileEmbedding::query()->exists();
    }

    public static function count(): int
    {
        return FileEmbedding::query()->count();
    }

    public static function generation(): int
    {
        return (int) Cache::get(self::GENERATION_KEY, 0);
    }

    private static function touch(): void
    {
        Cache::add(self::GENERATION_KEY, 0);
        Cache::increment(self::GENERATION_KEY);
    }

    /** The packed vector of a track's primary file, null while it has no current embedding (none, or made from an older file). */
    public static function ofTrack(int $trackId): ?string
    {
        $vector = \Illuminate\Support\Facades\DB::table('track_files')
            ->join('file_embeddings', 'file_embeddings.file_id', '=', 'track_files.file_id')
            ->join('file_analyses', 'file_analyses.file_id', '=', 'track_files.file_id')
            ->where('track_files.track_id', $trackId)
            ->where('track_files.is_primary', true)
            ->where('file_embeddings.model', self::MODEL)
            ->where(fn ($q) => $q->whereNull('file_embeddings.content_hash')->orWhereColumn('file_embeddings.content_hash', 'file_analyses.content_hash'))
            ->value('file_embeddings.vector');

        return is_resource($vector) ? stream_get_contents($vector) : $vector;
    }

    /** The cosine similarity of two stored (normalised) vectors, -1 to 1. */
    public static function dot(string $a, string $b): float
    {
        $x = unpack('g*', $a);
        $y = unpack('g*', $b);
        $sum = 0.0;
        foreach ($x as $i => $value) {
            $sum += $value * $y[$i];
        }

        return $sum;
    }
}
