<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Forecast extends Model
{
    protected $fillable = [
        'site_id',
        'weather_model_id',
        'forecast_at',
        'fetched_at',
        'wind_direction',
        'wind_speed_avg',
        'wind_speed_min',
        'wind_speed_max',
        'precipitation',
        'cloud_cover_low',
        'cloud_cover_mid',
        'cloud_cover_high',
        'cloud_base_m',
        'temperature',
        'humidity',
    ];

    protected $casts = [
        'forecast_at'     => 'datetime',
        'fetched_at'      => 'datetime',
        'wind_direction'  => 'integer',
        'wind_speed_avg'  => 'decimal:1',
        'wind_speed_min'  => 'decimal:1',
        'wind_speed_max'  => 'decimal:1',
        'precipitation'   => 'decimal:1',
        'cloud_cover_low' => 'integer',
        'cloud_cover_mid' => 'integer',
        'cloud_cover_high'=> 'integer',
        'cloud_base_m'    => 'integer',
        'temperature'     => 'decimal:1',
        'humidity'        => 'integer',
    ];

    // ── Relations ───────────────────────────────────────────────
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function weatherModel(): BelongsTo
    {
        return $this->belongsTo(WeatherModel::class);
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeForModel($query, int $modelId)
    {
        return $query->where('weather_model_id', $modelId);
    }

    public function scopeAtHour($query, \DateTimeInterface $hour)
    {
        return $query->where('forecast_at', $hour);
    }

    public function scopeUpcoming($query, int $days = 5)
    {
        return $query
            ->where('forecast_at', '>=', now()->startOfHour())
            ->where('forecast_at', '<=', now()->addDays($days));
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Nombre d'heures entre maintenant et ce créneau (horizon).
     */
    public function horizonHours(): int
    {
        return (int) now()->diffInHours($this->forecast_at);
    }
}
