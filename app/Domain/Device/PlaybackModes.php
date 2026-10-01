<?php

namespace App\Domain\Device;

/**
 * Shuffle / repeat / liked state of a device's playback. Each value is null
 * when it is unknown or the device doesn't support it.
 */
final class PlaybackModes
{
    public function __construct(
        public readonly ?bool $shuffle = null,
        public readonly ?RepeatMode $repeat = null,
        public readonly ?bool $liked = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            shuffle: isset($data['shuffle']) ? (bool) $data['shuffle'] : null,
            repeat: isset($data['repeat']) ? RepeatMode::tryFrom((string) $data['repeat']) : null,
            liked: isset($data['liked']) ? (bool) $data['liked'] : null,
        );
    }

    public function withShuffle(bool $shuffle): self
    {
        return new self($shuffle, $this->repeat, $this->liked);
    }

    public function withRepeat(RepeatMode $repeat): self
    {
        return new self($this->shuffle, $repeat, $this->liked);
    }

    public function withLiked(bool $liked): self
    {
        return new self($this->shuffle, $this->repeat, $liked);
    }

    /** @return array{shuffle: ?bool, repeat: ?string, liked: ?bool} */
    public function toArray(): array
    {
        return [
            'shuffle' => $this->shuffle,
            'repeat' => $this->repeat?->value,
            'liked' => $this->liked,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }
}
