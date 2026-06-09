<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Site extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'region',
        'latitude',
        'longitude',
        'altitude_m',
        'landing_lat',
        'landing_lng',
        'level',
        'created_by',
        'active',
        'country_code',
        'country',
        'admin_region',
        'department',
        'geocoded_provider',
        'geocoded_at',
    ];

    protected $casts = [
        'latitude'    => 'decimal:7',
        'longitude'   => 'decimal:7',
        'landing_lat' => 'decimal:7',
        'landing_lng' => 'decimal:7',
        'altitude_m'  => 'integer',
        'active'      => 'boolean',
        'geocoded_at' => 'datetime',
    ];

    // ── Boot ────────────────────────────────────────────────────
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Site $site) {
            if (empty($site->slug)) {
                $site->slug = Str::slug($site->name);
            }
        });
    }

    // ── Relations ───────────────────────────────────────────────
    public function conditions(): HasOne
    {
        return $this->hasOne(SiteCondition::class);
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    /**
     * ⚠️ Relation pointant la table template `site_scores` (vide depuis le
     * scoring déporté sidecar). Ne PAS l'utiliser pour lire des scores :
     * passer par SiteScore::onActiveBuffer() (cf. nextScore/upcomingScores).
     * Conservée pour la cohérence du modèle / contraintes éventuelles.
     */
    public function scores(): HasMany
    {
        return $this->hasMany(SiteScore::class);
    }

    public function balises(): HasMany
    {
        return $this->hasMany(Balise::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeRegion($query, string $region)
    {
        return $query->where('region', $region);
    }

    public function scopeLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    // ── Helpers ─────────────────────────────────────────────────

    /** Score du prochain créneau volable (buffer actif du sidecar) */
    public function nextScore(): ?SiteScore
    {
        return SiteScore::onActiveBuffer()
            ->where('site_id', $this->id)
            ->where('forecast_at', '>=', now())
            ->orderBy('forecast_at')
            ->first();
    }

    /** Scores sur les X prochains jours (horizon max : 5 jours), buffer actif */
    public function upcomingScores(int $days = 5)
    {
        return SiteScore::onActiveBuffer()
            ->where('site_id', $this->id)
            ->where('forecast_at', '>=', now()->startOfHour())
            ->where('forecast_at', '<=', now()->addDays($days))
            ->orderBy('forecast_at')
            ->get();
    }
}
