<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Services\Weather\UserScoringService;
use Illuminate\Support\Facades\Auth;
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

        // ── Qualité des données (détection de doublons) ──────────
        'quality.site_dup_distance_m' => [
            'default'     => 200,
            'label'       => 'Sites · distance max entre doublons (m)',
            'description' => "Deux sites séparés de moins de cette distance sont candidats à un signalement de doublon.",
            'group'       => 'quality',
            'type'        => 'int',
        ],
        'quality.site_dup_altitude_m' => [
            'default'     => 30,
            'label'       => 'Sites · écart d\'altitude max entre doublons (m)',
            'description' => "Écart d'altitude (en m) en-deçà duquel deux sites proches sont considérés comme un même décollage.",
            'group'       => 'quality',
            'type'        => 'int',
        ],
        'quality.site_dup_orientation_overlap_pct' => [
            'default'     => 60,
            'label'       => 'Sites · chevauchement d\'orientation min (%)',
            'description' => "Pourcentage minimum de recouvrement angulaire (sur le plus petit des deux arcs wind_dir_min→wind_dir_max) pour considérer l'orientation comme « la même ». Sans site_conditions sur l'un des deux : critère ignoré.",
            'group'       => 'quality',
            'type'        => 'int',
        ],
        'quality.balise_dup_distance_m' => [
            'default'     => 300,
            'label'       => 'Balises · distance max entre doublons (m)',
            'description' => "Deux balises séparées de moins de cette distance sont candidates à un signalement de doublon (tous réseaux confondus ; les paires inter-réseaux sont marquées « info »).",
            'group'       => 'quality',
            'type'        => 'int',
        ],

        // ── Trafic / analytics ───────────────────────────────────
        'pageviews.retention_days' => [
            'default'     => 365,
            'label'       => 'Trafic · rétention (jours)',
            'description' => "Nombre de jours pendant lesquels les pages vues sont conservées en base. Au-delà, le job quotidien `PurgePageViewsJob` les supprime. 365 = comparaison année/année possible.",
            'group'       => 'analytics',
            'type'        => 'int',
        ],

        // ── Sources balises ──────────────────────────────────────
        'windy.api_key' => [
            'default'     => '',
            'label'       => 'Windy.com — clé API',
            'description' => "Clé de la Windy Stations API v2 (https://api.windy.com → API Keys). Indispensable pour découvrir et lire les balises Windy en licence ouverte. Sans clé, le provider Windy est silencieux.",
            'group'       => 'balises',
            'type'        => 'secret',
        ],

        // ── Stations météo (configuration) ───────────────────────
        // Les clés API vivent dans la table `station_apis` (page /admin/station-apis).
        'stations.fetch_enabled' => [
            'default'     => false,
            'label'       => 'Fetch automatique actif',
            'description' => "Active le fetch horaire automatique des observations depuis les 3 réseaux de stations météo (MF, METAR, Infoclimat). Kill switch global.",
            'group'       => 'stations',
            'type'        => 'bool',
        ],
        'stations.retention_days' => [
            'default'     => 30,
            'label'       => 'Rétention des observations (jours)',
            'description' => "Durée de conservation des observations en base. Au-delà, les anciennes observations sont purgées quotidiennement.",
            'group'       => 'stations',
            'type'        => 'int',
        ],

        // ── Fiabilité des modèles (phase 2 + 2.5) ────────────────
        // Cf. FF_model_reliability.md. Tous ces paramètres pilotent
        // soit le calcul de la fiabilité dynamique d'un modèle météo
        // (phase 2), soit les algorithmes de consensus alternatifs
        // évalués en shadow mode (phase 2.5).
        'reliability.shadow_enabled' => [
            'default'     => true,
            'label'       => 'Shadow mode actif (phase 2.5)',
            'description' => "Active le calcul horaire du triple-consensus (A legacy / B amélioré / C amélioré + fiabilité) sur les balises marquées « Panel test fiabilité ». N'affecte pas le scoring de prod — uniquement la table de comparaison admin. Kill switch global.",
            'group'       => 'reliability',
            'type'        => 'bool',
        ],
        'reliability.epsilon_new' => [
            'default'     => 1.0,
            'label'       => 'EPSILON (consensus B et C)',
            'description' => "Constante ajoutée au carré des écarts dans la pondération inverse-carré, pour les consensus B et C uniquement (A reste à 0.001, valeur historique). Une valeur plus grande lisse les écarts faibles et évite qu'un quasi-doublon ne capte un poids démesuré.",
            'group'       => 'reliability',
            'type'        => 'float',
        ],
        'reliability.mad_floor' => [
            'default'     => 0.5,
            'label'       => 'MAD plancher (km/h)',
            'description' => "Valeur plancher de la MAD intra-créneau pour éviter la division par zéro quand tous les modèles convergent. Si la MAD calculée est inférieure, on retient ce plancher.",
            'group'       => 'reliability',
            'type'        => 'float',
        ],
        'reliability.z_outlier_threshold' => [
            'default'     => 3.0,
            'label'       => 'Seuil outlier MAD (vitesse, en MAD)',
            'description' => "Un modèle dont l'écart à la médiane dépasse ce seuil (exprimé en MAD × 1.4826) est exclu du consensus B / C pour les variables de vitesse.",
            'group'       => 'reliability',
            'type'        => 'float',
        ],
        'reliability.dir_mad_z_threshold' => [
            'default'     => 3.0,
            'label'       => 'Seuil outlier MAD (direction, en MAD circulaire)',
            'description' => "Équivalent du seuil ci-dessus pour la direction du vent, exprimé en MAD circulaire (distance angulaire absolue à la médiane circulaire).",
            'group'       => 'reliability',
            'type'        => 'float',
        ],
        'reliability.use_weighted_median' => [
            'default'     => true,
            'label'       => 'Médiane pondérée (consensus B et C)',
            'description' => "Si activé, utilise la médiane pondérée au lieu de la moyenne pondérée pour agréger les modèles dans les consensus B et C. Plus robuste aux outliers résiduels après filtrage MAD.",
            'group'       => 'reliability',
            'type'        => 'bool',
        ],
        'reliability.use_mad_filtering' => [
            'default'     => true,
            'label'       => 'Filtrage MAD (consensus B et C)',
            'description' => "Si activé, filtre les modèles aberrants via la MAD intra-créneau avant agrégation. Décorrélé du switch « médiane pondérée » — on peut activer l'un sans l'autre.",
            'group'       => 'reliability',
            'type'        => 'bool',
        ],
        'reliability.min_samples' => [
            'default'     => 50,
            'label'       => 'Seuil min. d\'échantillons',
            'description' => "Nombre minimal de paires (prévu, observé) accumulées pour qu'un modèle soit considéré comme jugé. En-dessous, son weight_factor reste neutre (= 1.0) et la voting logic le traite comme un modèle inconnu.",
            'group'       => 'reliability',
            'type'        => 'int',
        ],
        'reliability.factor_min' => [
            'default'     => 0.25,
            'label'       => 'Plancher du weight_factor',
            'description' => "Borne basse du multiplicateur de fiabilité appliqué au consensus C. Empêche un modèle d'être complètement réduit au silence — préserve la diversité de l'ensemble.",
            'group'       => 'reliability',
            'type'        => 'float',
        ],
        'reliability.factor_max' => [
            'default'     => 2.0,
            'label'       => 'Plafond du weight_factor',
            'description' => "Borne haute du multiplicateur de fiabilité. Empêche un modèle « chanceux » sur la fenêtre courante de dominer le consensus.",
            'group'       => 'reliability',
            'type'        => 'float',
        ],
        'reliability.window_days' => [
            'default'     => 7,
            'label'       => 'Fenêtre glissante (jours)',
            'description' => "Profondeur d'historique utilisée pour calculer la fiabilité d'un modèle. Aligné par défaut sur la rétention de balise_readings_hourly.",
            'group'       => 'reliability',
            'type'        => 'int',
        ],

        // ── Consensus sidecar — defaults globaux ─────────────────
        'consensus.global.default_method' => [
            'default'     => 'B',
            'label'       => 'Méthode de consensus par défaut',
            'description' => "Méthode appliquée aux variables qui n'ont pas de config explicite (A = legacy inverse-carré, B = amélioré MAD/médiane). Le sidecar lit cette valeur à chaque run.",
            'group'       => 'consensus_global',
            'type'        => 'string',
        ],
        'consensus.global.preview_enabled' => [
            'default'     => false,
            'label'       => 'Endpoint preview actif',
            'description' => "Active l'endpoint /v1/consensus/preview côté sidecar (debug uniquement).",
            'group'       => 'consensus_global',
            'type'        => 'bool',
        ],

        // ── Consensus sidecar — config par variable ──────────────
        // Chaque clé `consensus.config.<variable>` est un objet JSON
        // avec la méthode de consensus et ses options.
        // Sol (12)
        'consensus.config.wind_speed_10m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Vent moyen 10 m',
            'description' => "Config consensus pour wind_speed_10m.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_direction_10m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 5.0, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Direction vent 10 m',
            'description' => "Config consensus pour wind_direction_10m (circulaire, mad_floor en degrés).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_gusts_10m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Rafales 10 m',
            'description' => "Config consensus pour wind_gusts_10m.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.temperature_2m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 2.5, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Température 2 m',
            'description' => "Config consensus pour temperature_2m.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.relative_humidity_2m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 2.5, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Humidité relative 2 m',
            'description' => "Config consensus pour relative_humidity_2m.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.precipitation' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => false, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Précipitations',
            'description' => "Config consensus pour precipitation. MAD désactivé par défaut (divergence légitime entre modèles sur la pluie).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.cloud_cover_low' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Couverture nuageuse basse',
            'description' => "Config consensus pour cloud_cover_low.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.cloud_cover_mid' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Couverture nuageuse moyenne',
            'description' => "Config consensus pour cloud_cover_mid.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.cloud_cover_high' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Couverture nuageuse haute',
            'description' => "Config consensus pour cloud_cover_high.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.shortwave_radiation' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Rayonnement solaire',
            'description' => "Config consensus pour shortwave_radiation (W/m²).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.visibility' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Visibilité',
            'description' => "Config consensus pour visibility (m).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.freezing_level_height' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Isotherme 0 °C',
            'description' => "Config consensus pour freezing_level_height (m AMSL).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        // Vent en altitude AGL (6)
        'consensus.config.wind_speed_80m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Vent 80 m',
            'description' => "Config consensus pour wind_speed_80m.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_direction_80m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 5.0, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Direction vent 80 m',
            'description' => "Config consensus pour wind_direction_80m (circulaire).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_speed_120m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Vent 120 m',
            'description' => "Config consensus pour wind_speed_120m.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_direction_120m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 5.0, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Direction vent 120 m',
            'description' => "Config consensus pour wind_direction_120m (circulaire).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_speed_180m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Vent 180 m',
            'description' => "Config consensus pour wind_speed_180m.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_direction_180m' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 5.0, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Direction vent 180 m',
            'description' => "Config consensus pour wind_direction_180m (circulaire).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        // Niveau 850 hPa (5)
        'consensus.config.temperature_850hPa' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 2.5, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Température 850 hPa',
            'description' => "Config consensus pour temperature_850hPa.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_speed_850hPa' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Vent 850 hPa',
            'description' => "Config consensus pour wind_speed_850hPa.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.wind_direction_850hPa' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 5.0, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Direction vent 850 hPa',
            'description' => "Config consensus pour wind_direction_850hPa (circulaire).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.cloud_cover_850hPa' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Couverture nuageuse 850 hPa',
            'description' => "Config consensus pour cloud_cover_850hPa.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.relative_humidity_850hPa' => [
            'default'     => ['method' => 'B', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 2.5, 'mad_floor' => 0.5, 'use_mad_filtering' => true, 'use_weighted_median' => true, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Humidité relative 850 hPa',
            'description' => "Config consensus pour relative_humidity_850hPa.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        // Instabilité convective (3)
        'consensus.config.cape' => [
            'default'     => ['method' => 'A', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => false, 'use_weighted_median' => false, 'epsilon' => 1.0, 'render_tiles' => false],
            'label'       => 'CAPE',
            'description' => "Config consensus pour cape. Méthode A par défaut (forte dispersion légitime).",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.convective_inhibition' => [
            'default'     => ['method' => 'A', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => false, 'use_weighted_median' => false, 'epsilon' => 1.0, 'render_tiles' => false],
            'label'       => 'CIN (inhibition convective)',
            'description' => "Config consensus pour convective_inhibition. Méthode A par défaut.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.convective_precipitation' => [
            'default'     => ['method' => 'A', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => false, 'use_weighted_median' => false, 'epsilon' => 1.0, 'render_tiles' => false],
            'label'       => 'Précipitations convectives',
            'description' => "Config consensus pour convective_precipitation. Méthode A par défaut.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        // Pipeline dédié (1) — seul render_tiles est pris en compte
        'consensus.config.weather_code' => [
            'default'     => ['method' => 'vote', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => false, 'use_weighted_median' => false, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Code météo WMO',
            'description' => "Config consensus pour weather_code. Pipeline dédié (vote de mode WMO) — seul render_tiles est pris en compte par le sidecar.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        // Dérivées Qui-Vole post-pipeline (3) — seul render_tiles est pris en compte
        'consensus.config.dew_point_2m' => [
            'default'     => ['method' => 'derived', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => false, 'use_weighted_median' => false, 'epsilon' => 0.001, 'render_tiles' => false],
            'label'       => 'Point de rosée 2 m',
            'description' => "Dérivée de temperature_2m + relative_humidity_2m (Magnus-Tetens). Seul render_tiles est pris en compte.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.qui_vole_storm_risk' => [
            'default'     => ['method' => 'derived', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => false, 'use_weighted_median' => false, 'epsilon' => 0.001, 'render_tiles' => true],
            'label'       => 'Risque orageux Qui-Vole',
            'description' => "Dérivée de CAPE, CIN, LPI, cloud_cover, conv_precip, weather_code (vote pondéré, catégoriel 0-3). Seul render_tiles est pris en compte.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],
        'consensus.config.qui_vole_cloud_base' => [
            'default'     => ['method' => 'derived', 'use_weight_factor' => false, 'use_bias_correction' => false, 'z_threshold' => 3.0, 'mad_floor' => 0.5, 'use_mad_filtering' => false, 'use_weighted_median' => false, 'epsilon' => 0.001, 'render_tiles' => true],
            'label'       => 'Plafond de vol Qui-Vole',
            'description' => "Dérivée de temperature_2m + dew_point_2m + DEM Copernicus (formule d'Espy). Seul render_tiles est pris en compte.",
            'group'       => 'consensus_vars',
            'type'        => 'json',
        ],

        // ── Orchestration du sidecar ────────────────────────────
        'consensus.scheduler.mode' => [
            'default'     => 'cron',
            'label'       => 'Mode de scheduling',
            'description' => "Mode d'orchestration du sidecar : « cron » (horaire classique) ou « event_driven » (déclenché par les mises à jour Open-Meteo). Le mode event-driven sera activable quand la phase 3 sera livrée.",
            'group'       => 'consensus_scheduler',
            'type'        => 'string',
        ],
        'consensus.scheduler.cron_minute' => [
            'default'     => 25,
            'label'       => 'Minute du cron horaire',
            'description' => "Minute à laquelle le run consensus se déclenche en mode cron (0-59).",
            'group'       => 'consensus_scheduler',
            'type'        => 'int',
        ],
        'consensus.scheduler.event_debounce_seconds' => [
            'default'     => 30,
            'label'       => 'Debounce event-driven (s)',
            'description' => "Délai d'attente après détection d'un event Open-Meteo avant de lancer le run (coalescing, évite les runs en rafale).",
            'group'       => 'consensus_scheduler',
            'type'        => 'int',
        ],
        'consensus.scheduler.safety_net_hours' => [
            'default'     => 6,
            'label'       => 'Filet de sécurité (h)',
            'description' => "En mode event-driven, force un run global si rien n'a tourné depuis X heures.",
            'group'       => 'consensus_scheduler',
            'type'        => 'int',
        ],
        'consensus.scheduler.priority_horizon_J' => [
            'default'     => 100,
            'label'       => 'Priorité J (aujourd\'hui)',
            'description' => "Priorité du run pour l'horizon J (plus haut = plus prioritaire, échelle 0-100).",
            'group'       => 'consensus_scheduler',
            'type'        => 'int',
        ],
        'consensus.scheduler.priority_horizon_J1' => [
            'default'     => 80,
            'label'       => 'Priorité J+1',
            'description' => "Priorité du run pour l'horizon J+1.",
            'group'       => 'consensus_scheduler',
            'type'        => 'int',
        ],
        'consensus.scheduler.priority_horizon_J2' => [
            'default'     => 60,
            'label'       => 'Priorité J+2',
            'description' => "Priorité du run pour l'horizon J+2.",
            'group'       => 'consensus_scheduler',
            'type'        => 'int',
        ],
        'consensus.scheduler.priority_horizon_J3' => [
            'default'     => 40,
            'label'       => 'Priorité J+3',
            'description' => "Priorité du run pour l'horizon J+3.",
            'group'       => 'consensus_scheduler',
            'type'        => 'int',
        ],
        'consensus.scheduler.priority_horizon_J4' => [
            'default'     => 20,
            'label'       => 'Priorité J+4',
            'description' => "Priorité du run pour l'horizon J+4.",
            'group'       => 'consensus_scheduler',
            'type'        => 'int',
        ],
        'consensus.scheduler.priority_derived' => [
            'default'     => 10,
            'label'       => 'Priorité variables dérivées',
            'description' => "Poids appliqué aux jobs de variables dérivées (cloud_base, dew_point…) relatif à leur source.",
            'group'       => 'consensus_scheduler',
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
        $oldValue = $this->get($key);
        $meta = self::DEFAULTS[$key] ?? null;
        Setting::updateOrCreate(
            ['key' => $key],
            [
                'value'       => $value,
                'label'       => $meta['label']       ?? null,
                'description' => $meta['description'] ?? null,
            ]
        );
        $this->audit($key, $oldValue, $value);
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
        $oldValues = $this->all();
        DB::transaction(function () use ($values, $oldValues) {
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
                $old = $oldValues[$key] ?? (self::DEFAULTS[$key]['default'] ?? null);
                $this->audit($key, $old, $value);
            }
        });
        $this->flush();
    }

    private function audit(string $key, mixed $oldValue, mixed $newValue): void
    {
        $encode = fn (mixed $v): ?string => $v === null ? null : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
        $oldStr = $encode($oldValue);
        $newStr = $encode($newValue);
        if ($oldStr === $newStr) {
            return;
        }
        try {
            SettingsAudit::create([
                'setting_key'        => $key,
                'old_value'          => $oldStr,
                'new_value'          => $newStr,
                'changed_by_user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Table might not exist yet during migration
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);

        // Les seuils globaux (precip / gust) sont envoyés au sidecar dans le
        // payload de scoring perso. À chaque écriture, on purge donc tous les
        // caches user-scoring. Résolution paresseuse pour éviter la dépendance
        // circulaire à la construction (UserScoringService → Settings).
        try {
            app(UserScoringService::class)->invalidateAll();
        } catch (\Throwable) {
            // Si le service ne peut pas être résolu (ex: tests qui se
            // passent du conteneur complet), on laisse passer — la prochaine
            // expiration TTL absorbera de toute façon.
        }
    }
}
