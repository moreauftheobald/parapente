<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCondition extends Model
{
    protected $fillable = [
        'site_id',
        'wind_dir_min',
        'wind_dir_max',
        'wind_speed_min',
        'wind_speed_max',
        'wind_speed_ideal',
        'precip_max',
        'cloud_base_min_m',
        'cloud_cover_low_max',
        'notes',
    ];

    protected $casts = [
        'wind_dir_min'        => 'integer',
        'wind_dir_max'        => 'integer',
        'wind_speed_min'      => 'integer',
        'wind_speed_max'      => 'integer',
        'wind_speed_ideal'    => 'integer',
        'precip_max'          => 'decimal:1',
        'cloud_base_min_m'    => 'integer',
        'cloud_cover_low_max' => 'integer',
    ];

    // ── Relations ───────────────────────────────────────────────
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Vérifie si une direction de vent est dans la plage favorable.
     * Gère le cas où la plage chevauche le Nord (ex: 315° → 45°).
     */
    public function isWindDirectionFavorable(int $degrees): bool
    {
        if ($this->wind_dir_min <= $this->wind_dir_max) {
            return $degrees >= $this->wind_dir_min
                && $degrees <= $this->wind_dir_max;
        }

        // Plage qui chevauche le Nord (ex: 315-45)
        return $degrees >= $this->wind_dir_min
            || $degrees <= $this->wind_dir_max;
    }

    /**
     * Vérifie si une vitesse de vent est dans la plage favorable.
     */
    public function isWindSpeedFavorable(float $speed): bool
    {
        return $speed >= $this->wind_speed_min
            && $speed <= $this->wind_speed_max;
    }

    /**
     * Vérifie si les précipitations sont acceptables.
     */
    public function isPrecipitationAcceptable(float $precip): bool
    {
        return $precip <= $this->precip_max;
    }
}
