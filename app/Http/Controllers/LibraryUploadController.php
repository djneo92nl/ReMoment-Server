<?php

namespace App\Http\Controllers;

use App\Domain\Storage\AudioTags;
use App\Domain\Storage\LibraryFiles;
use App\Domain\Storage\LibraryUploads;
use App\Jobs\ProcessLibraryUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Browser drop zone on /settings/storage (admin): register a file, send its bytes, the queue does the rest. */
class LibraryUploadController extends Controller
{
    /** A row for the file, so the table shows it while its bytes are still travelling. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:0']]);
        $name = basename(str_replace('\\', '/', $data['name']));

        $row = LibraryUploads::start($name, $data['size']);

        if (!AudioTags::isAudio($name)) {
            LibraryUploads::fail($row['id'], 'Not an audio file.');

            return response()->json(['id' => $row['id'], 'accepted' => false]);
        }

        return response()->json(['id' => $row['id'], 'accepted' => true]);
    }

    public function receive(Request $request, string $id, LibraryFiles $files): JsonResponse
    {
        $row = LibraryUploads::find($id);
        abort_if($row === null || $row['state'] !== LibraryUploads::UPLOADING, 404);

        $upload = $request->file('file');
        if ($upload === null || !$upload->isValid()) {
            $message = $upload?->getErrorMessage() ?? 'No file received (larger than the server accepts?).';
            LibraryUploads::fail($id, $message);

            return response()->json(['message' => $message], 422);
        }

        $stream = fopen($upload->getRealPath(), 'rb');
        try {
            [$location, $path] = $files->stageUpload($row['name'], $stream);
        } catch (\InvalidArgumentException $e) {
            LibraryUploads::fail($id, $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            fclose($stream);
        }

        LibraryUploads::update($id, ['state' => LibraryUploads::PARSING, 'message' => 'Waiting for the queue']);
        ProcessLibraryUpload::dispatch($id, $location->id, $path);

        return response()->json(['id' => $id], 202);
    }
}
