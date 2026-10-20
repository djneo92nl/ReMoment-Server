<?php

namespace App\Http\Controllers;

use App\Domain\Health\LibraryReports;

/** The library health reports (`App\Domain\Health\LibraryReports`): counts per report, and one report's findings. */
class LibraryHealthController extends Controller
{
    public function index()
    {
        return view('settings.library-health', ['definitions' => LibraryReports::definitions(), 'counts' => LibraryReports::counts()]);
    }

    public function show(string $report)
    {
        abort_unless(LibraryReports::exists($report), 404);

        return view('settings.library-health-report', [
            'key' => $report,
            'definition' => LibraryReports::definitions()[$report],
            'total' => LibraryReports::count($report),
            'rows' => LibraryReports::rows($report, 200),
        ]);
    }
}
