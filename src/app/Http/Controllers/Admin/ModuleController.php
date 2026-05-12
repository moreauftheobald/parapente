<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * BackOffice — gestion des modules du menu principal.
 *
 * Paramètres éditables par module :
 *   - is_active             : module actif (sinon masqué partout)
 *   - access_level          : niveau de droit requis (guest|user|admin)
 *   - requires_registration : utilisateur connecté obligatoire (oui/non)
 *
 * L'affichage dans la barre de menu découle de ces réglages
 * (cf. Module::isVisibleFor()).
 */
class ModuleController extends Controller
{
    public function index(): View
    {
        return view('admin.modules.index', [
            'modules' => Module::orderBy('sort_order')->orderBy('label')->get(),
        ]);
    }

    public function update(Request $request, Module $module): RedirectResponse
    {
        $request->validate([
            'access_level' => ['required', Rule::in(Module::ACCESS_LEVELS)],
        ]);

        $module->update([
            'is_active'             => $request->boolean('is_active'),
            'access_level'          => $request->string('access_level')->toString(),
            'requires_registration' => $request->boolean('requires_registration'),
        ]);

        return back()->with('status', "Module « {$module->label} » mis à jour.");
    }
}
