<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SiteScore extends Model
{
    protected $fillable = [
        'site_id',
        'forecast_at',
        'computed_at',
        'status',
        'confidence_pct',
        'wind_dir_consensus',
        'wind_speed_consensus',
        'wind_gust_consensus',
        'precip_consensus',
        'cloud_base_consensus',
        'models_count',
        'models_converging',
        'detail',
        'quality_detail',
    ];

    protected $casts = [
        'forecast_at'          => 'datetime',
        'computed_at'          => 'datetime',
        'confidence_pct'       => 'integer',
        'wind_dir_consensus'   => 'integer',
        'wind_speed_consensus' => 'decimal:1',
        'wind_gust_consensus'  => 'decimal:1',
        'precip_consensus'     => 'decimal:1',
        'cloud_base_consensus' => 'integer',
        'models_count'         => 'integer',
        'models_converging'    => 'integer',
        'detail'               => 'array',   // JSON auto-décodé
        'quality_detail'       => 'array',   // scores qualité par profil (sidecar) — lecture brute
    ];

    // ── Double-buffer (sidecar consensus-grid-v2) ───────────────
    //
    // Les scores sont écrits par le sidecar dans `site_scores_1` /
    // `site_scores_2` ; le setting `scoring_table` désigne le buffer
    // actif. Laravel lit TOUJOURS via le buffer actif — la table par
    // défaut `site_scores` n'est plus qu'un template DDL vide.

    /**
     * Nom de la table de scoring active, lue en SQL direct depuis le
     * setting `scoring_table` (on bypasse volontairement le service
     * Settings et son cache Redis 1 h : le sidecar flippe le pointeur en
     * SQL direct, le cache ne verrait pas le changement à temps).
     */
    public static function activeTableName(): string
    {
        $raw = DB::table('settings')->where('key', 'scoring_table')->value('value');
        $n   = (int) trim((string) $raw, "\" \t\n\r");

        return 'site_scores_' . ($n === 2 ? 2 : 1);
    }

    /**
     * Démarre une requête Eloquent SiteScore ciblant le buffer actif.
     * Tous les lecteurs de scores doivent passer par ici plutôt que par
     * `SiteScore::query()` (qui taperait la table template vide).
     */
    public static function onActiveBuffer(): Builder
    {
        return (new static())->setTable(static::activeTableName())->newQuery();
    }

    // ── Relations ───────────────────────────────────────────────
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    // ── Scopes ──────────────────────────────────────────────────
    public function scopeGreen($query)
    {
        return $query->where('status', 'green');
    }

    public function scopeUpcoming($query, int $days = 5)
    {
        return $query
            ->where('forecast_at', '>=', now()->startOfHour())
            ->where('forecast_at', '<=', now()->addDays($days));
    }

    public function scopeForDay($query, \DateTimeInterface $day)
    {
        return $query
            ->whereDate('forecast_at', $day);
    }

    // ── Helpers ─────────────────────────────────────────────────

    public function isGreen(): bool
    {
        return $this->status === 'green';
    }

    public function isOrange(): bool
    {
        return $this->status === 'orange';
    }

    public function isRed(): bool
    {
        return $this->status === 'red';
    }
}
