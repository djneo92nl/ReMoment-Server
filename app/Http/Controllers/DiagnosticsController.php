<?php

namespace App\Http\Controllers;

use App\Domain\Diagnostics\DiagnosticsBundle;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The diagnostics zip as a download: the admin session (web) or the admin token (API) both land here. */
class DiagnosticsController extends Controller
{
    public function download(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'remoment-diag-');
        DiagnosticsBundle::make()->write($path);

        return response()->download($path, 'remoment-diagnostics-'.now()->format('Ymd-His').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
