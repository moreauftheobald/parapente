<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeatherModel extends Model
{
    protected $fillable = [
        'code',
        'name',
        'provider',
        'weather_api_id',
        'oauth_client_id',
        'oauth_client_secret',
        'oauth_token',
        'oauth_expires_at',
        'endpoint_url',
        'resolution_km',
        'max_horizon_h',
        'weight_short',
        'weight_medium',
        'weight_factor',
        'notes_admin',
        'refresh_frequency_minutes',
        'last_fetch_at',
        'active',
    ];

    protected $casts = [
        'weather_api_id'            => 'integer',
        'oauth_client_id'           => 'encrypted',
        'oauth_client_secret'       => 'encrypted',
        'oauth_token'               => 'encrypted',
        'oauth_expires_at'          => 'datetime',
        'resolution_km'             => 'decimal:1',
        'max_horizon_h'             => 'integer',
        'weight_short'              => 'decimal:2',
        'weight_medium'             => 'decimal:2',
        'weight_factor'             => 'float',
        'refresh_frequency_minutes' => 'integer',
        'last_fetch_at'             => 'datetime',
        'active'                    => 'boolean',
    ];

    protected $hidden = [
        'oauth_client_secret',
        'oauth_token',
    ];

    // ── Relations ───────────────────────────────────────────────
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    public function api(): BelongsTo
    {
        return $this->belongsTo(WeatherApi::class, 'weather_api_id');
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

    /**
     * Indique si le modèle est dû pour un refresh selon sa cadence.
     * `last_fetch_at` est mis à jour globalement par l'orchestrateur de
     * fetch (FetchForecastsJob) — la cadence est une propriété du modèle
     * NWP, pas du site.
     */
    public function isDueForRefresh(): bool
    {
        if (! $this->active) {
            return false;
        }
        if ($this->last_fetch_at === null) {
            return true;
        }
        $interval = max(1, (int) $this->refresh_frequency_minutes);
        return $this->last_fetch_at->lt(now()->subMinutes($interval));
    }
}
