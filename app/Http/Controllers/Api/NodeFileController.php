<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ripper\Rips;
use App\Domain\Storage\FileUploads;
use App\Http\Controllers\Api\Concerns\AuthenticatesNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The upload of a ripping node (`POST /api/nodes/files`, Bearer: the node's own token): the same ingest as the admin
 * upload of the Ripper app (`App\Domain\Storage\FileUploads`), so a node never holds an admin token. Allowed only while
 * the node has an accepted or running `ripper.rip` job that was not cancelled; the files of that job are what it
 * may send. An identical file that is already in the library is kept instead (a retried upload is a 201 again).
 */
class NodeFileController extends Controller
{
    use AuthenticatesNode;

    public function store(Request $request, FileUploads $uploads): JsonResponse
    {
        $node = $this->node($request);
        $job = Rips::uploadJob($node);

        if ($job === null) {
            return response()->json(['error' => 'no_rip_job', 'message' => 'A node may upload only while it has a running rip.'], 403);
        }

        $request->validate(['file' => ['required', 'file']]);

        try {
            [$file, $track] = $uploads->ingest($request->file('file'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'storage_error', 'message' => $e->getMessage()], 502);
        }

        Rips::noteUpload($job, $file);

        return response()->json([
            'file' => ['id' => $file->id, 'path' => $file->path, 'size' => $file->size, 'hash' => $file->hash],
            'track_id' => $track?->id,
        ], 201);
    }
}
