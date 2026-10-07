<?php

namespace App\Models\Concerns;

/** A household-wide favorite flag (`favorited_at`), shared by albums, artists and radio stations. */
trait Favoritable
{
    public function toggleFavorite(): bool
    {
        return $this->setFavorite($this->favorited_at === null);
    }

    /** Keeps the original `favorited_at` when an already favorite item is favorited again. */
    public function setFavorite(bool $favorite): bool
    {
        $this->update(['favorited_at' => $favorite ? ($this->favorited_at ?? now()) : null]);

        return $favorite;
    }
}
