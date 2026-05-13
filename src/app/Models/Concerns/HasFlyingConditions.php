<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Implémentation commune de l'interface `App\Contracts\FlyingConditions`
 * pour les modèles `SiteCondition` et `UserSiteCondition` qui partagent
 * la même surface de propriétés (direction min/max, vitesses, overrides
 * rafales, plafond minimal).
 *
 * @property int|null $wind_dir_min
 * @property int|null $wind_dir_max
 * @property int|null $wind_speed_min
 * @property int|null $wind_speed_max
 * @property string|float|null $wind_gust_orange_kmh
 * @property string|float|null $wind_gust_red_kmh
 * @property int|null $cloud_base_min_m
 */
trait HasFlyingConditions
{
    /**
     * Vérifie si une direction de vent (en degrés FROM) est dans la
     * plage favorable. Gère le cas où la plage chevauche le Nord
     * (ex: 315° → 45°).
     */
    public function isWindDirectionFavorable(int $degrees): bool
    {
        if ($this->wind_dir_min <= $this->wind_dir_max) {
            return $degrees >= $this->wind_dir_min
                && $degrees <= $this->wind_dir_max;
        }

        return $degrees >= $this->wind_dir_min
            || $degrees <= $this->wind_dir_max;
    }

    public function isWindSpeedFavorable(float $speed): bool
    {
        return $speed >= $this->wind_speed_min
            && $speed <= $this->wind_speed_max;
    }

    public function getWindGustOrangeKmh(): ?float
    {
        return $this->wind_gust_orange_kmh !== null
            ? (float) $this->wind_gust_orange_kmh
            : null;
    }

    public function getWindGustRedKmh(): ?float
    {
        return $this->wind_gust_red_kmh !== null
            ? (float) $this->wind_gust_red_kmh
            : null;
    }

    public function getCloudBaseMinM(): ?int
    {
        return $this->cloud_base_min_m !== null
            ? (int) $this->cloud_base_min_m
            : null;
    }
}
