<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WeatherStation extends Model
{
    public const NETWORK_MF         = 'mf';
    public const NETWORK_METAR      = 'metar';
    public const NETWORK_INFOCLIMAT = 'infoclimat';

    public const NETWORKS = [
        self::NETWORK_MF         => ['label' => 'Météo-France',  'color' => '#3b82f6'],
        self::NETWORK_METAR      => ['label' => 'METAR',         'color' => '#7c3aed'],
        self::NETWORK_INFOCLIMAT => ['label' => 'Infoclimat',    'color' => '#16a34a'],
    ];

    protected $fillable = [
        'network',
        'external_id',
        'name',
        'latitude',
        'longitude',
        'altitude_m',
        'country_code',
        'country',
        'admin_region',
        'department',
        'active',
        'in_reliability_panel',
        'metadata',
        'last_obs_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude'              => 'float',
            'longitude'             => 'float',
            'altitude_m'            => 'integer',
            'active'                => 'boolean',
            'in_reliability_panel'  => 'boolean',
            'metadata'              => 'array',
            'last_obs_at'           => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeNetwork($query, string $network)
    {
        return $query->where('network', $network);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(WeatherStationObservation::class);
    }

    public function latestObservation(): HasOne
    {
        return $this->hasOne(WeatherStationObservation::class)->latestOfMany('observed_at');
    }

    public function networkLabel(): string
    {
        return self::NETWORKS[$this->network]['label'] ?? $this->network;
    }

    public function networkColor(): string
    {
        return self::NETWORKS[$this->network]['color'] ?? '#6b7280';
    }
}
