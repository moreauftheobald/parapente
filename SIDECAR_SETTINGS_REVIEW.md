# Revue des settings consommés par le sidecar `consensus-grid-v2`

> Audit Laravel du 2026-06-11 (étape 3 de `FF_admin_redesign.md`).
> **Objectif** : pour chaque clé ci-dessous, dire si le sidecar la lit
> réellement. Toute clé que le sidecar ne lit pas (et que Laravel ne
> consomme pas — c'est déjà vérifié) sera **supprimée** de
> `Settings::DEFAULTS` et de l'admin.
>
> Méthode côté Laravel : recherche exhaustive des consommateurs de chacune
> des 74 clés dans `app/`, `routes/`, `config/` (consommation fonctionnelle,
> écrans d'édition exclus). Les clés ci-dessous n'ont **aucun consommateur
> fonctionnel Laravel** (listes A et C) ou un consommateur Laravel + une
> convention partagée avec le sidecar (liste B).

---

## Liste A — clés présumées lues par le sidecar (aucun usage Laravel)

### A1. Consensus global (2)

| Clé | Question pour le sidecar |
|---|---|
| `consensus.global.default_method` | lue ? (méthode A/B par défaut des variables sans config explicite) |
| `consensus.global.preview_enabled` | lue ? (endpoint preview actif ?) |

### A2. Config par variable — `consensus.config.<variable>` (30)

Seule consommation Laravel : `WeatherMapController` lit le champ
`render_tiles` pour construire la whitelist des overlays — le reste de
chaque objet JSON est présumé sidecar.

**Pour chaque variable : la clé est-elle lue ? Et lesquels de ses
SOUS-CHAMPS sont réellement consommés ?**

Sous-champs présents dans les défauts :
`method` · `epsilon` · `z_threshold` · `mad_floor` · `use_mad_filtering` ·
`use_weighted_median` · `use_weight_factor` · `use_bias_correction` ·
`render_tiles`

> Note : `use_weight_factor` et `use_bias_correction` sont à `false`
> partout par défaut — si le sidecar ne les implémente pas encore, les
> conserver quand même (ils sont le levier prévu par
> `FF_grid_reliability.md` pour la pondération par fiabilité).

```
consensus.config.wind_speed_10m          consensus.config.wind_direction_10m
consensus.config.wind_gusts_10m          consensus.config.temperature_2m
consensus.config.relative_humidity_2m    consensus.config.precipitation
consensus.config.cloud_cover_low         consensus.config.cloud_cover_mid
consensus.config.cloud_cover_high        consensus.config.shortwave_radiation
consensus.config.visibility              consensus.config.freezing_level_height
consensus.config.wind_speed_80m          consensus.config.wind_direction_80m
consensus.config.wind_speed_120m         consensus.config.wind_direction_120m
consensus.config.wind_speed_180m         consensus.config.wind_direction_180m
consensus.config.temperature_850hPa      consensus.config.wind_speed_850hPa
consensus.config.wind_direction_850hPa   consensus.config.cloud_cover_850hPa
consensus.config.relative_humidity_850hPa consensus.config.cape
consensus.config.convective_inhibition   consensus.config.convective_precipitation
consensus.config.weather_code            consensus.config.dew_point_2m
consensus.config.qui_vole_storm_risk     consensus.config.qui_vole_cloud_base
```

### A3. Orchestration — `consensus.scheduler.*` (10)

| Clé | Défaut | Question |
|---|---|---|
| `consensus.scheduler.mode` | `cron` | lue ? le mode `event_driven` est-il implémenté ? |
| `consensus.scheduler.cron_minute` | 25 | lue ? |
| `consensus.scheduler.event_debounce_seconds` | — | lue ? (ou réservée phase 3 ?) |
| `consensus.scheduler.safety_net_hours` | — | lue ? |
| `consensus.scheduler.priority_horizon_J` | — | lue ? |
| `consensus.scheduler.priority_horizon_J1` | — | lue ? |
| `consensus.scheduler.priority_horizon_J2` | — | lue ? |
| `consensus.scheduler.priority_horizon_J3` | — | lue ? |
| `consensus.scheduler.priority_horizon_J4` | — | lue ? |
| `consensus.scheduler.priority_derived` | — | lue ? |

---

## Liste B — clés consommées par Laravel, possiblement AUSSI par le sidecar

À confirmer : si le sidecar les lit, toute modification de sémantique
devra rester synchronisée (convention unique, cf. CLAUDE.md point 9).

| Clé | Consommateur Laravel | Question pour le sidecar |
|---|---|---|
| `reliability.min_samples` | `ReliabilityCalculator` (gate cold start) | appliques-tu aussi ce seuil en lisant `model_reliability` ? |
| `reliability.factor_min` / `factor_max` | `ReliabilityCalculator` (clamp) | idem — clamp à la lecture ? |
| `reliability.window_days` | `ReliabilityCalculator` | lue ? |
| `scoring.precip_orange_mmh` / `scoring.precip_red_mmh` | `UserScoringService` | le scoring GLOBAL sidecar lit-il ces seuils (ou sont-ils codés en dur côté sidecar / transmis via `POST /v1/scoring/custom`) ? |
| `scoring.gust_orange_kmh` / `scoring.gust_red_kmh` | `UserScoringService` | idem |

---

## Liste C — déjà tranché côté Laravel (pour information)

### Supprimées (audit 2026-06-11, aucun consommateur nulle part)
- `stations.fetch_enabled` — kill switch jamais branché ; les vrais
  interrupteurs sont `station_apis.active` (par réseau).
- `stations.retention_days` — les rétentions sont des constantes
  délibérées de `PurgeOldForecastsJob` (brut 7 j / horaire 30 j).

### Mourront avec le shadow mode A/B/C (legacy, ne PAS soumettre au sidecar)
`reliability.shadow_enabled` · `reliability.epsilon_new` ·
`reliability.mad_floor` · `reliability.z_outlier_threshold` ·
`reliability.dir_mad_z_threshold` · `reliability.use_weighted_median` ·
`reliability.use_mad_filtering`
(consommées uniquement par `BaliseConsensusCompareService` /
`ConsensusCalculator` — suppression prévue avec la dépréciation du shadow
mode, cf. `FF_grid_reliability.md` étape 5.)

### Saines (consommateur Laravel fonctionnel vérifié)
- `scoring.stale_after_minutes` → `ScoringFreshness` (watchdog)
- `viability.*` (8) → `DayQualityCalculator`
- `quality.*` (4) → `DataQualityService` (doublons)
- `pageviews.retention_days` → `PurgePageViewsJob`
- `windy.api_key` → `WindyOpenDataProvider`

---

## Réponse attendue

Pour les listes A et B : par clé, `lue` / `pas lue` / `réservée à une
phase future identifiée`. Les « pas lues » sans justification seront
supprimées de `Settings::DEFAULTS`, du seeder et des écrans d'admin.
