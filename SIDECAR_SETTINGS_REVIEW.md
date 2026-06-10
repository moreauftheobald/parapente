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

## ✅ VERDICT (réponse sidecar du 2026-06-11) — RÉSOLU

### Lues activement par le sidecar → conservées
- `consensus.config.<variable>` (30) — sous-champs lus : `method`,
  `epsilon`, `z_threshold`, `mad_floor`, `use_mad_filtering`,
  `use_weighted_median`, `render_tiles`.
- Sous-champs `use_weight_factor` / `use_bias_correction` : parsés,
  ignorés (WARNING), réservés méthode C / fiabilité phase 4 → **conservés**.
- `scoring.precip_orange_mmh` / `scoring.precip_red_mmh` /
  `scoring.gust_orange_kmh` / `scoring.gust_red_kmh` — lus par le scoring
  prod sidecar (en plus de `UserScoringService` côté Laravel).
- `scoring_table` — pointeur de buffer (clé système, hors catalogue).

### Pas lues → SUPPRIMÉES (Laravel, 2026-06-11)
- `consensus.global.default_method` / `preview_enabled` (le sidecar a ses
  défauts en dur + override par variable ; l'endpoint preview est
  toujours actif) → clés + UI « Defaults globaux » de l'onglet consensus.
- `consensus.scheduler.*` (10) — le scheduler est un daemon Python
  autonome (env `CONSENSUS_TICK_MINUTE` / `--tick-minute`, fallback 2 h en
  dur) → clés + **onglet « Orchestration » entier** + route + vue.
- Lignes en base purgées par la migration
  `2026_06_11_100000_purge_obsolete_settings_keys` (+ `stations.*`).

### « Pas lues » par le sidecar mais CONSERVÉES (consommateur Laravel)
`reliability.min_samples` / `factor_min` / `factor_max` / `window_days` —
consommées par `ReliabilityCalculator` (fiabilité balises quotidienne) et
socle du futur `ComputeStationReliabilityJob` (FF_grid_reliability).

### ✅ Clés sidecar hors catalogue — INTÉGRÉES (2026-06-11)
Les 9 clés globales lues par le sidecar ont été ajoutées à
`Settings::DEFAULTS` (groupe `consensus_global` recréé, onglet
« Sidecar / consensus » du hub `/admin/settings`), avec les **défauts
exacts du sidecar** (`src/config.py:80-88`) — l'absence de clé en base et
la clé seedée à ces valeurs produisent le même comportement :

| Clé | Type | Défaut |
|---|---|---|
| `consensus.legacy_epsilon` | float | 0.001 |
| `consensus.improved_epsilon` | float | 1.0 |
| `consensus.mad_floor` | float | 0.5 |
| `consensus.mad_z_threshold` | float | 2.5 |
| `consensus.mad_z_threshold_circular` | float | 2.0 |
| `consensus.use_weighted_median` | bool | true |
| `consensus.use_mad_filtering` | bool | true |
| `consensus.min_samples_for_method_C` | int | 50 |
| `consensus.grid_resolution_deg` | float | 0.025 (~2,77 km) |

> ⚠️ Si les défauts changent côté sidecar (`src/config.py`), répercuter
> ici — sinon l'admin affichera de faux « défauts ».
