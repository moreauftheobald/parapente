<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteScore extends Model
{
    protected $fillable = [
        'site_id',
        'forecast_at',
        'computed_at',
        'status',
        'confidence_pct',
        'wind_dir_consensus',
        'wind_speed_consensus',
        'precip_consensus',
        'models_count',
        'models_converging',
        'detail',
    ];

    protected $casts = [
        'forecast_at'          => 'datetime',
        'computed_at'          => 'datetime',
        'confidence_pct'       => 'integer',
        'wind_dir_consensus'   => 'integer',
        'wind_speed_consensus' => 'decimal:1',
        'precip_consensus'     => 'decimal:1',
        'models_count'         => 'integer',
        'models_converging'    => 'integer',
        'detail'               => 'array',   // JSON auto-décodé
    ];

    // ── Relations ───────────────────────────────────────────────
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeGreen($query)
    {
        return $query->where('status', 'green');
    }

    public function scopeUpcoming($query, int $days = 5)
    {
        return $query
            ->where('forecast_at', '>=', now()->startOfHour())
            ->where('forecast_at', '<=', now()->addDays($days));
    }

    public function scopeForDay($query, \DateTimeInterface $day)
    {
        return $query
            ->whereDate('forecast_at', $day);
    }

    // ── Helpers ─────────────────────────────────────────────────

    public function isGreen(): bool
    {
        return $this->status === 'green';
    }

    public function isOrange(): bool
    {
        return $this->status === 'orange';
    }

    public function isRed(): bool
    {
        return $this->status === 'red';
    }
}
