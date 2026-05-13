<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\FlyingConditions;
use App\Models\Concerns\HasFlyingConditions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conditions de vol personnelles d'un utilisateur sur un site donné.
 *
 * Cf. FF_personnal_scoring.md — un user a au maximum
 * `UserSiteCondition::MAX_ACTIVE` scorings actifs simultanément (LRU
 * automatique sur `activated_at`) et `UserSiteCondition::MAX_STORED`
 * scorings stockés au total (actifs + dormants).
 */
class UserSiteCondition extends Model implements FlyingConditions
{
    use HasFlyingConditions;

    public const MAX_ACTIVE = 10;
    public const MAX_STORED = 50;

    protected $fillable = [
        'user_id',
        'site_id',
        'is_active',
        'activated_at',
        'wind_dir_min',
        'wind_dir_max',
        'wind_speed_min',
        'wind_speed_max',
        'wind_speed_ideal',
        'wind_gust_orange_kmh',
        'wind_gust_red_kmh',
        'cloud_base_min_m',
        'cloud_cover_low_max',
        'notes',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'activated_at'         => 'datetime',
        'wind_dir_min'         => 'integer',
        'wind_dir_max'         => 'integer',
        'wind_speed_min'       => 'integer',
        'wind_speed_max'       => 'integer',
        'wind_speed_ideal'     => 'integer',
        'wind_gust_orange_kmh' => 'decimal:1',
        'wind_gust_red_kmh'    => 'decimal:1',
        'cloud_base_min_m'     => 'integer',
        'cloud_cover_low_max'  => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser(Builder $query, int|User $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }
}
