<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaliseReading extends Model
{
    public $timestamps = false; // uniquement created_at défini en migration

    protected $fillable = [
        'balise_id',
        'read_at',
        'wind_direction',
        'wind_speed_avg',
        'wind_speed_min',
        'wind_speed_max',
        'temperature',
        'humidity',
    ];

    protected $casts = [
        'read_at'        => 'datetime',
        'wind_direction' => 'integer',
        'wind_speed_avg' => 'decimal:1',
        'wind_speed_min' => 'decimal:1',
        'wind_speed_max' => 'decimal:1',
        'temperature'    => 'decimal:1',
        'humidity'       => 'integer',
        'created_at'     => 'datetime',
    ];

    // ── Relations ───────────────────────────────────────────────
    public function balise(): BelongsTo
    {
        return $this->belongsTo(Balise::class);
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeAtHour($query, \DateTimeInterface $hour)
    {
        return $query
            ->where('read_at', '>=', $hour)
            ->where('read_at', '<', (clone $hour)->modify('+1 hour'));
    }
}
