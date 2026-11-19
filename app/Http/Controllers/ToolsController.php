<?php

namespace App\Http\Controllers;

use App\Domain\Tools\Tools;
use Illuminate\Contracts\View\View;

/** The Tools page (docs/frontend/tools-menu.md): one card per tool the active profile can run. */
class ToolsController extends Controller
{
    public function __invoke(): View
    {
        return view('tools.index', ['tools' => Tools::available()]);
    }
}
