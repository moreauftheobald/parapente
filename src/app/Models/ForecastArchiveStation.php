<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastArchiveStation extends Model
{
    protected $table = 'forecast_archive_stations';

    protected $fillable = [
        'weather_station_id',
        'weather_model_id',
        'target_at',
        'fetched_at',
        'horizon_bucket',
        'wind_direction',
        'wind_speed_avg',
        'wind_speed_max',
        'temperature',
        'dew_point',
        'humidity',
        'precipitation',
        'pressure_hpa',
        'cloud_cover',
    ];

    protected function casts(): array
    {
        return [
            'target_at'      => 'datetime',
            'fetched_at'     => 'datetime',
            'wind_direction' => 'integer',
            'wind_speed_avg' => 'float',
            'wind_speed_max' => 'float',
            'temperature'    => 'float',
            'dew_point'      => 'float',
            'humidity'       => 'integer',
            'precipitation'  => 'float',
            'pressure_hpa'   => 'float',
            'cloud_cover'    => 'integer',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(WeatherStation::class, 'weather_station_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(WeatherModel::class, 'weather_model_id');
    }
}
