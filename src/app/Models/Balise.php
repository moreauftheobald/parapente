<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * FUTURE USE — Balises temps réel PiouPiou / FFVL.
 * Modèle préparé mais non utilisé tant que l'intégration des balises
 * n'est pas implémentée (cf. CLAUDE.md, modules futurs).
 */
class Balise extends Model
{
    protected $fillable = [
        'site_id',
        'source',
        'external_id',
        'name',
        'latitude',
        'longitude',
        'altitude_m',
        'active',
    ];

    protected $casts = [
        'latitude'   => 'decimal:7',
        'longitude'  => 'decimal:7',
        'altitude_m' => 'integer',
        'active'     => 'boolean',
    ];

    // ── Relations ───────────────────────────────────────────────
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function readings(): HasMany
    {
        return $this->hasMany(BaliseReading::class);
    }

    public function latestReading(): HasOne
    {
        return $this->hasOne(BaliseReading::class)
            ->latestOfMany('read_at');
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Relevés sur la dernière heure (pour comparaison avec forecast horaire).
     */
    public function lastHourReadings()
    {
        return $this->readings()
            ->where('read_at', '>=', now()->subHour())
            ->orderBy('read_at')
            ->get();
    }
}
