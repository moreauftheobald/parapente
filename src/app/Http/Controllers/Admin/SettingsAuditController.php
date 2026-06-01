<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SettingsAudit;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsAuditController extends Controller
{
    public function index(Request $request): View
    {
        $query = SettingsAudit::query()
            ->with('user')
            ->orderByDesc('changed_at');

        if ($search = $request->input('search')) {
            $query->where('setting_key', 'like', "%{$search}%");
        }

        $entries = $query->paginate(50)->appends($request->query());

        return view('admin.settings.audit', compact('entries'));
    }
}
