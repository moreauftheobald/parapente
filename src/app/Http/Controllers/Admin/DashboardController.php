<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SupervisionService;
use Illuminate\View\View;

/**
 * Page d'accueil du BackOffice : dashboard Supervision (santé du
 * pipeline, sidecar, couverture, APIs, incidents, volumétrie).
 * Cf. FF_admin_redesign.md (étape 1).
 */
class DashboardController extends Controller
{
    public function index(SupervisionService $supervision): View
    {
        return view('admin.dashboard', $supervision->build());
    }
}
