<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Helpers de listing (recherche + filtres ternaires + tri whitelisté)
 * partagés par les controllers admin (Site, Balise, WeatherModel, User).
 *
 * Les filtres scalaires triviaux (`where('source', $val)`) restent inline
 * dans chaque controller — ce trait ne couvre que les patterns à risque
 * (injection sur le `orderBy`) ou réellement répétitifs.
 */
trait HasFilterableIndex
{
    /**
     * Recherche full-text simple (LIKE %term%) sur N colonnes en OR.
     * Term vide ou null → no-op.
     */
    protected function applySearch(Builder $query, mixed $term, array $columns): void
    {
        $term = trim((string) $term);
        if ($term === '' || empty($columns)) {
            return;
        }

        $query->where(function ($q) use ($term, $columns) {
            foreach ($columns as $col) {
                $q->orWhere($col, 'like', "%{$term}%");
            }
        });
    }

    /**
     * Filtre tri-state booléen : '1' = vrai, '0' = faux, autre (null, '')
     * = pas de filtre. Utilisé pour des colonnes type `active`.
     */
    protected function applyTriStateFilter(Builder $query, mixed $raw, string $column): void
    {
        if ($raw === '1' || $raw === '0') {
            $query->where($column, (int) $raw);
        }
    }

    /**
     * Tri avec whitelist (anti-injection) + direction validée.
     *
     * @param  array<int,string> $allowed
     * @return array{0:string,1:string} `[sort, dir]` effectivement appliqués
     */
    protected function applySorting(
        Builder $query,
        Request $request,
        array $allowed,
        string $defaultSort,
        string $defaultDir = 'asc'
    ): array {
        $sort = (string) $request->input('sort', $defaultSort);
        if (! in_array($sort, $allowed, true)) {
            $sort = $defaultSort;
        }
        $dirInput = $request->input('dir', $defaultDir);
        $dir      = in_array($dirInput, ['asc', 'desc'], true) ? $dirInput : $defaultDir;

        $query->orderBy($sort, $dir);
        return [$sort, $dir];
    }
}
