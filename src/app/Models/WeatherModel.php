<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeatherModel extends Model
{
    protected $fillable = [
        'code',
        'name',
        'provider',
        'resolution_km',
        'max_horizon_h',
        'weight_short',
        'weight_medium',
        'refresh_frequency_minutes',
        'active',
    ];

    protected $casts = [
        'resolution_km'             => 'decimal:1',
        'max_horizon_h'             => 'integer',
        'weight_short'              => 'decimal:2',
        'weight_medium'             => 'decimal:2',
        'refresh_frequency_minutes' => 'integer',
        'active'                    => 'boolean',
    ];

    // ── Relations ───────────────────────────────────────────────
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Modèles disponibles pour un horizon donné (en heures).
     */
    public function scopeAvailableForHorizon($query, int $hours)
    {
        return $query->where('active', true)
            ->where('max_horizon_h', '>=', $hours);
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Retourne le poids à appliquer selon l'horizon de prévision.
     * Court terme : <= 48h / Moyen terme : > 48h
     */
    public function getWeightForHorizon(int $hours): float
    {
        return $hours <= 48
            ? (float) $this->weight_short
            : (float) $this->weight_medium;
    }

    /**
     * Vérifie si ce modèle couvre un horizon donné.
     */
    public function coversHorizon(int $hours): bool
    {
        return $hours <= $this->max_horizon_h;
    }
}
