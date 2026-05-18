<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historique des comparaisons de consensus en shadow mode (phase 2.5).
 *
 * Stocke pour chaque (balise du panel, créneau, horizon, variable) :
 *  - les 3 consensus calculés (A legacy, B amélioré sans fiabilité,
 *    C amélioré avec fiabilité) ;
 *  - l'observation correspondante (lue dans `balise_readings_hourly`) ;
 *  - quelques métadonnées de debug (MAD, nb modèles, nb readings).
 *
 * Alimente l'écran `/admin/reliability/compare`. Rétention 14 jours
 * (alignée sur `forecast_archive_balises`).
 *
 * Cf. FF_model_reliability.md (section phase 2.5).
 */
class BaliseConsensusCompare extends Model
{
    protected $table = 'balise_consensus_compare';

    protected $fillable = [
        'balise_id',
        'target_at',
        'horizon_bucket',
        'variable',
        'consensus_a',
        'consensus_b',
        'consensus_c',
        'observation',
        'observation_count',
        'models_count',
        'mad_value',
        'computed_at',
    ];

    protected $casts = [
        'target_at'         => 'datetime',
        'consensus_a'       => 'decimal:2',
        'consensus_b'       => 'decimal:2',
        'consensus_c'       => 'decimal:2',
        'observation'       => 'decimal:2',
        'observation_count' => 'integer',
        'models_count'      => 'integer',
        'mad_value'         => 'decimal:2',
        'computed_at'       => 'datetime',
    ];

    public function balise(): BelongsTo
    {
        return $this->belongsTo(Balise::class);
    }
}
