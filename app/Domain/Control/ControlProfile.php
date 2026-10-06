<?php

namespace App\Domain\Control;

/** One kind of source's controls (CD, tape, FM): a label and abstract commands. */
final readonly class ControlProfile
{
    /**
     * @param  array<int, array{id: string, label: string, icon?: string, type?: string, group?: string}>  $commands
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $commands,
    ) {}

    public function has(string $commandId): bool
    {
        return in_array($commandId, array_column($this->commands, 'id'), true);
    }

    public function toArray(): array
    {
        return [
            'profile' => $this->key,
            'label' => $this->label,
            'controls' => array_map(fn (array $c) => [
                'id' => $c['id'],
                'label' => $c['label'],
                'icon' => $c['icon'] ?? null,
                'type' => $c['type'] ?? 'button',
                'group' => $c['group'] ?? null,
            ], $this->commands),
        ];
    }
}
