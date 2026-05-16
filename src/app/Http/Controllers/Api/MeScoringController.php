<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSiteCondition;
use App\Services\Map\DayQualityCalculator;
use App\Services\Map\SunWindowCalculator;
use App\Services\Weather\UserScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sur-couche utilisateur du map bundle : retourne les overrides de
 * scoring perso pour les sites où l'utilisateur connecté en a un.
 *
 * Le bundle global servi par `/api/map-bundle` est partagé entre
 * tous les utilisateurs (max d'efficacité cache). Quand un utilisateur
 * a un scoring perso actif/inactif sur certains sites (typiquement
 * 1-3 sites), cet endpoint renvoie juste les overrides correspondants
 * que le client fusionne en mémoire :
 *
 *  - flag `user_scoring` (active / inactive / null) pour le badge marker
 *  - re-calcul des `days[day]` (status, viability, green_hours) si
 *    scoring perso ACTIF sur le site
 *
 * Cf. FF_map_bundle_cache.md (phase 4).
 */
class MeScoringController extends Controller
{
    public function __construct(
        private readonly UserScoringService $userScoring,
        private readonly DayQualityCalculator $dayQuality,
    ) {}

    public function overrides(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            // Couvert normalement par le middleware auth — sécurité fallback.
            return response()->json(['overrides' => []]);
        }

        // Tous les scorings perso (actifs OU inactifs) de l'utilisateur.
        // Les inactifs servent au badge gris « tu as un scoring perso
        // enregistré sur ce site, mais inactif » (cf. carte légende).
        $scorings = UserSiteCondition::forUser($user->id)
            ->with('site')
            ->get();

        $overrides = [];
        foreach ($scorings as $usc) {
            // Note : la colonne s'appelle `active` (pas `is_active`) sur la
            // table sites — ne pas confondre avec UserSiteCondition::is_active.
            if (! $usc->site || ! $usc->site->active) {
                continue;
            }

            $flag = $usc->is_active ? 'active' : 'inactive';
            $entry = ['user_scoring' => $flag];

            // Si le scoring est actif, on recalcule les statuts journaliers
            // avec les conditions perso (réutilise rescoreForUserSite qui
            // est lui-même caché Redis 1 h par UserScoringService).
            if ($usc->is_active) {
                $entry['days'] = $this->rescoreDays($user->id, $usc->site);
            }

            $overrides[(string) $usc->site_id] = $entry;
        }

        return response()->json([
            'user_id'   => $user->id,
            'overrides' => $overrides,
        ]);
    }

    /**
     * Pour un site donné, applique le scoring perso et recalcule la
     * structure `days` du bundle (status, viability, green_hours par
     * jour). Réutilise UserScoringService::rescoreForUserSite qui sert
     * de cache des statuts horaires perso.
     *
     * @return array<string,array{status:string,viability:int,green_hours:int}>
     */
    private function rescoreDays(int $userId, \App\Models\Site $site): array
    {
        $userRescored = $this->userScoring->rescoreForUserSite($userId, $site->id);
        if ($userRescored === null) {
            return [];
        }

        // Regrouper par jour (d/m) + fenêtre solaire pour computeDayQuality.
        $lat = (float) $site->latitude;
        $lng = (float) $site->longitude;

        // Re-organise : day => [hour => status]. Les forecast_at sont
        // au format 'Y-m-d H:i:s'.
        $byDay = [];
        foreach ($userRescored as $forecastAt => $info) {
            $dt   = \Carbon\Carbon::parse($forecastAt);
            $day  = $dt->format('d/m');
            $hour = (int) $dt->format('G');
            $byDay[$day][$hour] = $info['status'];
        }

        $out = [];
        foreach ($byDay as $day => $byHour) {
            $window  = SunWindowCalculator::compute($lat, $lng, $day);
            // Filtre fenêtre solaire (computeDayQuality s'en occupe via le
            // start_hour/end_hour, mais on garde seulement les heures dans
            // la fenêtre pour le passer proprement).
            $filtered = [];
            foreach ($byHour as $h => $st) {
                if ($h >= $window['start_hour'] && $h <= $window['end_hour']) {
                    $filtered[$h] = $st;
                }
            }
            $quality = $this->dayQuality->compute($filtered, $window);
            $out[$day] = [
                'status'      => $quality['status'],
                'viability'   => $quality['viability'],
                'green_hours' => $quality['green_hours'],
            ];
        }

        return $out;
    }
}
