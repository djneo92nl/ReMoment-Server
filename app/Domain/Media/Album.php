<?php

namespace App\Domain\Media;

class Album
{
    public ?string $name;

    public array $images = [];

    public Artist $artist;

    public function __construct(array $data = [])
    {
        $this->name = $data['name'] ?? null;
        $this->images = $data['images'] ?? [];

        if (isset($data['artist'])) {
            $this->artist = new Artist($data['artist']);
        }
    }

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
        ];
        if (isset($data['artist'])) {
            $data['artist'] = $this->artist->toArray();
        }

        return $data;
    }
}
