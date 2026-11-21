<?php

namespace App\Http\Controllers\Api;

use App\Domain\Sync\SyncFiles;
use App\Http\Controllers\Controller;
use App\Models\SyncTarget;

/**
 * One file of a sync, with Range (docs/api/sync.md): the same handler behind the admin token (header) and behind a
 * signed URL (the signature is the authorization, valid `SYNC_URL_HOURS`, bound to target, key and fingerprint).
 */
class SyncFileController extends Controller
{
    public function show(SyncTarget $target, string $key, string $fingerprint)
    {
        return app(SyncFiles::class)->serve($target, $key, $fingerprint);
    }
}
