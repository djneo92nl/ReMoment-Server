<?php

namespace App\Domain\Actions;

use App\Domain\Actions\Definitions\ActivateSourceAction;
use App\Domain\Actions\Definitions\MovePlaybackAction;

/** The registry of the spike: a plain list (a real one would be a tagged container binding). */
final class Actions
{
    /** @return array<string, Action> keyed by name */
    public static function all(): array
    {
        $all = [];
        foreach ([new MovePlaybackAction, new ActivateSourceAction] as $action) {
            $all[$action->name()] = $action;
        }

        return $all;
    }
}
