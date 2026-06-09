<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Contracts\FlyingConditions;
use App\Models\SiteScore;
use App\Services\Settings;

/**
 * Moteur de règles de scoring (statut éliminatoire + couleurs par
 * paramètre), extrait de l'ancien `ScoringService` après le passage du
 * calcul de consensus ET du scoring au sidecar `consensus-grid-v2`.
 *
 * Le sidecar produit désormais le statut vert/orange/rouge « global » de
 * chaque site (table double-buffer `site_scores_{1,2}`). Ce moteur ne sert
 * plus qu'au RE-SCORING personnalisé : rejouer les règles éliminatoires
 * sur les valeurs consensus déjà persistées (`site_scores`) avec les
 * conditions propres d'un utilisateur (cf. `UserScoringService`). Il ne
 * touche ni aux `forecasts`, ni au consensus, ni à la base.
 *
 * Les seuils globaux sont préchargés à la construction (évite N appels
 * `Settings::get()` lors d'un rescore complet d'un site).
 */
class ScoringRules
{
    private float $precipOrangeMmh;
    private float $precipRedMmh;
    private float $gustOrangeDefault;
    private float $gustRedDefault;

    public function __construct(Settings $settings)
    {
        $this->precipOrangeMmh   = (float) $settings->get('scoring.precip_orange_mmh');
        $this->precipRedMmh      = (float) $settings->get('scoring.precip_red_mmh');
        $this->gustOrangeDefault = (float) $settings->get('scoring.gust_orange_kmh');
        $this->gustRedDefault    = (float) $settings->get('scoring.gust_red_kmh');
    }

    /** Seuil rafale orange applicable à un set de conditions (override ou défaut global). */
    private function gustOrangeFor(FlyingConditions $conditions): float
    {
        return $conditions->getWindGustOrangeKmh() ?? $this->gustOrangeDefault;
    }

    /** Seuil rafale rouge applicable à un set de conditions (override ou défaut global). */
    private function gustRedFor(FlyingConditions $conditions): float
    {
        return $conditions->getWindGustRedKmh() ?? $this->gustRedDefault;
    }

    // ── Règles éliminatoires ────────────────────────────────────

    private function applyEliminatoryRules(
        FlyingConditions $conditions,
        float $windDir,
        float $windSpeed,
        float $windGust,
        float $precip
    ): string {
        $gustOrange = $this->gustOrangeFor($conditions);
        $gustRed    = $this->gustRedFor($conditions);

        // ── Rouges (éliminatoires) ──────────────────────────────
        if ($precip > $this->precipRedMmh) {
            return 'red';
        }
        if ($windGust > $gustRed) {
            return 'red';
        }
        if (! $conditions->isWindDirectionFavorable((int) $windDir)) {
            return 'red';
        }
        if (! $conditions->isWindSpeedFavorable($windSpeed)) {
            return 'red';
        }

        // ── Oranges (prudence) ──────────────────────────────────
        if ($precip > $this->precipOrangeMmh) {
            return 'orange';
        }
        if ($windGust > $gustOrange) {
            return 'orange';
        }

        // Tout est OK
        return 'green';
    }

    // ── Re-scoring à partir d'un SiteScore déjà calculé ─────────

    /**
     * Recalcule statut + couleurs d'un `SiteScore` existant en appliquant
     * un autre set de `FlyingConditions` (typiquement celles d'un user).
     *
     * Le consensus multi-modèles (déjà persisté en colonnes du
     * `site_scores`) est réutilisé tel quel — on ne refait pas la moyenne
     * pondérée, on ne touche pas aux `forecasts`. Seules les règles
     * dépendantes des conditions sont rejouées.
     *
     * Retourne : `['status' => string, 'colors' => array{wind_dir,wind_speed,wind_gust,precip,cloud_base}]`.
     * La confiance (`confidence_pct`) ne dépend pas des conditions, on la
     * laisse intacte au niveau appelant.
     */
    public function rescore(FlyingConditions $conditions, SiteScore $score): array
    {
        $windDir   = (float) $score->wind_dir_consensus;
        $windSpeed = (float) $score->wind_speed_consensus;
        $windGust  = (float) $score->wind_gust_consensus;
        $precip    = (float) $score->precip_consensus;
        $cloudBase = $score->cloud_base_consensus !== null
            ? (float) $score->cloud_base_consensus
            : null;

        return [
            'status' => $this->applyEliminatoryRules($conditions, $windDir, $windSpeed, $windGust, $precip),
            'colors' => $this->computeParamColors($conditions, $windDir, $windSpeed, $windGust, $precip, $cloudBase),
        ];
    }

    // ── Couleurs par paramètre (onglet « Détail du scoring ») ──

    /**
     * Calcule la couleur green|orange|red de chaque paramètre de la voting
     * logic, en isolant le critère (contrairement au statut global qui est
     * agrégé). Logique alignée sur applyEliminatoryRules().
     *
     * @return array{wind_dir:string,wind_speed:string,wind_gust:string,precip:string,cloud_base:string}
     */
    public function computeParamColors(
        FlyingConditions $conditions,
        float $windDir,
        float $windSpeed,
        float $windGust,
        float $precip,
        ?float $cloudBase
    ): array {
        // Direction : binaire (dans l'axe / hors axe)
        $dirColor = $conditions->isWindDirectionFavorable((int) $windDir) ? 'green' : 'red';

        // Vitesse moyenne : binaire (dans la plage / hors plage)
        $speedColor = $conditions->isWindSpeedFavorable($windSpeed) ? 'green' : 'red';

        // Rafales : seuils globaux (settings) ou surcharges du site
        $gustOrange = $this->gustOrangeFor($conditions);
        $gustRed    = $this->gustRedFor($conditions);
        $gustColor  = match (true) {
            $windGust > $gustRed    => 'red',
            $windGust > $gustOrange => 'orange',
            default                 => 'green',
        };

        // Précipitations : seuils globaux sur le consensus seulement
        $precipColor = match (true) {
            $precip > $this->precipRedMmh    => 'red',
            $precip > $this->precipOrangeMmh => 'orange',
            default                          => 'green',
        };

        // Plafond : informatif (pas éliminatoire). Vert si > cloud_base_min_m,
        // orange dans une marge de 100 m, rouge en-dessous. Unknown si pas
        // de seuil défini sur le site ou pas de consensus (ciel dégagé).
        $minCeil = $conditions->getCloudBaseMinM();
        if ($cloudBase === null || $minCeil === null) {
            $cloudColor = 'unknown';
        } elseif ($cloudBase < $minCeil) {
            $cloudColor = 'red';
        } elseif ($cloudBase < $minCeil + 100) {
            $cloudColor = 'orange';
        } else {
            $cloudColor = 'green';
        }

        return [
            'wind_dir'   => $dirColor,
            'wind_speed' => $speedColor,
            'wind_gust'  => $gustColor,
            'precip'     => $precipColor,
            'cloud_base' => $cloudColor,
        ];
    }
}
