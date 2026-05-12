<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Module;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Construit la liste des modules visibles dans la barre de menu pour
 * l'utilisateur courant, à partir de la table `modules` (éditable depuis
 * /admin/modules).
 */
final class Navigation
{
    /**
     * @return Collection<int, Module>
     */
    public static function modules(): Collection
    {
        $user = Auth::user();

        try {
            return Module::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get()
                ->filter(fn (Module $module): bool => $module->isVisibleFor($user))
                ->values();
        } catch (\Throwable) {
            // Table pas encore migrée (premier déploiement) : pas de menu.
            return collect();
        }
    }
}
