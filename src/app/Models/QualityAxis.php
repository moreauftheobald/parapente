<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityAxis extends Model
{
    public const AVAILABLE_AXES = [
        'thermal'     => ['label' => 'Thermique (gradient)',    'unit' => '°C/100m', 'min' => 0,   'max' => 1.5],
        'ceiling'     => ['label' => 'Plafond de vol',          'unit' => 'm',       'min' => 0,   'max' => 4000],
        'comfort'     => ['label' => 'Confort / turbulence',    'unit' => '0-100',   'min' => 0,   'max' => 100],
        'visibility'  => ['label' => 'Visibilité',              'unit' => 'km',      'min' => 0,   'max' => 50],
        'cloud_cover' => ['label' => 'Couverture nuageuse',     'unit' => '% inv.',  'min' => 0,   'max' => 100],
        'sunshine'    => ['label' => 'Ensoleillement',           'unit' => '0-100',   'min' => 0,   'max' => 100],
        'temperature' => ['label' => 'Température au sol',      'unit' => '°C',      'min' => -5,  'max' => 40],
    ];

    protected $table = 'quality_axes';

    protected $fillable = [
        'profile_id',
        'axis',
        'weight',
        'scoring_curve',
    ];

    protected $casts = [
        'weight'        => 'integer',
        'scoring_curve' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(QualityProfile::class, 'profile_id');
    }
}
