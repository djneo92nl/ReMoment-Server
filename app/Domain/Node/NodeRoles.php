<?php

namespace App\Domain\Node;

/**
 * The closed list of roles a ReMoment Node can declare (docs/architecture/node-agent.md).
 * Declared by the node, enabled by the admin; an unknown name is a validation error.
 */
final class NodeRoles
{
    public const KIND_CLIENT = 'client';

    public const KIND_NODE = 'node';

    public const KINDS = [self::KIND_CLIENT, self::KIND_NODE];

    public const ROLES = [
        'ripper' => 'CD ripper',
        'line-in' => 'Line-in',
        'player' => 'Player',
        'sync-target' => 'Sync target',
    ];

    /** Largest `capabilities` document a node may send, in bytes of JSON. */
    public const MAX_CAPABILITIES_BYTES = 16384;

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ROLES);
    }

    /**
     * Keep only known, distinct role names, in the canonical order.
     *
     * @param  array<int, mixed>|null  $roles
     * @return list<string>
     */
    public static function normalize(?array $roles): array
    {
        $roles = array_map('strval', $roles ?? []);

        return array_values(array_filter(self::names(), fn (string $r) => in_array($r, $roles, true)));
    }
}
