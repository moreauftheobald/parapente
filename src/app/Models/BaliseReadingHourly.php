<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat horaire d'une balise (cf. migration create_balise_readings_hourly_table).
 * Une ligne par (balise, heure pile). Alimenté par AggregateBaliseReadingsHourlyJob.
 */
class BaliseReadingHourly extends Model
{
    protected $table = 'balise_readings_hourly';

    protected $fillable = [
        'balise_id',
        'hour_at',
        'wind_direction',
        'wind_speed_avg',
        'wind_speed_max',
        'temperature',
        'readings_count',
    ];

    protected $casts = [
        'hour_at'        => 'datetime',
        'wind_direction' => 'integer',
        'wind_speed_avg' => 'decimal:1',
        'wind_speed_max' => 'decimal:1',
        'temperature'    => 'decimal:1',
        'readings_count' => 'integer',
    ];

    public function balise(): BelongsTo
    {
        return $this->belongsTo(Balise::class);
    }
}
