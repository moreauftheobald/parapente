<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fiabilité dynamique d'un modèle météo, calculée par variable et par
 * horizon, contre une balise de référence.
 *
 * Une ligne par tuple (modèle × balise × horizon_bucket × variable).
 * Alimentée par `ComputeModelReliabilityJob` (phase 2). Consommée par
 * le consensus C (phase 2.5) et plus tard par `ScoringService` (phase 4).
 *
 * Cf. FF_model_reliability.md.
 */
class ModelReliability extends Model
{
    protected $table = 'model_reliability';

    protected $fillable = [
        'weather_model_id',
        'balise_id',
        'horizon_bucket',
        'variable',
        'mae',
        'rmse',
        'bias_signed',
        'weight_factor',
        'samples_n',
        'computed_at',
    ];

    protected $casts = [
        'mae'           => 'decimal:2',
        'rmse'          => 'decimal:2',
        'bias_signed'   => 'decimal:2',
        'weight_factor' => 'decimal:2',
        'samples_n'     => 'integer',
        'computed_at'   => 'datetime',
    ];

    public function weatherModel(): BelongsTo
    {
        return $this->belongsTo(WeatherModel::class);
    }

    public function balise(): BelongsTo
    {
        return $this->belongsTo(Balise::class);
    }
}
