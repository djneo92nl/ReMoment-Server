<?php

namespace App\Integrations\Contracts;

interface LikeInterface
{
    /** Like (save to the library) or unlike the currently playing track. */
    public function setLiked(bool $liked): void;
}
