# CLAUDE.md — Instructions pour Claude Code

## Présentation du projet

**Qui Vole ?** (anciennement « ParapenteFR ») est une plateforme web nationale dédiée aux
pilotes de parapente, construite en Laravel. Le projet est modulaire : chaque grande
fonctionnalité est un module indépendant (table `modules`, visibilité par rôle).

Le cœur du système est une **carte de volabilité** : pour chaque site de vol, un
sidecar Python (`consensus-grid-v2`, repo séparé) calcule un consensus multi-modèles
et un scoring de volabilité qu'il écrit directement en base. Laravel orchestre la
collecte de données (prévisions, observations balises et stations météo), sert les
APIs/vues, et calcule la **fiabilité des modèles météo** par comparaison
prévisions ↔ observations.

---

## Changelog (IMPORTANT)

Un fichier **`CHANGELOG.md`** est maintenu à la racine du dépôt. Il retrace les
**évolutions majeures** du code (nouvelles fonctionnalités, changements
d'architecture, modifications de base de données, ruptures de compatibilité) —
**en français**, la plus récente en haut.

- Après **tout changement majeur**, proposer à l'utilisateur de mettre à jour
  `CHANGELOG.md` (lui demander avant de l'éditer).
- Ne pas y consigner chaque commit : seulement ce qui mérite d'être retenu.
- Format des dates : `AAAA-MM-JJ`.

---

## Notes de fonctionnalités futures — `FF_*.md` (IMPORTANT)

Lorsqu'une fonctionnalité future est évoquée et discutée (cadrage, choix
d'architecture, estimation) sans être encore implémentée, on consigne la
discussion dans un fichier **`FF_<nom>.md`** à la racine du dépôt (FF =
*Futur Feature*). En français, structuré, suffisamment complet pour reprendre
le sujet plus tard sans tout réfléchir à nouveau : concept, modèle de données,
choix d'architecture (alternatives écartées et justification), bornes /
garde-fous, estimation grossière, ordre de découpage, risques.

FF actuellement présents : `FF_grid_reliability.md` (**objectif en cours** —
fiabilité par maille via stations, cf. section Fiabilité), `FF_model_reliability.md`
(phase 2.5, implémentée côté balises), `FF_personnal_scoring_sidecar.md` (implémenté),
`FF_location_enrichment.md` (implémenté), `FF_Iam_Here.md`, `FF_accessibility_palette.md`.

---

## Convention de branches Git

- **Branches conservées en permanence** :
  - branches **majeures** : `V1`, `V2`, `V3`, `V4`… (une par grande version) ;
  - branches **versionnées** : `V1.1`, `V2.1`… (incréments d'une version).
  - Ne **jamais** supprimer ces branches.
- Les branches de travail temporaires (`feature/*`, `fix/*`, `claude/*`) sont
  jetables une fois fusionnées dans la branche de version correspondante.
- Le travail courant se fait sur la branche de version active (actuellement **`V4`**).

---

## Stack technique

| Composant       | Technologie                         |
|-----------------|-------------------------------------|
| Serveur         | Nginx 1.26                          |
| Backend         | PHP 8.4 / Laravel 13                |
| Base de données | MariaDB 10.11                       |
| Cache / Queue   | Redis 7                             |
| Frontend        | Tailwind 4 + Alpine.js (CSS inline pour la carte) |
| Carte           | Leaflet.js (+ MarkerCluster pour les stations)    |
| Positionnement flottant | `@floating-ui/dom` (dispo, migration progressive) |
| Build assets    | Vite                                |
| Conteneurs      | Docker / Docker Compose             |
| Sidecar consensus/scoring | `consensus-grid-v2` (Python, repo séparé) |
| Serveur prévisions | Open-Meteo self-hosted (+ API publique pour UKMO) |

---

## Vue d'ensemble des flux de données

```
                    ┌────────────── APIs externes ───────────────┐
                    │ Open-Meteo (self-hosted + public)          │
                    │ PiouPiou · Windy · METAR/NOAA ·            │
                    │ Météo-France (OAuth2) · Infoclimat (StatIC)│
                    └────────────────────┬───────────────────────┘
                                         │ jobs schedulés Laravel
        ┌────────────────────────────────┼────────────────────────────────┐
        ▼                                ▼                                ▼
  forecasts (sites, par modèle,   balise_readings (brut 7j)      weather_station_observations
  rétention J-1 ; multimodèle)    → balise_readings_hourly 30j   (brut 7j) → *_hourly 30j
        │                                │                                │
        │       forecast_archive_balises / forecast_archive_stations      │
        │       (par modèle × bucket, consensus inclus, 30 j)             │
        │                                └────────────┬───────────────────┘
        │                                             ▼
        │                     Fiabilité : model_reliability (balises, legacy)
        │                     → objectif : par STATION puis par MAILLE
        │                       (FF_grid_reliability.md, rayon 25 km)
        ▼
  sidecar consensus-grid-v2 ──► site_scores_1/2 (double-buffer, flip scoring_table)
                            ──► overlays PNG carte météo (/carte-meteo)
                            ──► lit `settings` (consensus.config.*, scheduler, seuils)
```

Principes structurants :
- **Toutes les données météo viennent d'APIs** (internes ou externes) — aucun calcul
  de prévision côté Laravel.
- **Le scoring des sites est calculé par le sidecar** et poussé directement en base
  (double-buffer `site_scores_1`/`site_scores_2`). Laravel est lecteur pur.
- Le **consensus** est lui aussi produit par le sidecar ; Laravel l'archive aux
  coordonnées des balises et des stations comme un pseudo-modèle
  (`qui_vole_consensus`) pour le confronter aux observations.
- Les prévisions par modèle ne sont archivées **par bucket d'horizon**
  (nowcast / same_day / j_plus_1 / j_plus_2) que pour les **balises et stations**
  (calcul de fiabilité) — pas pour les sites (la table `forecasts` est une table de
  travail courte durée pour le panel multimodèles).

---

## Architecture du projet (arborescence commentée)

```
src/                        ← Racine Laravel
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── SiteController.php          ← /api/sites (+scores/chart/multimodel)
│   │   │   │   ├── BaliseController.php        ← /api/balises (+history/comparison)
│   │   │   │   ├── WeatherStationController.php ← /api/weather-stations (+detail/comparison)
│   │   │   │   ├── MapBundleController.php     ← /api/map-bundle (boot carte)
│   │   │   │   ├── MeScoringController.php     ← overlay scoring perso (auth)
│   │   │   │   ├── MeHiddenSitesController.php ← overlay sites masqués (auth)
│   │   │   │   ├── UserScoringController.php   ← CRUD scorings perso
│   │   │   │   └── UserHiddenSiteController.php ← CRUD sites masqués
│   │   │   ├── Admin/                          ← BackOffice (cf. section BackOffice)
│   │   │   ├── User/                           ← Profil, scorings, sites masqués (pages)
│   │   │   ├── Auth/                           ← Login / Register front
│   │   │   ├── HomeController.php              ← Accueil (articles + épinglé)
│   │   │   ├── MapController.php               ← Carte de volabilité (/carte)
│   │   │   ├── WeatherMapController.php        ← Carte météo (/carte-meteo, proxy sidecar)
│   │   │   ├── ModelGridController.php         ← Carte des modèles (/carte-modeles, admin)
│   │   │   ├── WikiController.php              ← Aide en ligne (/aide)
│   │   │   └── IconCacheController.php         ← Tampon disque icônes SpotAir (site/balise/station)
│   │   ├── Middleware/RecordPageView.php       ← Tracking trafic RGPD-friendly (page_views)
│   │   ├── Requests/Api/                       ← Validation scorings perso (ScoringRules = catalogue de règles)
│   │   └── Controllers/Concerns/HasFilterableIndex.php ← search/sort whitelist admin
│   ├── Models/                                 ← cf. section Base de données
│   ├── Support/Navigation.php                  ← Modules visibles (navbar)
│   ├── Services/
│   │   ├── Weather/
│   │   │   ├── Apis/OpenMeteoApi.php           ← fetch sites + batchs balises/stations
│   │   │   ├── ForecastFetcher.php             ← orchestrateur fetch (site, modèle)
│   │   │   ├── CustomScoringClient.php         ← POST /v1/scoring/custom (sidecar)
│   │   │   ├── UserScoringService.php          ← overlay scoring perso (cache Redis/user)
│   │   │   ├── Site{Scores,Chart,Multimodel}PayloadBuilder.php
│   │   │   └── Reliability/                    ← cf. section Fiabilité
│   │   │       ├── ConsensusCalculator.php          (A/B/C, linéaire + circulaire — legacy shadow)
│   │   │       ├── ReliabilityCalculator.php        (recomputeForBalise + weightFactorsBatch)
│   │   │       ├── BaliseConsensusCompareService.php (triple-consensus — legacy shadow)
│   │   │       └── ReliabilityExportService.php     (CSV/JSON analyse externe)
│   │   ├── Balises/                            ← PiouPiouProvider, WindyOpenDataProvider,
│   │   │                                          BaliseProviderInterface, constantes, formatter
│   │   ├── Stations/                           ← StationProviderInterface +
│   │   │                                          MfStationProvider (OAuth2, infrahoraire 6 min),
│   │   │                                          MetarStationProvider (NOAA),
│   │   │                                          InfoclimatStationProvider (StatIC, batch 50)
│   │   ├── Geocoding/                          ← geo.api.gouv.fr (FR) + Nominatim (monde)
│   │   ├── Map/
│   │   │   ├── MapBundleBuilder.php            (bundle /api/map-bundle, summarizeDays statique)
│   │   │   ├── SiteDetailCache.php             (cache scores/chart/multimodel)
│   │   │   ├── BalisesBundleCache.php          (cache /api/balises + history)
│   │   │   ├── ComparisonSeriesBuilder.php     (séries mesures vs consensus, balise + station)
│   │   │   ├── DayQualityCalculator.php / SunWindowCalculator.php
│   │   │   ├── ScoringFreshness.php            (watchdog péremption scoring sidecar)
│   │   │   └── ModelGridBuilder.php            (grille NWP GeoJSON, /carte-modeles)
│   │   ├── DataCoverage.php                    ← couverture données (6 sections, cache 5 min)
│   │   └── Settings.php                        ← paramètres globaux (cf. section Settings)
│   ├── Jobs/                                   ← cf. section Jobs & scheduler
│   │   └── Concerns/TracksExecution.php        ← suivi JobMonitor (trackStart/Success/Failure)
│   └── Observers/
│       ├── GeocodableObserver.php              ← géocode auto Site/Balise (création/modif coords)
│       ├── MapBundleInvalidationObserver.php   ← rebuild bundle (debounce 5 s + ShouldBeUnique 30 s)
│       └── SiteActivationObserver.php          ← fetch forecasts à l'activation (pas de scoring)
├── config/weather.php                          ← palette MODEL_COLORS des modèles NWP
├── database/{migrations,seeders}/
├── resources/views/
│   ├── components/app-shell.blade.php          ← shell global <x-app-shell>
│   ├── components/admin/                       ← composants BackOffice standardisés
│   ├── partials/app-shell-navbar.blade.php
│   ├── layouts/admin.blade.php
│   ├── map/index.blade.php + map/_partials/{html,scripts,styles}/ (~30 partials)
│   ├── weather-map/ · model-grid/ · user/ · wiki/ · auth/ · home.blade.php
│   └── admin/                                  ← ~22 dossiers (un par section BackOffice)
├── routes/{web,api,console}.php                ← console.php = scheduler complet
└── tests/{Unit,Feature}/                       ← cf. section Tests
```

---

## Base de données

### Tables principales

| Table | Description | Rétention |
|---|---|---|
| `users` | Utilisateurs + rôle admin/user | — |
| `sites` | Sites de vol (seed Grand Est + import ParaglidingEarth `sites:import`, ~1100 sites ; colonnes géocodage) | — |
| `site_conditions` | Conditions idéales par site (axe vent, plages vitesse, plafond, overrides rafales) | — |
| `user_site_conditions` | Scorings perso par utilisateur (miroir + `is_active`, rotation LRU) | — |
| `user_hidden_sites` | Sites masqués par utilisateur (exclusion pure) | — |
| `weather_models` | Catalogue NWP (~20 modèles + pseudo-modèle `qui_vole_consensus`) ; colonnes sidecar | — |
| `weather_apis` | Sources prévisions (Open-Meteo self-hosted / public, consensus sidecar) | — |
| `forecasts` | Prévisions par site × modèle (panel multimodèles, carte des modèles) | **J-1** |
| `site_scores` | **Template DDL vide** — les scores vivent dans `site_scores_1`/`site_scores_2` | — |
| `site_scores_1/2` | Double-buffer écrit par le sidecar (flip `settings.scoring_table`) ; + `quality_detail` JSON | géré sidecar |
| `balises` | Balises PiouPiou/Windy (+ `in_consensus_compare_panel`, `reliability_class`, géocodage) | — |
| `balise_readings` | Lectures brutes balises (~10 min) | **7 j** |
| `balise_readings_hourly` | Agrégat horaire balises (moy. circulaire dir, AVG/MAX vitesses) | **30 j** |
| `weather_stations` | Stations météo : réseaux `mf` / `metar` / `infoclimat` (+ `has_wind_sensor`, `in_reliability_panel`) | — |
| `weather_station_observations` | Observations brutes stations (vent/temp/Td/HR/pression/précip/nuages/visibilité) | **7 j** |
| `weather_station_observations_hourly` | Agrégat horaire stations (vérité-terrain fiabilité) | **30 j** |
| `station_apis` | Config APIs stations (OAuth2 MF, clé Infoclimat, quotas) — `/admin/station-apis` | — |
| `forecast_archive_balises` | Prévisions par balise × modèle × **bucket** (+ consensus) — 5 variables vent/temp | **30 j** |
| `forecast_archive_stations` | Idem stations, payload étendu (Td, HR, précip, pression, nuages total + **bas/moyen/haut**) | **30 j** |
| `model_reliability` | Fiabilité par modèle × **balise** × bucket × variable (MAE/RMSE/biais/`weight_factor`) | — |
| `balise_consensus_compare` | Historique triple-consensus A/B/C (shadow legacy, panel balises) | 14 j |
| `weather_fetch_log` | Journal des fetches (modèle, scope site/balise/station, rows) — écran couverture | **30 j** |
| `job_monitors` | Suivi d'exécution des jobs (`TracksExecution`) — écrans admin | **7 j** |
| `settings` | Paramètres globaux (clé/valeur JSON) — cf. section Settings | — |
| `settings_audit` | Audit des modifications de paramètres | — |
| `quality_profiles` / `quality_axes` | Profils de scoring qualité lus par le sidecar (axes thermal/ceiling/…) | — |
| `model_variable_overrides` | Désactivation de variables par modèle (ex. température AROME) | — |
| `ignored_duplicates` | Paires de doublons sites/balises marquées « ignorées » (écran qualité données) | — |
| `page_views` | Trafic (middleware `RecordPageView`, hash quotidien, RGPD-friendly) | setting (365 j) |
| `modules` / `articles` / `wiki_pages` | Menu, articles d'accueil, aide en ligne | — |

> Les rétentions sont appliquées par `PurgeOldForecastsJob` (quotidien 03h00,
> DELETE par lots de 10 000 pour les tables volumineuses) et
> `PurgePageViewsJob` (03h15). **Schéma de rétention voulu** : le brut ne sert
> qu'au temps réel et à l'agrégation (fenêtre glissante 3 h) ; l'historique
> long (30 j) vit dans les agrégats horaires et les archives par bucket.

### Convention direction vent (IMPORTANT)

Toujours stocker et comparer en **FROM direction** (météo standard) : Est = 90°,
Ouest = 270°. Même convention dans `site_conditions.wind_dir_min/max`. Ajouter
+180° **uniquement à l'affichage** des flèches (pointe où le vent va).
Les agrégations de direction utilisent la **moyenne circulaire**
(SUM(SIN)/SUM(COS) en SQL puis atan2) ; les erreurs de direction la
distance/différence circulaire.

---

## API REST (`routes/api.php`)

```
# Public
GET /api/map-bundle                 → bundle carte (sites, statuts/jour, fenêtres solaires,
                                      agrégat global, scoring_status hors cache)
GET /api/sites                      → liste sites actifs (+ pays/région/département)
GET /api/sites/{id}/scores          → scores fenêtre solaire + day_quality (+ overlay perso si auth)
GET /api/sites/{id}/chart           → données horaires popup graphique
GET /api/sites/{id}/multimodel      → détail multi-modèles (day × period)
GET /api/balises                    → balises actives + dernière lecture + tendance
GET /api/balises/{id}/history       → historique du jour
GET /api/balises/{id}/comparison    → séries mesures vs consensus J−2 → J+2 (ComparisonSeriesBuilder)
GET /api/weather-stations           → stations actives (clusters carte)
GET /api/weather-stations/{id}/detail      → fiche station + dernières observations
GET /api/weather-stations/{id}/comparison  → mesures vs consensus (vent/temp/HR/pression)

# Auth (auth:web)
GET    /api/me/scoring-overrides    → overlay scoring perso du bundle
GET    /api/me/hidden-sites         → overlay sites masqués + days_summary recalculé
CRUD   /api/users/me/scorings/*     → scorings perso (+ activate/deactivate)
PUT/DELETE /api/users/me/hidden-sites/{site} → masquer / réafficher (idempotent)
```

Tous les endpoints carte sont cachés en Redis (cf. *Cache des données carte*).

### Fenêtre de vol solaire
Lever − 30 min (floor heure) → coucher + 30 min (ceil heure), Europe/Paris.
`App\Services\Map\SunWindowCalculator::compute()` (statique).

---

## Jobs & scheduler (`routes/console.php`)

| Job | Cadence | Rôle |
|---|---|---|
| `FetchForecastsJob` → `FetchSiteModelJob`×N | horaire | prévisions par site × modèle → `forecasts` (pas de scoring, pas de consensus) |
| `WatchScoringTableJob` | chaque minute | détecte le flip `scoring_table` (sidecar) → invalide caches + rebuild bundle ; évalue la fraîcheur (`ScoringFreshness`) |
| `FetchPiouPiouReadingsJob` | 10 min | lectures balises PiouPiou |
| `FetchWindyReadingsJob` | 30 min | lectures balises Windy (Http::pool, skip si pas de clé) |
| `FetchMetarStationReadingsJob` | 30 min | observations METAR → `weather_station_observations` |
| `FetchMfStationReadingsJob` | cron `9,21,33,45,57` | Météo-France infrahoraire 6 min (2 slots/run, OAuth2) |
| `FetchInfoclimatStationReadingsJob` | horaire | Infoclimat StatIC (batchs de 50 stations) |
| `FetchBaliseForecastsJob` | horaire :00 | archive prévisions (tous modèles **+ consensus**) aux coords balises, par bucket |
| `FetchStationForecastsJob` | horaire :15 | idem stations, payload étendu (chunks de 40 ; consensus = vent + temp + Td + nuages 3 étages) |
| `AggregateBaliseReadingsHourlyJob` | horaire :05 | brut balises → `balise_readings_hourly` (fenêtre glissante 3 h) |
| `AggregateStationObservationsHourlyJob` | horaire :07 | brut stations → `weather_station_observations_hourly` (logique partagée avec `stations:backfill-hourly`) |
| `ComputeBaliseConsensusCompareJob` | horaire :10 | triple-consensus A/B/C (shadow legacy, panel balises, kill switch `reliability.shadow_enabled`) |
| `PurgeOldForecastsJob` | 03h00 | purges (cf. tableau rétentions) |
| `PurgePageViewsJob` | 03h15 | purge trafic |
| `ComputeModelReliabilityJob` | 03h30 | MAE/`weight_factor` par modèle × balise × bucket × variable (fenêtre `reliability.window_days`) |

Jobs hors scheduler : `FetchSiteForecastsJob` (refresh sync d'un site, activation/tinker),
`RebuildMapBundleJob` (ShouldBeUnique 30 s), `GeocodeLocationJob` (async, observers).
Les jobs balises étendent la base abstraite `FetchBaliseReadingsJob` ; les jobs
stations étendent `FetchWeatherStationReadingsJob`. Quasiment tous les jobs
utilisent `TracksExecution` (suivi `job_monitors`, affiché dans les écrans admin).

---

## Scoring & consensus (sidecar `consensus-grid-v2`)

> Le consensus multi-modèles ET le scoring de volabilité sont calculés par le
> sidecar, qui écrit **directement en base**. La voting logic PHP, le
> `ScoringService` et le moteur de règles ont été **supprimés** (aucune
> réversibilité — si le sidecar décroche, les scores se figent).

- **Double-buffer** : le sidecar écrit le buffer inactif (`DROP` + `CREATE LIKE
  site_scores` + INSERT) puis flippe atomiquement `settings.scoring_table`
  (`"1"`↔`"2"`). Lecture via `SiteScore::onActiveBuffer()` /
  `SiteScore::activeTableName()` (pointeur lu en SQL direct, hors cache).
- **Invalidation** : `WatchScoringTableJob` purge `SiteDetailCache` (tous sites),
  map bundle et user-scoring au flip, et dispatch `RebuildMapBundleJob`.
- **Watchdog de fraîcheur** (`ScoringFreshness`) : au-delà de
  `scoring.stale_after_minutes` (75) sans flip → flag + `Log::error` one-shot ;
  exposé dans `/api/map-bundle` (`scoring_status`) → bandeau carte. N'auto-répare pas.
- **Scoring perso** : également déporté (`CustomScoringClient` →
  `POST /v1/scoring/custom`, batch par user, cache Redis
  `scoring_custom:user:{id}` TTL 1 h, fallback scoring global sur panne).
  3 invalidations : flip buffer, édition d'un scoring, changement de seuil global.
- **Pilotage du sidecar via la table `settings`** (le sidecar la lit à chaque run) :
  - `consensus.global.default_method` (A legacy / B amélioré) + preview ;
  - `consensus.config.<variable>` (~24 variables) : méthode, epsilon, MAD,
    z-threshold, `use_weight_factor`, `use_bias_correction`, render_tiles… ;
  - `consensus.scheduler.*` : mode cron/event-driven, priorités par horizon ;
  - `quality_profiles` / `quality_axes` : profils de scoring qualité
    (résultat dans `site_scores_*.quality_detail`).
- **Qualité d'une journée** : calculée à la lecture par `DayQualityCalculator`
  (cloche horaire ~13h30 × facteur de continuité ; seuils éditables `viability.*`).

---

## Fiabilité des modèles — état et objectif (IMPORTANT)

**Objectif cible** (cf. `FF_grid_reliability.md`) : pour chaque **maille** de la
grille du sidecar, pondérer le consensus selon la fiabilité 30 j de chaque modèle
par bucket, calculée **au niveau des stations météo** (vérité-terrain), chaque
station ayant un **rayon de confiance de 25 km** ; maille sans station → consensus
brut. Le mapping maille → station la plus proche se calcule **dans le sidecar**
(KD-tree), Laravel ne matérialise **rien par maille** — il fournira une table
`model_reliability_stations` (à créer) + le setting `reliability.station_radius_km`.
La comparaison du consensus archivé (`qui_vole_consensus`) aux observations donnera
aussi le **biais moyen** par paramètre (l'**offset temporel** est un chantier séparé).

**État actuel** :
- Le pipeline de données est en place : archives par bucket (balises + stations,
  consensus inclus) et agrégats horaires 30 j des deux côtés.
- Le calcul de fiabilité existant est **balise-centré** (phase 2.5 historique) :
  `ReliabilityCalculator::recomputeForBalise()` joint `forecast_archive_balises` ×
  `balise_readings_hourly` → `model_reliability` (panel
  `balises.in_consensus_compare_panel`). Fenêtre `reliability.window_days`
  (7 par défaut, extensible à 30).
- Le **shadow mode triple-consensus A/B/C** (`ConsensusCalculator`,
  `BaliseConsensusCompareService`, table `balise_consensus_compare`, écran
  `/admin/reliability/compare`) est un **legacy** de la validation de l'ancienne
  voting logic PHP : il recalcule des consensus en PHP qui ne sont pas celui du
  sidecar. **À déprécier** une fois le pipeline stations/maille en place — ne pas
  étendre ce système.
- `weather_stations.in_reliability_panel` : flag d'exclusion manuelle, pas encore
  consommé par un job (réservé au futur `ComputeStationReliabilityJob`).
- Écrans admin : `/admin/reliability/{compare,horizon,models}` + exports CSV/JSON
  (`ReliabilityExportService`, contexte pour analyse externe dans
  `RELIABILITY_ANALYSIS_CONTEXT.md`).
- Cold start : `weight_factor` reste à 1.0 tant que `samples_n <
  reliability.min_samples` (50).

---

## Modules front

### Carte de volabilité (`/carte`, public) — module principal

- **Vue** : `map/index.blade.php` + ~30 partials dans `map/_partials/{html,scripts,styles}/`.
  UN SEUL composant Alpine `x-data="mapApp()"` (jamais de composants imbriqués),
  CSS inline (pas de classes Tailwind — évite la dépendance au build Vite).
- **Boot en 1 appel** : `/api/map-bundle` (+ overlays auth : scoring perso, sites
  masqués). Marqueurs sites colorés par statut du jour sélectionné (`pgIcon`).
- **Balises** : marqueurs avec dernière lecture + tendance, volet droit avec
  graphes du jour et **comparaison mesures vs consensus** (rose des vents).
- **Stations météo** : affichées via **MarkerClusterGroup par réseau**
  (MF / METAR / Infoclimat), visibles à partir du zoom 9 ; fiche détail +
  comparaison consensus.
- **Icônes** : API SpotAir, tamponnées sur disque par `IconCacheController`
  (`/icons-cache/{site|balise|station}/...`, nginx sert directement dès le 2e hit ;
  `chown www-data` après premier déploiement ; rotation future :
  `FF_icon_cache_rotation.md`).
- Popups/dropdowns au-dessus de Leaflet : `position:fixed` +
  `getBoundingClientRect()`.

### Carte météo (`/carte-meteo`, admin pour l'instant)

Overlays raster du sidecar (~30 variables : vent sol/altitude, convectif,
plafond `qui_vole_cloud_base`, risque orageux…) sur Leaflet plein écran.
- Laravel proxifie `manifest` (cache Redis 10 min sur succès uniquement),
  `health`, `progress` ; les **PNG d'overlay passent par nginx en direct**
  (`location ~ ^/carte-meteo/overlay/` avec capture + `resolver 127.0.0.11
  valid=10s` + upstream en variable — combinaison nécessaire, ne pas
  « simplifier »).
- Flèches de vent décodées **côté navigateur** depuis le PNG `wind_direction_10m`
  (cmap HSV → angle). Si la cmap change côté sidecar, le décodeur casse.
- Légende construite depuis `palette.stops` du manifest (fallback table CSS +
  palette sémantique par variable), conversions m/s → km/h à l'affichage.
- `image-rendering: pixelated` volontaire (montre la maille native).
- Panes Leaflet : tiles 200 · overlay 350 · labels satellite 380 · flèches 400.

### Carte des modèles (`/carte-modeles`, admin)

Grille d'un modèle NWP en GeoJSON (`ModelGridBuilder`), cellules colorées par
fiabilité agrégée des **balises** du panel (à terme : lira la future table de
fiabilité stations). Zoom minimum auto par résolution ; > 16 000 cellules →
`too_large`. Pour rendre public : `/admin/modules` → `access_level`.

### Autres
- **Accueil** (`/`) : article épinglé (« À la une », au plus un) + flux articles publiés.
- **Aide** (`/aide`) : pseudo-wiki arborescent (`wiki_pages.parent_id`).
- **Profil** (`/profil`) : + `/profil/scorings` (scorings perso) et
  `/profil/sites-masques` (gestion sites masqués avec filtres en cascade).
- **Modules futurs** : journal de vol, comparatif voiles/sellettes.

---

## BackOffice (`/admin`, middleware auth + admin)

Layout `layouts/admin.blade.php` sur `<x-app-shell>` (nav dans le panneau gauche,
flash messages globaux via `<x-admin.alert>` — ne pas les répéter dans les vues).

| Section | Contenu |
|---|---|
| Dashboard **Supervision** (`/admin`) | santé du système en lecture seule : fraîcheur sidecar, état de chaque job schedulé vs cadence attendue (`SupervisionService::JOBS`), résumé couverture, APIs (quotas/erreurs), incidents 24 h, volumétrie + formulaire de déploiement géographique. Cf. `FF_admin_redesign.md` |
| **Paramètres par section** (`SectionSettingsController`) | `/admin/meteo/settings` (**8 onglets** : général, data = couverture `DataCoverage`, consensus global + par variable, orchestration scheduler sidecar, variables = `model_variable_overrides`, dépendances, sidecar, logs) ; `/admin/{sites,balises,weather-stations,contenu}/settings` |
| Sites / Balises / Stations / Users | listings filtrables (`HasFilterableIndex` : pays/région/département…), fiches, toggles |
| Modèles / APIs météo / APIs stations | édition cadence, activation, credentials (OAuth2 MF, clés), test/inspect |
| Sync (`DataSyncController`) | import sites ParaglidingEarth, découverte balises/stations, deploy |
| Qualité données | doublons sites/balises (seuils `quality.*`), ignore/unignore |
| Fiabilité | compare / horizon / models / exports (cf. Fiabilité) |
| Profils qualité | CRUD `quality_profiles`/`quality_axes` (lus par le sidecar) |
| Trafic | KPI `page_views` (jour/7j/30j, top pages, devices) |
| Articles / Wiki / Modules | éditeurs TinyMCE (upload images disque `public` ⇒ `storage:link`), menu |
| Settings / Audit | paramètres restants + `settings_audit` |
| Logs | tail logs Laravel + dernières exécutions jobs (`job_monitors`) |

Composants Blade standardisés dans `resources/views/components/admin/`
(`<x-admin.button>`, `page-title`, `badge`, `empty-state`, `alert`, `input`,
`field`, `section`) — **toujours** les utiliser plutôt que copier des classes.

---

## Paramètres globaux — `App\Services\Settings`

Service DI (`get/all/set/setMany/flush`), cache Redis 1 h invalidé à l'écriture,
défauts dans `Settings::DEFAULTS` (~90 clés), seeder idempotent
(`SettingsSeeder`), édition `/admin/settings` + écrans par section, audit en
table `settings_audit`. Groupes :

| Groupe | Exemples de clés |
|---|---|
| scoring | `scoring.precip_*`, `scoring.gust_*`, `scoring.stale_after_minutes` |
| viability | cloche horaire, continuité, seuils jour |
| quality | seuils détection doublons sites/balises |
| analytics | `pageviews.retention_days` |
| balises / stations | `windy.api_key`, `stations.fetch_enabled`, `stations.retention_days` |
| reliability | `shadow_enabled`, `min_samples`, `factor_min/max`, `window_days`… |
| consensus_global / consensus_vars / consensus_scheduler | pilotage du sidecar (cf. Scoring & consensus) |

---

## Cache des données carte — `App\Services\Map\*`

- **`MapBundleBuilder`** (TTL 90 min) : bundle global, régénéré par
  `RebuildMapBundleJob` (flip scoring, observers). `summarizeDays()` statique
  réutilisée par l'overlay sites masqués. `green_hours_set` retiré du payload
  client par le contrôleur.
- **`SiteDetailCache`** (TTL 90 min) : scores/chart/multimodel par site ;
  invalidé au flip + au refresh forecasts d'un site.
- **`BalisesBundleCache`** : `/api/balises` TTL 5 min (invalidé par les jobs
  readings) ; history TTL 2 min.
- **Règle d'or `CACHE_VERSION`** : toute modification de structure d'un payload
  caché ⇒ **bump** de la constante `CACHE_VERSION` du service (sinon les vieilles
  entrées Redis cassent la vue). Ne **jamais** stocker d'objets Eloquent en
  cache — primitives uniquement (sinon `__PHP_Incomplete_Class` au premier
  changement de schéma).
- Fallback lazy avec lock Redis anti-thundering-herd ; commande
  `php artisan map:rebuild-bundle [--clear]`.

---

## Géocodage administratif — `App\Services\Geocoding\*`

Orchestrateur 2 tiers : **geo.api.gouv.fr** (point-in-polygon FR, sans rate
limit) → **Nominatim** (monde, 1 req/s via `Cache::lock` distribué). Colonnes
`country_code/country/admin_region/department/geocoded_*` sur `sites` et
`balises`. Déclencheurs : `GeocodableObserver` (async, tries=3 backoff) +
commande en masse `geocode:locations` (idempotente sans `--force`). BAN
abandonné (sites loin des adresses postales). Config : bloc `geocoding` de
`config/services.php` (`NOMINATIM_USER_AGENT`, `NOMINATIM_CONTACT_EMAIL`…).

---

## Conventions de code

### PHP / Laravel
- PSR-12 strict, `declare(strict_types=1)` en tête de chaque fichier.
- Logique métier dans `app/Services/`, jamais dans les controllers.
- Traitements async via jobs queue Redis ; jobs longs avec `TracksExecution`.
- Toujours Eloquent **sauf** agrégations massives (fiabilité, agrégats horaires) :
  `DB::table()` + fonctions SQL (AVG/MAX/SIN/COS) et **upserts par lots de 500**
  sont la norme pour les volumétries balises/stations.
- Migrations Laravel uniquement, snake_case, indexes sur FK et colonnes filtrées.

### Frontend
- **Vue carte : CSS inline et un seul `x-data`** (pas de Tailwind, pas de
  composants Alpine imbriqués). Dropdowns en `position:fixed` calculé.
- SVG popups générés en JS pur (`buildChartSVG`, helpers `svgHelpers` dans
  `geometry.blade.php`).
- CSS custom properties dans `map/_partials/styles/base.blade.php` pour toute
  valeur répétée ≥ 3 fois.
- `window.AppShell.isDesktop()` = single source of truth du breakpoint 1024 px.
- Hiérarchie z-index : 30 backdrop · 40 navbar/volets · 50 dropdowns navbar ·
  70 contrôles carte · 80 tooltips. Pas de `z-index: 9999`.

### Shell global `<x-app-shell>`
- Props : `title`, `page-title`, `detail-title`, `help-title`,
  `:left-default`/`:right-default` ; slots `detail` (gauche) et `help` (droite).
- Un slot vide n'affiche pas le panneau. Ne **pas imbriquer** de composant
  anonyme dans un slot (→ « Undefined variable $component ») : `@include`.
- `@push('styles')` / `@push('scripts')` disponibles.
- **Jamais** écrire une balise composant Blade (`<x-app-shell\>`) dans un
  commentaire CSS/JS d'un fichier Blade (Blade la compile quand même) ;
  `@media`/`@keyframes` dans un CSS Blade → `@verbatim`.

---

## Commandes utiles

```bash
# Environnement
docker compose up -d
docker exec -it parapente_php bash
docker exec -it parapente_php php artisan optimize:clear
docker exec -it parapente_php npm run build

# Queue / scheduler
docker exec -it parapente_php php artisan queue:work --queue=meteo
docker logs parapente_worker -f

# Carte / cache
docker exec -it parapente_php php artisan map:rebuild-bundle [--clear]

# Données de référence
docker exec -it parapente_php php artisan sites:import --iso=fr [--dry-run]
docker exec -it parapente_php php artisan balises:discover
docker exec -it parapente_php php artisan weather-stations:discover --network=mf
docker exec -it parapente_php php artisan stations:classify-sensors [--dry-run]
docker exec -it parapente_php php artisan geocode:locations [--sites] [--balises] [--force]

# Backfills (après trou de collecte ou nouvelle table d'agrégat)
docker exec -it parapente_php php artisan readings:backfill-hourly --days=30
docker exec -it parapente_php php artisan stations:backfill-hourly --days=30
docker exec -it parapente_php php artisan mf:backfill --from=… --to=…
docker exec -it parapente_php php artisan infoclimat:backfill --from=…

# Fiabilité (legacy balises, cf. section Fiabilité)
docker exec -it parapente_php php artisan reliability:compute-compare [--balise=ID]
docker exec -it parapente_php php artisan reliability:compute-factors [--days=N]
docker exec -it parapente_php php artisan reliability:purge-out-of-panel

# Divers
docker exec -it parapente_php php artisan admin:create
docker exec -it parapente_php php artisan db:scrub        # anonymise un dump prod (refuse en prod)

# Lire les scores (TOUJOURS via le buffer actif)
# >>> \App\Models\SiteScore::onActiveBuffer()->where('site_id',1)->get();
# >>> \App\Models\SiteScore::activeTableName();  // site_scores_1 | site_scores_2
```

---

## URLs de développement

| Service     | URL                   |
|-------------|-----------------------|
| Application | http://localhost:8001 |
| phpMyAdmin  | http://localhost:8081 |
| MailDev     | http://localhost:6082 |
| Xdebug port | 6001                  |

> Ports décalés pour coexister avec Dolibarr sur le même serveur.

---

## Points d'attention

1. **Un seul `x-data` dans la vue carte** ; CSS inline ; dropdowns `position:fixed`.
2. **Convention vent FROM** partout, +180° à l'affichage seulement ; moyennes et
   erreurs de direction toujours **circulaires**.
3. **Redis** : cache + sessions + queues — ne pas changer `QUEUE_CONNECTION`.
4. **Horizon météo 5 jours** max.
5. **`CACHE_VERSION`** : bump obligatoire à toute modif de payload caché
   (map-bundle, scores/chart/multimodel, balises). Jamais d'Eloquent en cache.
6. **Observers et opérations en masse** : les `Builder::update()` /
   `DB::table()->insert()` bypassent les observers → lancer
   `map:rebuild-bundle` (et `geocode:locations`) après.
7. **Ordre des purges vs backfills** : la purge 03h00 ramène le brut à 7 j —
   après un trou de collecte ou une nouvelle table d'agrégat, lancer les
   backfills **avant** la purge suivante.
8. **Ne pas étendre le shadow mode A/B/C** (legacy) : tout nouveau travail de
   fiabilité va vers le pipeline stations/maille (`FF_grid_reliability.md`).
9. **Sémantique `weight_factor`** : clamp `factor_min/max` + gate `min_samples` —
   une seule convention Laravel ↔ sidecar, documentée dans le FF.
10. **`CHANGELOG.md`** : proposer la mise à jour après chaque changement majeur.
11. **Scheduler** : tout nouveau job schedulé se déclare dans
    `routes/console.php` avec `->name()` + `->withoutOverlapping()`, et
    s'enregistre dans **deux registres** : les labels de
    `SectionSettingsController::SCHEDULED_JOBS` (s'il concerne
    data/balises/stations) et `SupervisionService::JOBS` (cadence attendue —
    sinon il est invisible du dashboard Supervision).
12. **Sidecar piloté par `settings`** : les clés `consensus.*` sont lues par le
    sidecar à chaque run — les modifier via `/admin/meteo/settings`, jamais en
    SQL direct (audit + cache).

---

## Tests

`tests/Unit` : `ConsensusCalculatorTest` (classe pure), `UserScoringServiceTest`,
`Map/SummarizeDaysTest`. `tests/Feature` : API (map-bundle, sites user-aware,
caches site detail/balises, scorings perso, sites masqués), Auth
(login/registration), `WatchScoringTableJobTest`, `ScoringFreshnessTest`,
pages user. Lancer : `docker exec -it parapente_php php artisan test`.

---

## Architecture de déploiement production

### Infrastructure VPS

| Élément | Valeur |
|---|---|
| Domaine | `qui-vole.fr` + `www.qui-vole.fr` |
| IP VPS | `213.199.51.57` |
| OS | Ubuntu 24.04 LTS |
| Chemin projet | `/srv/parapente-app/parapente/` |
| Chemin proxy | `/srv/proxy/` |
| Chemin Open-Meteo | `/srv/openmeteo/` |

### Architecture Docker sur le VPS

```
Internet (80/443)
       │
       ▼
Nginx Proxy Manager       (/srv/proxy/ — réseau: proxy, HTTPS Let's Encrypt)
       │
       ▼
parapente_nginx:80        (réseaux: proxy + parapente-external-pod + meteo-net)
       │  PHP-FPM
       ▼
parapente_php:9000        (réseaux: parapente-internal-pod + meteo-net)
       ├── parapente_mariadb:3306   (parapente-internal-pod)
       ├── parapente_redis:6379     (parapente-internal-pod)
       ├── open-meteo-api:8080      (meteo-net — projet séparé)
       └── consensus-grid-v2-api:8082 (meteo-net — sidecar, projet séparé)

parapente_worker / parapente_scheduler  (parapente-internal-pod + meteo-net)
```

Réseaux : `proxy` (external), `parapente-internal-pod` (internal),
`parapente-external-pod` (bridge), `meteo-net` (external, partagé avec
Open-Meteo **et le sidecar** — le sidecar doit déclarer `meteo-net` en
`external: true` pour que son alias DNS soit résolvable par nginx).

### Fichiers de configuration prod

| Fichier | Rôle |
|---|---|
| `docker-compose.prod.yml` | Orchestration prod (sans Xdebug/MailDev/phpMyAdmin) |
| `Dockerfile.prod` | Image PHP prod (php.ini-production, opcache optimisé) |
| `nginx/nginx.prod.conf` | Nginx prod (+ blocs `/icons-cache/` et proxy overlays `/carte-meteo/overlay/`) |
| `src/.env`, `.env.prod`, `.env` | Jamais committés ; `.env` racine = copie de `.env.prod` (lu par Compose) |

`OPEN_METEO_BASE_URL` : dev `http://localhost:8888/v1`, prod
`http://open-meteo-api:8080/v1`. `CONSENSUS_GRID_BASE_URL` : défaut
`http://consensus-grid-v2-api:8082`.

### Mise à jour (déploiement)

```bash
cd /srv/parapente-app/parapente
git fetch origin && git checkout <branche> && git pull origin <branche>   # ex: V4

docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d

docker exec parapente_php composer install --no-dev --optimize-autoloader
docker exec parapente_php npm install && docker exec parapente_php npm run build

docker exec parapente_php php artisan migrate --force
docker exec parapente_php php artisan storage:link                      # idempotent
# seeders ciblés uniquement (PAS `db:seed` seul, qui reseed tout) :
# docker exec parapente_php php artisan db:seed --class=SettingsSeeder --force

# droits + caches (les exec tournent en root, PHP-FPM en www-data)
docker exec parapente_php chown -R www-data:www-data storage bootstrap/cache
docker exec parapente_php php artisan optimize:clear
docker exec parapente_php php artisan optimize

# PHP d'abord (opcache.validate_timestamps=0), PUIS nginx (re-résolution IP)
docker compose -f docker-compose.prod.yml restart parapente-php
docker compose -f docker-compose.prod.yml restart parapente-nginx
```

> `docker compose restart <service>` = nom de **service** (tirets :
> `parapente-php`) ; `docker exec <conteneur>` = nom de **conteneur**
> (underscores : `parapente_php`).

### Points d'attention prod

- **Recréation du conteneur PHP ⇒ restart nginx** (sinon 502 : IP gelée par
  `fastcgi_pass`).
- `chown www-data` sur `storage bootstrap/cache` (et `public/icons-cache/`
  au premier déploiement) après tout `docker exec` root.
- `APP_DEBUG=false` ; cache config/routes/vues incohérent → 500 « Target class
  [view] does not exist » → `optimize:clear && optimize`.
- Vérification santé : `docker compose -f docker-compose.prod.yml ps`,
  `docker logs parapente_worker --tail 20`, écran `/admin/logs` (job monitors),
  onglets « data » des settings (couverture), bandeau fraîcheur scoring sur la carte.
