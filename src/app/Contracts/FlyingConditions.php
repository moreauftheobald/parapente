<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Conditions de vol favorables pour un site donné.
 *
 * Implémenté par `App\Models\SiteCondition` (réglage administrateur,
 * global au site) et `App\Models\UserSiteCondition` (réglage personnel
 * de l'utilisateur connecté). Permet au `ScoringService` de calculer
 * indifféremment un statut "global" ou "perso" à partir des mêmes
 * `forecasts` brutes.
 */
interface FlyingConditions
{
    public function isWindDirectionFavorable(int $degrees): bool;

    public function isWindSpeedFavorable(float $speed): bool;

    /**
     * Override site/user du seuil rafale orange (km/h), ou null pour
     * appliquer le défaut global de `settings`.
     */
    public function getWindGustOrangeKmh(): ?float;

    /**
     * Override site/user du seuil rafale rouge (km/h), ou null pour
     * appliquer le défaut global de `settings`.
     */
    public function getWindGustRedKmh(): ?float;

    /**
     * Plafond minimal acceptable pour voler (m ASL), ou null si non défini.
     */
    public function getCloudBaseMinM(): ?int;
}
