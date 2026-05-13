<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Services\Weather\UserScoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Accès aux paramètres globaux (table `settings`) avec cache.
 *
 * Toute valeur lue plus d'une fois par requête HTTP / par job passe par
 * ici plutôt que par le modèle directement, pour bénéficier du cache.
 *
 * Catalogue des défauts (`DEFAULTS`) : sert à la fois de fallback si la
 * clé n'est pas en base et de référence pour le SettingsSeeder + l'écran
 * admin (libellés et descriptions).
 */
class Settings
{
    private const CACHE_KEY = 'app:settings:all';
    private const CACHE_TTL = 3600; // 1 h — invalidation explicite à chaque set()

    /**
     * Catalogue complet des paramètres : clé → [default, label, description, group, type].
     *
     * - group  : section dans l'écran admin (precip / gust / viability)
     * - type   : indication de saisie (number, float) — pour le rendu admin
     */
    public const DEFAULTS = [
        // ── Précipitations (consensus multi-modèles) ─────────────
        'scoring.precip_orange_mmh' => [
            'default'     => 0.0,
            'label'       => 'Pluie · seuil orange (mm/h)',
            'description' => "Consensus de précipitations au-delà duquel le créneau passe en orange. Mettre 0 pour que toute trace de pluie consensus déclenche l'orange.",
            'group'       => 'precip',
            'type'        => 'float',
        ],
        'scoring.precip_red_mmh' => [
            'default'     => 0.1,
            'label'       => 'Pluie · seuil rouge (mm/h)',
            'description' => "Consensus de précipitations au-delà duquel le créneau passe en rouge (éliminatoire).",
            'group'       => 'precip',
            'type'        => 'float',
        ],

        // ── Rafales (consensus de wind_speed_max) ────────────────
        'scoring.gust_orange_kmh' => [
            'default'     => 25.0,
            'label'       => 'Rafales · seuil orange (km/h)',
            'description' => "Rafales consensus à partir desquelles le créneau passe en orange. Surchargeable par site (fiche site).",
            'group'       => 'gust',
            'type'        => 'float',
        ],
        'scoring.gust_red_kmh' => [
            'default'     => 35.0,
            'label'       => 'Rafales · seuil rouge (km/h)',
            'description' => "Rafales consensus au-delà desquelles le créneau passe en rouge. Surchargeable par site.",
            'group'       => 'gust',
            'type'        => 'float',
        ],

        // ── Viabilité d'une journée (cloche horaire + run factor) ─
        'viability.peak_hour' => [
            'default'     => 13.5,
            'label'       => 'Heure de pic (cloche horaire)',
            'description' => "Heure de la journée à laquelle un créneau volable pèse le plus dans la viabilité (centre de la cloche).",
            'group'       => 'viability',
            'type'        => 'float',
        ],
        'viability.sigma' => [
            'default'     => 4.0,
            'label'       => 'Largeur de cloche σ (h)',
            'description' => "Écart-type de la cloche horaire : plus σ est petit, plus les créneaux du milieu de journée dominent.",
            'group'       => 'viability',
            'type'        => 'float',
        ],
        'viability.val_green' => [
            'default'     => 1.0,
            'label'       => 'Poids d\'un créneau vert',
            'description' => "Contribution d'un créneau « vert » dans le score de viabilité (1.0 = poids plein).",
            'group'       => 'viability',
            'type'        => 'float',
        ],
        'viability.val_orange' => [
            'default'     => 0.40,
            'label'       => 'Poids d\'un créneau orange',
            'description' => "Contribution d'un créneau « orange » (prudence) dans le score de viabilité.",
            'group'       => 'viability',
            'type'        => 'float',
        ],
        'viability.run_base' => [
            'default'     => 0.40,
            'label'       => 'Facteur de continuité · base',
            'description' => "Contribution d'un créneau volable isolé (1 h seule).",
            'group'       => 'viability',
            'type'        => 'float',
        ],
        'viability.run_step' => [
            'default'     => 0.30,
            'label'       => 'Facteur de continuité · pas',
            'description' => "Gain par heure consécutive supplémentaire (plafonné à 1.0 → run ≥ 3 h).",
            'group'       => 'viability',
            'type'        => 'float',
        ],
        'viability.green_threshold' => [
            'default'     => 35,
            'label'       => 'Seuil jour vert (viabilité)',
            'description' => "Viabilité 0-100 à partir de laquelle un jour est qualifié de « vert ».",
            'group'       => 'viability',
            'type'        => 'int',
        ],
        'viability.orange_threshold' => [
            'default'     => 12,
            'label'       => 'Seuil jour orange (viabilité)',
            'description' => "Viabilité 0-100 à partir de laquelle un jour est qualifié d'« orange » (sinon rouge).",
            'group'       => 'viability',
            'type'        => 'int',
        ],
    ];

    /**
     * Récupère la valeur d'un paramètre, ou son défaut si absente.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        if ($default !== null) {
            return $default;
        }
        return self::DEFAULTS[$key]['default'] ?? null;
    }

    /**
     * Récupère toutes les valeurs (clé => valeur), en fusionnant défauts
     * et valeurs persistées. Cache Redis sur la durée de vie configurée.
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $stored = Setting::query()->pluck('value', 'key')->all();
            $out = [];
            foreach (self::DEFAULTS as $k => $meta) {
                $out[$k] = array_key_exists($k, $stored) ? $stored[$k] : $meta['default'];
            }
            // valeurs orphelines (clé en base sans entrée DEFAULTS) : on les
            // laisse traverser au cas où — utile pour les évolutions futures.
            foreach ($stored as $k => $v) {
                if (! array_key_exists($k, $out)) {
                    $out[$k] = $v;
                }
            }
            return $out;
        });
    }

    /**
     * Persiste une valeur et invalide le cache.
     */
    public function set(string $key, mixed $value): void
    {
        $meta = self::DEFAULTS[$key] ?? null;
        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value'       => $value,
                'label'       => $meta['label']       ?? null,
                'description' => $meta['description'] ?? null,
            ]
        );
        $this->flush();
    }

    /**
     * Persiste plusieurs valeurs en une seule transaction (utilisé par
     * l'écran admin pour enregistrer le formulaire entier).
     *
     * @param array<string,mixed> $values
     */
    public function setMany(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                $meta = self::DEFAULTS[$key] ?? null;
                Setting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value'       => $value,
                        'label'       => $meta['label']       ?? null,
                        'description' => $meta['description'] ?? null,
                    ]
                );
            }
        });
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);

        // Les seuils globaux (precip / gust / viability) entrent dans le
        // calcul du scoring perso. À chaque écriture, on purge donc tous
        // les caches user-scoring. Résolution paresseuse pour éviter la
        // dépendance circulaire à la construction (UserScoringService →
        // ScoringService → Settings).
        try {
            app(UserScoringService::class)->invalidateAll();
        } catch (\Throwable) {
            // Si le service ne peut pas être résolu (ex: tests qui se
            // passent du conteneur complet), on laisse passer — la prochaine
            // expiration TTL absorbera de toute façon.
        }
    }
}
