<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Support\SelectedDevice;

class PlayerController extends Controller
{
    public function select(Device $device, SelectedDevice $selected)
    {
        $selected->set($device);

        return back();
    }

    public function clear(SelectedDevice $selected)
    {
        $selected->clear();

        return back();
    }
}
