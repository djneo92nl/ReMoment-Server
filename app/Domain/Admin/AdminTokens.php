<?php

namespace App\Domain\Admin;

use App\Models\AdminToken;
use Illuminate\Support\Collection;

/**
 * The app sign-ins of the one shared admin (Settings › Admin & Apps and `/api/admin/tokens`). A token is not an
 * account; this only lists and revokes. The hash never leaves the server.
 */
class AdminTokens
{
    /** @return Collection<int, AdminToken> most recently used first */
    public function all(): Collection
    {
        return AdminToken::orderByDesc('last_used_at')->orderByDesc('id')->get();
    }

    /** @return array{id: int, name: ?string, last_used_at: ?string, last_used_ip: ?string, created_at: ?string, current: bool} */
    public function toApi(AdminToken $token, ?AdminToken $current = null): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'last_used_ip' => $token->last_used_ip,
            'created_at' => $token->created_at?->toIso8601String(),
            'current' => $current !== null && $current->is($token),
        ];
    }

    public function revoke(AdminToken $token): void
    {
        $token->delete();
    }

    /** Signs every app out; `$except` keeps one (the caller's own token). @return int how many were revoked */
    public function revokeAll(?AdminToken $except = null): int
    {
        return AdminToken::query()->when($except, fn ($q) => $q->where('id', '!=', $except->id))->delete();
    }
}
