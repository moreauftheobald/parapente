<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeatherStationObservation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'weather_station_id',
        'observed_at',
        'wind_direction',
        'wind_speed_avg',
        'wind_speed_max',
        'wind_speed_max_10m',
        'wind_direction_max',
        'wind_direction_gust',
        'temperature',
        'temperature_min',
        'temperature_max',
        'humidity',
        'humidity_min',
        'humidity_max',
        'pressure_hpa',
        'precipitation_mm',
        'cloud_cover_pct',
        'visibility_m',
        'dew_point',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'observed_at'        => 'datetime',
            'wind_direction'     => 'integer',
            'wind_speed_avg'     => 'float',
            'wind_speed_max'     => 'float',
            'wind_speed_max_10m' => 'float',
            'wind_direction_max' => 'integer',
            'wind_direction_gust' => 'integer',
            'temperature'        => 'float',
            'temperature_min'    => 'float',
            'temperature_max'    => 'float',
            'humidity'           => 'integer',
            'humidity_min'       => 'integer',
            'humidity_max'       => 'integer',
            'pressure_hpa'       => 'float',
            'precipitation_mm'   => 'float',
            'cloud_cover_pct'    => 'integer',
            'visibility_m'       => 'integer',
            'dew_point'          => 'float',
            'raw_data'           => 'array',
            'created_at'         => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(WeatherStation::class, 'weather_station_id');
    }
}
