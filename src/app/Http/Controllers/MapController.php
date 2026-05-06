<?php

declare(strict_types=1);

namespace App\Http\Controllers;

class MapController extends Controller
{
    public function index()
    {
        return view('map.index');
    }
}
