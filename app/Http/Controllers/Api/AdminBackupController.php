<?php

namespace App\Http\Controllers\Api;

use App\Domain\Backup\BackupCopy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The optional second copy of every backup (docs/api/admin-backup.md): one directory path, a test-write, and the
 * result of the last copy. Admin token only. The path is the one Setting of Domain\Backup\BackupCopy, shared with
 * the card on /settings/health.
 */
class AdminBackupController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** Saves the path after a test-write; an empty or null path switches the copy off. An unusable path is a 422 and nothing is saved. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => ['present', 'nullable', 'string', 'max:500']]);
        $path = trim((string) $data['path']);

        if ($path !== '') {
            $check = BackupCopy::usable($path);
            if (!$check['ok']) {
                throw ValidationException::withMessages(['path' => [$check['message']]]);
            }
        }

        BackupCopy::setPath($path);

        return response()->json($this->payload());
    }

    /** Writes and removes a small file in `path` (default: the saved one); never creates the directory. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $path = trim((string) ($data['path'] ?? '')) ?: BackupCopy::path();

        if ($path === null) {
            throw ValidationException::withMessages(['path' => ['No path given and none saved.']]);
        }

        return response()->json(['path' => $path] + BackupCopy::usable($path));
    }

    private function payload(): array
    {
        return [
            'path' => BackupCopy::path(),
            'keep' => max(1, (int) config('backup.keep')),
            'last_copy' => BackupCopy::status(),
        ];
    }
}
