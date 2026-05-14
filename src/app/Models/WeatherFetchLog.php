<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeatherFetchLog extends Model
{
    protected $table = 'weather_fetch_log';

    protected $fillable = [
        'weather_model_id',
        'scope',
        'fetched_at',
        'rows_upserted',
        'provider_run_at',
    ];

    protected $casts = [
        'fetched_at'      => 'datetime',
        'provider_run_at' => 'datetime',
        'rows_upserted'   => 'integer',
    ];

    public function weatherModel(): BelongsTo
    {
        return $this->belongsTo(WeatherModel::class);
    }
}
