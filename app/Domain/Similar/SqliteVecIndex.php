<?php

namespace App\Domain\Similar;

use App\Models\FileEmbedding;
use PDO;

/**
 * The optional fast path of docs/architecture/similar-tracks.md: a sqlite-vec `vec0` table (exact scan, cosine, float32)
 * in its own SQLite file, rebuilt from `file_embeddings` by `php artisan similar:index`. It is a derived cache: delete the
 * file and the query falls back to the PHP scan.
 *
 * Laravel's connection cannot load an extension, so this opens its own `Pdo\Sqlite` (PHP 8.4), loads the library by its
 * absolute path (`similar.vec_extension`) and binds every vector as a BLOB (a string would be parsed as JSON). The `vec0`
 * tables never go into the main database: `migrate:fresh` or a backup on a machine without the extension would fail on them.
 *
 * Unverified on real hardware in this repository: tests only reach it through a fake `VectorIndex`.
 */
final class SqliteVecIndex implements VectorIndex
{
    private ?\Pdo\Sqlite $pdo = null;

    public function __construct(private readonly ?string $extension = null, private readonly ?string $path = null) {}

    public static function configured(): self
    {
        return new self(config('similar.vec_extension'), config('similar.vec_path'));
    }

    /** The library is named, readable and this PHP can load extensions into a Pdo\Sqlite. */
    public function available(): bool
    {
        return class_exists(\Pdo\Sqlite::class) && method_exists(\Pdo\Sqlite::class, 'loadExtension')
            && $this->extension !== null && $this->extension !== '' && is_readable($this->extension);
    }

    public function exists(): bool
    {
        return $this->path !== null && is_file($this->path);
    }

    public function rowCount(): int
    {
        if (!$this->available() || !$this->exists()) {
            return 0;
        }

        try {
            return (int) $this->connection()->query('select count(*) from vectors')->fetchColumn();
        } catch (\Throwable) {
            return 0; // not built yet, or a damaged file
        }
    }

    public function nearest(string $packed, int $k): array
    {
        $statement = $this->connection()->prepare('select rowid, distance from vectors where embedding match ? and k = ?');
        $statement->bindValue(1, $packed, PDO::PARAM_LOB);
        $statement->bindValue(2, $k, PDO::PARAM_INT);
        $statement->execute();

        $hits = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$rowid, $distance]) {
            $hits[] = ['file_id' => (int) $rowid, 'similarity' => 1.0 - (float) $distance];
        }

        return $hits;
    }

    /** Rebuilds the whole file from the stored embeddings of the current model; returns the rows written. */
    public function rebuild(): int
    {
        $this->pdo = null;
        $temporary = $this->path.'.building';
        @unlink($temporary);

        $pdo = $this->open($temporary);
        $pdo->exec('create virtual table vectors using vec0(embedding float['.Embeddings::DIMENSIONS.'] distance_metric=cosine)');
        $pdo->beginTransaction();
        $insert = $pdo->prepare('insert into vectors(rowid, embedding) values (?, ?)');
        $written = 0;

        FileEmbedding::query()->where('model', Embeddings::MODEL)->where('dimensions', Embeddings::DIMENSIONS)
            ->select(['file_id', 'vector'])->lazyById(500, 'file_id')->each(function ($row) use ($insert, &$written) {
                $insert->bindValue(1, (int) $row->file_id, PDO::PARAM_INT);
                $insert->bindValue(2, is_resource($row->vector) ? stream_get_contents($row->vector) : $row->vector, PDO::PARAM_LOB);
                $insert->execute();
                $written++;
            });

        $pdo->commit();
        $pdo = null;

        rename($temporary, $this->path); // the old index answers until the new one is complete

        return $written;
    }

    private function connection(): \Pdo\Sqlite
    {
        return $this->pdo ??= $this->open($this->path);
    }

    private function open(string $path): \Pdo\Sqlite
    {
        $pdo = new \Pdo\Sqlite('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->loadExtension($this->extension);

        return $pdo;
    }
}
