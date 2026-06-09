# CLAUDE.md — Instructions pour Claude Code

## Présentation du projet

**Qui Vole ?** (anciennement « ParapenteFR ») est une plateforme web nationale dédiée aux pilotes de parapente,
construite en Laravel. Le projet est modulaire : chaque grande fonctionnalité est un module indépendant.

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
*Futur Feature*). En français, structuré, suffisamment complet pour qu'on
puisse reprendre le sujet plus tard sans devoir tout réfléchir à nouveau.

Le fichier doit contenir au minimum : le concept, le modèle de données
retenu, les choix d'architecture (avec les alternatives écartées et leur
justification), les bornes / garde-fous, une estimation grossière, un
ordre de découpage suggéré, et les risques identifiés.

Exemple : `FF_personnal_scoring.md` — scoring personnalisé par utilisateur.

---

## Convention de branches Git

- **Branches conservées en permanence** :
  - branches **majeures** : `V1`, `V2`, `V3`… (une par grande version) ;
  - branches **versionnées** : `V1.1`, `V1.2`, `V2.1`, `V2.2`… (incréments d'une version).
  - Ne **jamais** supprimer ces branches, même si leur contenu semble repris ailleurs.
- Les branches de travail temporaires (`feature/*`, `fix/*`, `claude/*`, etc.) sont
  jetables une fois fusionnées dans la branche de version correspondante.
- Le travail courant se fait sur la branche de version active (actuellement `V2`).

---

## Stack technique

| Composant       | Technologie                         |
|-----------------|-------------------------------------|
| Serveur         | Nginx 1.26                          |
| Backend         | PHP 8.4 / Laravel 13                |
| Base de données | MariaDB 10.11                       |
| Cache / Queue   | Redis 7                             |
| Frontend        | Tailwind 4 + Alpine.js (CSS inline pour la carte) |
| Carte           | Leaflet.js                          |
| Positionnement flottant | `@floating-ui/dom` (dispo, migration progressive) |
| Build assets    | Vite                                |
| Conteneurs      | Docker / Docker Compose             |

> **Note** : la vue carte (`map/index.blade.php`) utilise majoritairement du CSS inline
> (pas de classes Tailwind) pour éviter les dépendances au build Vite. Alpine.js est
> utilisé sans composants imbriqués — tout l'état est dans un seul `x-data="mapApp()"`.

---

## Architecture du projet

```
src/                        ← Racine Laravel
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── SiteController.php       ← Fin orchestrateur ; délègue aux payload builders
│   │   │   │   ├── BaliseController.php     ← API JSON balises + history
│   │   │   │   ├── MapBundleController.php  ← Bundle pré-calculé pour boot carte
│   │   │   │   ├── MeScoringController.php  ← Overrides scoring perso (auth)
│   │   │   │   ├── UserScoringController.php ← CRUD scorings perso utilisateur
│   │   │   │   ├── MeHiddenSitesController.php ← Overlay sites masqués + days_summary recalculé (auth)
│   │   │   │   └── UserHiddenSiteController.php ← CRUD sites masqués utilisateur (FF_site_blacklist.md)
│   │   │   ├── Admin/                   ← BackOffice (sites, balises, modèles, APIs,
│   │   │   │                                users, sync, logs, articles, modules…)
│   │   │   ├── HomeController.php        ← Page d'accueil (articles + épinglé « À la une »)
│   │   │   ├── IconCacheController.php   ← Tampon disque icônes SpotAir
│   │   │   ├── MapController.php         ← Vue carte de volabilité (/carte)
│   │   │   ├── ModelGridController.php   ← Module « Carte des modèles » (admin-only, FF_model_reliability.md)
│   │   │   ├── WeatherMapController.php  ← Module « Carte météo » (admin-only, proxy sidecar consensus-grid)
│   │   │   └── WikiController.php        ← Pseudo-wiki / aide en ligne (/aide)
│   │   ├── Requests/Api/
│   │   │   ├── ScoringRules.php           ← Catalogue de règles validation partagé
│   │   │   └── ValidatesScoringCoherence  ← Invariants relationnels (min ≤ max, etc.)
│   │   └── Controllers/Concerns/
│   │       └── HasFilterableIndex.php     ← Trait search/sort whitelist pour admin
│   ├── Models/
│   │   ├── Site.php, SiteCondition.php, WeatherModel.php, Forecast.php,
│   │   ├── SiteScore.php, Balise.php, BaliseReading.php, BaliseReadingHourly.php
│   │   ├── ModelReliability.php          ← Fiabilité par modèle (phase 2.5)
│   │   ├── BaliseConsensusCompare.php    ← Historique triple-consensus (phase 2.5)
│   │   ├── Module.php                    ← Modules du menu (table `modules`)
│   │   ├── Article.php                   ← Articles / changelog accueil (+ flag `is_pinned`)
│   │   ├── WikiPage.php                  ← Pages du pseudo-wiki (arborescence parent_id)
│   │   ├── UserSiteCondition.php         ← Scorings perso utilisateur (FF_personnal_scoring.md)
│   │   └── UserHiddenSite.php            ← Sites masqués par utilisateur (FF_site_blacklist.md)
│   ├── Support/
│   │   └── Navigation.php                ← Liste des modules visibles (navbar)
│   ├── Services/
│   │   ├── Weather/
│   │   │   ├── Apis/OpenMeteoApi.php         (fetch sites + batch balises — alimente `forecasts` pour multimodèles/grid/fiabilité)
│   │   │   ├── CustomScoringClient.php       (client HTTP `POST /v1/scoring/custom` — scoring perso déporté au sidecar)
│   │   │   ├── SiteScoresPayloadBuilder.php  (build `/api/sites/{id}/scores`)
│   │   │   ├── SiteChartPayloadBuilder.php   (build `/api/sites/{id}/chart`)
│   │   │   ├── SiteMultimodelPayloadBuilder.php (build `/api/sites/{id}/multimodel`)
│   │   │   ├── UserScoringService.php        (overrides scoring perso)
│   │   │   └── Reliability/              ← Shadow mode triple-consensus (FF_model_reliability.md)
│   │   │       ├── ConsensusCalculator.php          (classe pure : legacy + improved, linear + circular)
│   │   │       ├── ReliabilityCalculator.php        (lookup weight_factor + recomputeForBalise)
│   │   │       ├── BaliseConsensusCompareService.php (orchestrateur des 3 consensus)
│   │   │       └── ReliabilityExportService.php     (CSV/JSON pour analyse externe)
│   │   ├── Balises/
│   │   │   ├── BaliseProviderInterface.php
│   │   │   ├── BaliseConstants.php           (DEAD_AFTER_DAYS, etc.)
│   │   │   ├── BaliseReadingFormatter.php    (sérialisation JSON)
│   │   │   ├── PiouPiouProvider.php, MetarProvider.php, WindyOpenDataProvider.php
│   │   ├── Geocoding/                    ← Reverse geocoding admin (FF_location_enrichment.md)
│   │   │   ├── LocationResult.php            (DTO readonly)
│   │   │   ├── ReverseGeocoderInterface.php
│   │   │   ├── GeoApiGouvReverseGeocoder.php (point-in-polygon FR, sans rate limit)
│   │   │   ├── NominatimReverseGeocoder.php  (fallback monde, 1 req/s)
│   │   │   └── HybridReverseGeocoder.php     (orchestrateur 2 tiers : geoApiGouv → nominatim)
│   │   └── Map/                          ← Cache pré-calculé des données carte
│   │       ├── MapBundleBuilder.php          (bundle markers, /api/map-bundle)
│   │       ├── SiteDetailCache.php           (cache /scores /chart /multimodel)
│   │       ├── BalisesBundleCache.php        (cache /api/balises + /history)
│   │       ├── DayQualityCalculator.php      (helper viabilité jour)
│   │       ├── SunWindowCalculator.php       (helper fenêtre solaire)
│   │       └── ModelGridBuilder.php          (grille NWP en GeoJSON, /carte-modeles)
│   ├── Jobs/
│   │   ├── FetchForecastsJob.php         ← Orchestre par site (Bus::batch)
│   │   ├── FetchSiteForecastsJob.php     ← Fetch + score 1 site
│   │   ├── FetchBaliseReadingsJob.php    ← Base ABSTRAITE des 3 jobs balises
│   │   ├── FetchPiouPiouReadingsJob.php  ← Étend FetchBaliseReadingsJob
│   │   ├── FetchMetarReadingsJob.php     ← Étend FetchBaliseReadingsJob
│   │   ├── FetchWindyReadingsJob.php     ← Étend FetchBaliseReadingsJob
│   │   ├── FetchBaliseForecastsJob.php   ← Archive prévisions aux coords balises
│   │   ├── AggregateBaliseReadingsHourlyJob.php ← Agrège balise_readings → balise_readings_hourly
│   │   ├── ComputeBaliseConsensusCompareJob.php ← Phase 2.5 — horaire :10
│   │   ├── ComputeModelReliabilityJob.php ← Phase 2.5 — quotidien 03:30
│   │   ├── GeocodeLocationJob.php        ← Géocode 1 Site/Balise (job async)
│   │   └── RebuildMapBundleJob.php       ← Régénère le map bundle (ShouldBeUnique 30s)
│   └── Observers/
│       ├── GeocodableObserver.php        ← Géocode auto Site/Balise à la création/modif coords
│       ├── MapBundleInvalidationObserver.php ← Rebuild map bundle sur create/update/delete (debounce 5s)
│       └── SiteActivationObserver.php    ← Fetch météo + scoring immédiat à l'activation d'un site
├── config/
│   └── weather.php                       ← Palette MODEL_COLORS des modèles NWP
├── database/
│   ├── migrations/
│   └── seeders/
│       ├── ModuleSeeder.php              ← Modules du menu
│       ├── WeatherModelSeeder.php, SiteSeeder.php, GrandEstSitesSeeder.php
├── resources/views/
│   ├── components/
│   │   ├── app-shell.blade.php           ← Shell global <x-app-shell>
│   │   └── admin/button.blade.php        ← Bouton standardisé BackOffice
│   ├── partials/
│   │   └── app-shell-navbar.blade.php    ← Barre de menu supérieure
│   ├── layouts/
│   │   └── admin.blade.php               ← BackOffice (utilise <x-app-shell>)
│   ├── home.blade.php                    ← Page d'accueil (/)
│   ├── admin/                            ← Vues BackOffice (articles/, modules/, sites/, …)
│   └── map/index.blade.php               ← Vue carte (/carte)
└── routes/
    ├── web.php
    └── api.php                           ← Endpoints REST publics + auth
```

---

## Base de données

### Migrations (dans l'ordre)

| Table             | Description                                              |
|-------------------|----------------------------------------------------------|
| `users`           | Utilisateurs + rôle admin/user                          |
| `sites`           | Sites de vol (nom, coords, altitude, niveau, région)    |
| `site_conditions` | Conditions idéales par site (vent dir/vitesse, nuages)  |
| `weather_models`  | Catalogue des modèles NWP (~20 connus + `qui_vole_consensus` **inactif**, palette dans `config/weather.php`) |
| `weather_apis`    | Sources API météo (Open-Meteo self-hosted/public ; `consensus` **inactif** depuis le scoring déporté) |
| `forecasts`       | Prévisions brutes par modèle (nullable). Alimente le panel multimodèles, `/carte-modeles` et la fiabilité (plus le scoring) |
| `site_scores`     | **Template DDL vide** (plus écrit par Laravel). Les scores vivent dans `site_scores_1` / `site_scores_2` (double-buffer écrit par le sidecar) ; le buffer actif est désigné par `settings.scoring_table`. Lecture via `SiteScore::onActiveBuffer()` |
| `site_scores_1` / `site_scores_2` | Double-buffer de scoring écrit par le sidecar `consensus-grid-v2` (DROP/CREATE LIKE/INSERT + flip atomique de `scoring_table`). Colonnes = `site_scores` + `quality_detail` (JSON scores qualité) |
| `balises`         | Balises PiouPiou/FFVL/METAR/Windy (+ flag `in_consensus_compare_panel` pour la phase 2.5) |
| `balise_readings` | Lectures temps réel balises                             |
| `balise_readings_hourly` | Agrégat horaire des lectures balises (dir/vit/rafale/temp) — alimenté par `AggregateBaliseReadingsHourlyJob` |
| `forecast_archive_balises` | Prévisions Open-Meteo archivées aux coords des balises, ventilées par horizon_bucket (nowcast/same_day/j_plus_1/j_plus_2) |
| `model_reliability` | Fiabilité par modèle météo × balise × bucket × variable (MAE/RMSE/biais/`weight_factor`/`samples_n`) — alimenté par `ComputeModelReliabilityJob`. Cf. `FF_model_reliability.md`. |
| `balise_consensus_compare` | Historique triple-consensus (A legacy / B amélioré / C amélioré+fiabilité) vs observation balise, pour les balises du panel `in_consensus_compare_panel`. Rétention 14 j. |
| `modules`         | Modules du menu (key, label, icône, route, `is_active`, `access_level` guest\|user\|admin, `requires_registration`, `sort_order`) |
| `articles`        | Articles / changelog accueil (titre, body HTML, `author_id`, `is_published`, `is_pinned`, `published_at`) |
| `wiki_pages`      | Pages du pseudo-wiki (`parent_id` auto-référent, `slug` unique, `title`, `excerpt`, body HTML, `sort_order`, `is_published`, `author_id`) |
| `settings`        | Paramètres globaux (clé unique, valeur JSON, label, description) — seuils de scoring + paramètres `reliability.*` éditables via `/admin/settings` |
| `user_site_conditions` | Scorings perso d'un utilisateur par site (miroir de `site_conditions` + `is_active`/`activated_at` pour la rotation LRU). Unique `(user_id, site_id)`. Cf. `FF_personnal_scoring.md`. |
| `user_hidden_sites` | Sites masqués par un utilisateur sur la carte de volabilité (exclusion pure : 1 ligne = 1 site masqué pour ce user). `user_id`, `site_id`, unique `(user_id, site_id)`, cascade delete. Cf. `FF_site_blacklist.md`. |

> **Colonnes de géocodage** (sur `sites` et `balises`) :
> `country_code` (CHAR 2 ISO-2), `country` (libellé FR), `admin_region`
> (région / Bundesland / canton), `department` (département / province /
> Landkreis), `geocoded_provider` (`geo-api-gouv` | `nominatim`),
> `geocoded_at`. Alimentées par `App\Services\Geocoding\*` via la
> commande `php artisan geocode:locations` (one-shot) ou l'observer
> `GeocodableObserver` (création / modif coords). Indexes :
> `country_code`, `department`, `(country_code, admin_region)`.
> Cf. `FF_location_enrichment.md`.

### Colonnes clés `site_conditions`
```
wind_dir_min, wind_dir_max          ← Axe favorable (ex: 75-105 pour Volmerange EST)
wind_speed_min, wind_speed_max      ← Plage de vent acceptable (km/h)
wind_speed_ideal                    ← Vent idéal
wind_gust_orange_kmh                ← Override rafale orange par site (nullable, sinon valeur globale)
wind_gust_red_kmh                   ← Override rafale rouge par site (nullable, sinon valeur globale)
cloud_base_min_m                    ← Plafond nuageux minimum (m)
cloud_cover_low_max                 ← Couverture nuageuse basse max
```
> Les seuils de précipitations sont **globaux** (table `settings`,
> clés `scoring.precip_orange_mmh` / `scoring.precip_red_mmh`).

### Colonnes clés `site_scores`
```
status                  ← green / orange / red / unknown
confidence_pct          ← Pourcentage de confiance (0-100)
wind_dir_consensus      ← Direction vent consensus (FROM direction, météo standard)
wind_speed_consensus    ← Vitesse vent moyen consensus
wind_gust_consensus     ← Rafales consensus (= consensus de wind_speed_max)
precip_consensus        ← Précipitations consensus
cloud_base_consensus    ← Plafond de vol estimé consensus (m ASL, règle d'Espy)
models_count            ← Nombre de modèles ayant des données
models_converging       ← Nombre de modèles convergents
detail                  ← JSON détail du scoring
```

### Sites en base (14 total — seed initial)
Volmerange EST (49.4468, 6.0999, 420m, vent E 75°-105°) + 13 autres sites de vol :
Jouy-sous-les-Côtes, Beauring, Losheim, Houéville, Létanne, Lion-devant-Dun,
Coo Ouest, Algrange, Fumay, Coo Sud, Revin Fallières, Klusserath, Markstein.
(La couverture a vocation nationale ; le `GrandEstSitesSeeder` n'est que le jeu de données initial.)

---

## API REST

```
GET /api/map-bundle         → Bundle pré-calculé : tous les sites + statuts
                              journaliers + fenêtres solaires + agrégat global
                              par jour. Boot de la carte = 1 seul appel
                              (avant : 1 + N appels). Cache Redis partagé,
                              régénéré à la fin de chaque cycle de scoring.
GET /api/me/scoring-overrides → (auth) Sur-couche utilisateur du bundle :
                              flag user_scoring + statuts journaliers
                              recalculés pour les sites avec scoring perso
                              actif. Fusionné côté client avec le bundle global.
GET /api/me/hidden-sites    → (auth) Sur-couche « sites masqués » : liste
                              des site_id à filtrer + days_summary recalculé
                              SANS les sites masqués (compteur « h de vol
                              possible » cohérent). null si aucun site masqué.
                              Recalcul en mémoire depuis le bundle (pas de DB).
                              Cf. FF_site_blacklist.md.
GET    /api/users/me/hidden-sites        → (auth) Liste des sites masqués (CRUD page gestion).
PUT    /api/users/me/hidden-sites/{site} → (auth) Masquer un site (idempotent).
DELETE /api/users/me/hidden-sites/{site} → (auth) Réafficher un site (idempotent).
GET /api/sites              → Liste tous les sites actifs (métadonnées,
                              + country/admin_region/department pour les
                              filtres de la page « Sites masqués »).
                              Conservé pour rétrocompat — la carte utilise
                              désormais /api/map-bundle.
GET /api/sites/{id}/scores  → Scores filtrés fenêtre solaire + sun_windows
                              + day_quality + detail allégé par créneau
                              (consensus/convergence/color des 5 paramètres
                              de la voting logic ; sans les `values` brutes).
                              Cache Redis transparent (version global) +
                              overlay scoring perso si user authentifié.
GET /api/sites/{id}/chart   → Données horaires pour popup graphique
                              (vent min/moy/max, nuages H/M/B, direction).
                              Cache Redis transparent.
GET /api/sites/{id}/multimodel?day=YYYY-MM-DD&period=24h|daylight
                            → Détail multi-modèles d'une journée (vent,
                              direction, précip, humidité, température,
                              plafond) + consensus par heure.
                              Cache Redis transparent (par day × period).
GET /api/balises            → Liste des balises actives + dernière lecture
                              + tendance 30 min. Cache Redis transparent
                              (TTL 5 min, invalidé par les jobs Fetch*Readings).
GET /api/balises/{id}/history → Historique du jour (graphes volet droit).
                              Cache Redis transparent (TTL 2 min).
```

### Fenêtre de vol solaire
- Début : lever du soleil − 30min → **floor** à l'heure (ex: 06:40 → 6h)
- Fin   : coucher du soleil + 30min → **ceil** à l'heure (ex: 18:50 → 19h)
- Calculé via `date_sunrise` / `date_sunset` PHP natif, timezone Europe/Paris
- Implémenté dans `App\Services\Map\SunWindowCalculator::compute()` (statique,
  utilisé par les 3 payload builders et `MapBundleBuilder`).

---

## Interface globale — shell `<x-app-shell>`

Structure commune à (presque) tous les écrans, en **Tailwind + composants Blade** :

- `resources/views/components/app-shell.blade.php` — composant `<x-app-shell>` :
  `<head>`, **barre de menu supérieure**, panneau latéral **gauche « détail »**
  (slot `detail`), zone centrale (`$slot`), panneau latéral **droit
  « aide / légende / actions »** (slot `help`).
- `resources/views/partials/app-shell-navbar.blade.php` — la navbar, incluse via
  `@include` (partage le scope Alpine `leftOpen` / `rightOpen`). Liens des modules
  filtrés par `App\Support\Navigation::modules()` (lecture de la table `modules`),
  + formulaire de connexion en menu déroulant à droite.

Props utiles de `<x-app-shell>` : `title`, `page-title`, `detail-title`,
`help-title`, `:left-default` / `:right-default` (panneau ouvert d'emblée, desktop only).

Exemple d'utilisation :
```blade
<x-app-shell title="Accueil" page-title="Accueil" help-title="Aide">
    <x-slot:detail> … </x-slot:detail>   {{-- panneau gauche, optionnel --}}
    <x-slot:help>   … </x-slot:help>      {{-- panneau droit, optionnel --}}
    … contenu principal …
</x-app-shell>
```

Notes :
- Un slot latéral **vide** (ex. `<x-slot:detail>` rendu vide par un `@auth`) ne
  fait pas apparaître le panneau ni son bouton (`<x-app-shell>` teste le contenu réel).
- Ne **pas imbriquer** de composant anonyme dans un slot de `<x-app-shell>`
  (provoque « Undefined variable $component ») — utiliser `@include`.
- Les pages utilisant `<x-app-shell>` peuvent `@push('styles')` / `@push('scripts')`
  (le shell expose `@stack('styles')` dans le `<head>` et `@stack('scripts')` avant `</body>`).
- **Page d'accueil** (`/`, `HomeController`) : affiche d'abord l'article
  épinglé (`Article::pinned()`, au plus un — flag `is_pinned`, garde-fou
  côté contrôleur) dans un bloc « À la une » au-dessus, puis le flux
  des articles publiés (`Article::published()`) du plus récent au plus ancien.
- **BackOffice** (`layouts/admin.blade.php`) : repose sur `<x-app-shell>` ; la
  navigation des sections admin est dans le panneau gauche, ouvert par défaut.
  Une page admin peut alimenter le panneau droit via `@section('help')`.
  Les messages flash (`session('status'|'error'|'warning')`) sont rendus
  globalement par le layout via `<x-admin.alert>` — ne pas les répéter
  dans les vues individuelles.
- **Modules** : éditables dans `/admin/modules` (actif, niveau de droit
  `guest|user|admin`, compte obligatoire). Visibilité menu = `Module::isVisibleFor()`.
  Un module sans `route_name` est affiché grisé (« non implémenté »).
- **Articles** : éditeur WYSIWYG **TinyMCE** (CDN), upload d'images via
  `POST /admin/articles/upload-image` → disque `public` (⇒ `php artisan storage:link`).
  Un seul article peut être « épinglé » (`is_pinned`) à la fois ; les
  autres sont dépinglés automatiquement à l'écriture.
- **Pseudo-wiki / aide en ligne** (`/aide`, `WikiController`) : pages
  organisées en arborescence simple (`wiki_pages.parent_id` auto-référent).
  Index `/aide` liste les racines, `/aide/{slug}` rend une page avec
  navigation arborescente dans le panneau gauche et fil d'Ariane.
  Admin : CRUD `/admin/wiki/*` (TinyMCE, slug auto avec déduplication,
  prévention des boucles parent/enfant). Upload d'images dédié
  `POST /admin/wiki/upload-image` (disque `public/wiki/`).
- La **vue carte** (`/carte`) utilise désormais `<x-app-shell>` comme toutes
  les autres pages (depuis V2.x — refonte mobile). `mapApp()` est passé
  directement en `x-data` du shell via la prop `x-data="mapApp()"` ; il
  expose les variables `leftOpen` / `rightOpen` consommées par le shell.
  Les volets carte (filtres / détail) sont des `<x-slot:detail>` /
  `<x-slot:help>` avec `hideDetailHeader`/`hideHelpHeader=true` (la carte
  fournit ses propres en-têtes) et `right-class` élargi en desktop
  (`lg:w-[clamp(420px,45vw,640px)] xl:w-[50vw]`) pour les graphes /
  tableaux scoring.

---

## Module 1 — Carte de volabilité (IMPLÉMENTÉ)

> Anciennement « Carte météo » jusqu'au 2026-05-23. Renommée pour
> distinguer du nouveau module « Carte météo » (cf. plus bas) qui
> superpose des overlays consensus-grid sur une vue Leaflet.

### Vue carte (`map/index.blade.php`)

**Architecture Alpine.js** — UN SEUL composant `x-data="mapApp()"`, pas de composants
imbriqués. Les dropdowns toolbar utilisent `position:fixed` calculé via `getBoundingClientRect()`
pour échapper au stacking context de Leaflet.

**Toolbar (h=56px)** :
- Dropdown sélecteur de journée (5 jours, défaut = Aujourd'hui)
- Compteur sites volables
- Dropdown sélecteur fond de carte (5 options)

**Fonds de carte disponibles** :
| Clé        | Label         | URL                                    |
|------------|---------------|----------------------------------------|
| topo       | Topographique | opentopomap.org (défaut)               |
| osm        | Standard      | tile.openstreetmap.org                 |
| satellite  | Satellite     | server.arcgisonline.com (ESRI)         |
| dark       | Sombre        | cartocdn.com/dark_all                  |
| light      | Clair         | cartocdn.com/rastertiles/voyager       |

**Marqueurs** : icône bouclier SVG (`pgIcon(color)`) colorée vert/orange/rouge/gris.
Couleur = statut du jour sélectionné dans le toolbar.

**Popup graphique** (au clic sur marqueur, `position:fixed`) :
- Header : nom site + altitude + niveau + fenêtre solaire
- **Tuiles nuageuses** (3 tuiles/heure : haute/moy/basse)
  - Couleur `#4b8db5` (bleu-gris)
  - Opacité INVERSÉE : `(100 - cover%) / 100` → bleu = ciel dégagé, transparent = couvert
- **Bargraphes vent** (heure par heure) :
  - Max : fond orange transparent
  - Moy : vert plein (si favorable) / gris (si hors axe)
  - Min : bleu centré
- **Flèches direction** : `rotate(wind_dir + 180)` — pointe où le vent VA (convention usuelle)
  - Vert si dans l'axe du site (`wind_dir_min` ≤ dir ≤ `wind_dir_max`)
  - Rouge sinon
  - Gère le chevauchement Nord (ex: 315°→45°)
- Bouton "Détails ›" → ouvre side panel timeline

**Side panel** (timeline détaillée, s'ouvre sur "Détails ›") :
- Blocs colorés par heure, opacité = confiance
- Clic sur bloc → détail vent/confiance/précip/modèles

### Services météo

> **Consensus ET scoring déportés au sidecar (depuis 2026-06-09)** — le
> sidecar `consensus-grid-v2` calcule le consensus multi-modèles **et le
> scoring de volabilité** de chaque site, qu'il écrit **directement en
> base** dans le double-buffer `site_scores_1` / `site_scores_2` (pointeur
> `settings.scoring_table`). Laravel n'est plus que **lecteur**
> (`SiteScore::onActiveBuffer()`). La voting logic PHP, le fetch du
> consensus (`ConsensusApi`/`FetchConsensusBatchJob`), `ScoringService`
> et `ScoringRules` (le moteur de règles PHP) ont été **supprimés**.
> Le scoring perso est lui aussi déporté au sidecar
> (`POST /v1/scoring/custom` via `CustomScoringClient`). Cf. la sous-section
> *Scoring déporté* plus bas et `FF_personnal_scoring_sidecar.md`.
>
> Les ~20 modèles NWP restent fetchés par `OpenMeteoApi` dans `forecasts`,
> mais **uniquement** pour le panel multimodèles, `/carte-modeles` et la
> fiabilité — ils ne pilotent plus aucun statut.

**Architecture self-hosted (depuis PR5)** :
Le projet utilise un **serveur Open-Meteo dédié** (image `open-meteo/open-meteo`)
pour agréger 13 modèles publics. Plus de fetch direct sur les APIs externes
(Météo-France DPS, DWD OpenData, ECMWF Open Data, MET Norway sont supprimés).

URL configurable via `OPEN_METEO_BASE_URL` dans `.env` :
- dev local : `http://localhost:8888/v1`
- prod docker-compose : `http://open-meteo-api:8080/v1`

20 modèles connus (édition individuelle dans `/admin/models`) :
- **Météo-France** : `meteofrance_arome_france_hd`,
  `meteofrance_arome_france_hd_15min`, `meteofrance_arome_france0025`,
  `meteofrance_arpege_europe`.
- **DWD** : `dwd_icon_eu`, `dwd_icon_d2`, `dwd_icon`.
- **NOAA/NCEP** : `ncep_gfs013`, `ncep_gfs_graphcast025`,
  `ncep_aigfs025`, `ncep_aigefs025` (inactif), `ncep_hgefs025_ensemble_mean`
  (inactif).
- **ECMWF** : `ecmwf_ifs025`, `ecmwf_aifs025_single`.
- **Autres globaux / régionaux** : `ukmo_global_deterministic_10km`
  (routé sur l'API publique Open-Meteo, cf. plus bas), `cmc_gem_gdps`,
  `cmc_gem_rdps` (inactif, régional Amérique du Nord),
  `bom_access_global` (inactif), `cma_grapes_global` (inactif),
  `jma_gsm` (inactif).

Les ensembles *mean* (`ncep_hgefs025_ensemble_mean`) sont inactifs
par défaut : la moyenne lisse les pics de vent utiles au scoring
parapente. Les modèles hémisphère sud/Asie (BOM, CMA, JMA) restent
inactifs car peu pertinents pour des sites France/Bénélux.

**`OpenMeteoApi`** (`app/Services/Weather/Apis/`) :
- `fetchForSiteAndModel()` : 1 appel par (site, modèle), variables `HOURLY_VARS`
  (11 variables complètes pour le scoring + variables journalières).
- `fetchBatchForBalises()` : appel batch multi-coordonnées (40 points/chunk),
  variables `HOURLY_VARS_BALISES` (4 variables — subset minimal pour
  l'affichage, pas de scoring complet).
- Pas de pause entre appels (serveur dédié, pas de rate limit)
- Plafond (base des cumulus) — règle d'Espy avec température de déclenchement,
  en **altitude absolue (ASL)** : `cloud_base_m = elevation_modèle + 125 × (T₂ₘ_max_jour − Td₂ₘ)`
  (`temperature_2m_max` journalière, `dew_point_2m` ; `elevation` = point de grille
  du modèle, renvoyé par Open-Meteo ; fallbacks : T horaire / Td approx. depuis l'humidité).
  (Le plafond consensus est désormais calculé et stocké par le sidecar
  dans `site_scores_*.cloud_base_consensus`.)
- Format datetime `Y-m-d H:i:s` pour MariaDB (pas ISO avec `T`)

#### Scoring perso déporté au sidecar — `UserScoringService` + `CustomScoringClient`

Le scoring **personnalisé** (un utilisateur applique ses propres fenêtres
vent/plafond à un site) n'est plus calculé en PHP : il est lui aussi délégué
au sidecar, qui rejoue **la même logique** (`scoring.py`) que le scoring de
prod. Plus aucune règle éliminatoire en PHP (`ScoringRules` supprimé). Cf.
`FF_personnal_scoring_sidecar.md`.

- **`CustomScoringClient`** : client HTTP de `POST /v1/scoring/custom`.
  Tolérant aux pannes → renvoie `null` sur toute erreur (réseau, 4xx/5xx).
- **`UserScoringService`** : pour un utilisateur, envoie en **un seul appel
  batch** ses sites perso actifs (coords + conditions custom) + les seuils
  globaux (`Settings`) ; le sidecar lit son propre forecast et renvoie
  statut + couleurs par créneau. Résultat **caché en Redis par user**
  (clé `scoring_custom:user:{id}`, TTL 1 h). Sur panne sidecar : pas de
  cache, overlay vide → l'appelant sert le **scoring global** (fallback).
- **3 déclencheurs d'invalidation** : flip du buffer (`WatchScoringTableJob`
  → `invalidateAll()`), édition/activation/désactivation d'un scoring
  (`invalidateUser()`), changement d'un seuil global (`Settings::flush()`
  → `invalidateAll()`).
- Consommé par `SiteController::scores` (overlay du volet détail) et
  `MeScoringController` (overlay carte, recalcul `day_quality` perso via
  `DayQualityCalculator` — l'agrégation de viabilité reste en PHP).

> ⚠️ Le `forecast_at` renvoyé (ISO UTC) est aligné sur
> `site_scores.forecast_at` (clé `Y-m-d H:i:s`) pour que l'overlay retombe
> sur les bons créneaux du payload global.

#### Scoring déporté — double-buffer `site_scores_{1,2}`

Le sidecar `consensus-grid-v2` calcule le consensus **et** le scoring sur
toute la France, et écrit les résultats directement en base :

- **Double-buffer** : le sidecar écrit le buffer **inactif**
  (`DROP TABLE` + `CREATE TABLE … LIKE` + INSERT par lots), puis flippe
  atomiquement `settings.scoring_table` (`"1"`↔`"2"`). Zéro downtime côté
  lecture. `site_scores` (sans suffixe) reste **vide**, comme template DDL
  du bootstrap (premier run : `CREATE … LIKE site_scores`).
- **Lecture côté Laravel** : `SiteScore::activeTableName()` lit le pointeur
  **en SQL direct** (hors cache Redis du service Settings, sinon un flip
  passerait inaperçu jusqu'à 1 h) ; `SiteScore::onActiveBuffer()` cible la
  table active. Tous les lecteurs (payload builders, `MapBundleBuilder`,
  `UserScoringService`, `Site::nextScore/upcomingScores`) y passent.
- **Colonnes** : identiques à l'ancienne `site_scores` + `quality_detail`
  (JSON, scores qualité par profil — `quality_profiles`/`quality_axes` lus
  par le sidecar ; lecture brute côté app, affichage à venir).
- **Invalidation des caches** : `WatchScoringTableJob` (planifié chaque
  minute) compare le buffer actif à la dernière valeur vue ; au flip, il
  purge `SiteDetailCache` (tous sites) + map bundle + user-scoring, et
  dispatch `RebuildMapBundleJob`. Remplace l'ancien push de `ScoreSiteJob`.
- **Modèle `qui_vole_consensus` / API `consensus`** : **désactivés**
  (reliques). Exclus des comparaisons via `WeatherModel::CONSENSUS_CODE`
  (`SiteMultimodelPayloadBuilder`, `ModelGridController`).
- **Réversibilité** : aucune. Le scoring PHP a été supprimé (choix assumé).
  Si le sidecar décroche, les scores se figent (tables stales) jusqu'à son
  retour ; pas de fallback.
- Toute modif des payloads concernés ⇒ bump `SiteDetailCache::CACHE_VERSION`
  (actuellement **v3**) — cf. point 14.

> **Qualité d'une journée** — calculée à la lecture par
> `App\Services\Map\DayQualityCalculator::compute()` (pas en base) :
> viabilité 0-100 = Σ(poids horaire × valeur du statut × facteur de
> continuité) / Σ(poids horaire) sur la fenêtre solaire. Le poids
> horaire est une cloche centrée ~13h30 (créneaux du milieu de journée >
> très tôt/tard) ; le facteur de continuité pénalise les créneaux volables
> isolés (1 h → 40 %, ≥3 h consécutives → 100 %). Statut du jour dérivé :
> ≥35 vert, ≥12 orange, sinon rouge. Exposé dans `/api/sites/{id}/scores`
> (`day_quality`) et utilisé côté carte pour la couleur des marqueurs.
> Tous les paramètres (pic / σ / valeurs green/orange / run base/step /
> seuils) sont éditables depuis `/admin/settings` (clés `viability.*`).

### Sites masqués par utilisateur (« black-list » carte)

Un utilisateur **connecté** peut masquer de sa carte les sites qui ne
l'intéressent pas. Règle par défaut = **affiché** ; on ne stocke que
les exclusions (table `user_hidden_sites`, modèle d'exclusion pure).
Cf. `FF_site_blacklist.md`.

- **Gestion** : page dédiée `/profil/sites-masques`
  (`User\HiddenSitePageController` → vue `user.hidden-sites`), liée
  depuis le menu utilisateur et la page `/profil`. Liste des sites
  actifs avec bascule Masquer/Réafficher + **barre de filtrage**
  (nom, sélecteurs en cascade pays → région → département, état).
- **API** : `UserHiddenSiteController` (CRUD, `PUT`/`DELETE`
  idempotents) + overlay carte `MeHiddenSitesController`
  (`/api/me/hidden-sites`).
- **Carte** : `mapApp().loadHiddenSites()` charge l'overlay au boot
  (connecté uniquement) ; `_siteVisible()` masque les sites blacklistés
  (pas de toggle sur la carte — la page de gestion est le panneau de
  contrôle). Le compteur du sélecteur de jour (`days_summary`) est
  remplacé par la version recalculée **sans** les sites masqués.

### Cache des données carte — `App\Services\Map\*`

Tous les endpoints alimentant la vue carte sont **pré-calculés** en cache
Redis pour soulager la DB et accélérer le boot mobile :

- **`MapBundleBuilder`** (`map.bundle.v3`, TTL 90 min)
  - Construit le bundle global servi à `/api/map-bundle` : tous les sites
    actifs avec leurs statuts journaliers (issus du buffer de scoring actif
    `site_scores_{1,2}` via `SiteScore::onActiveBuffer()`), les fenêtres
    solaires, et l'agrégat global par jour (best_status, green_slots cumulés).
  - L'agrégat journalier est calculé par la méthode **statique pure
    `summarizeDays($sitesPayload, $excludedSiteIds = [])`**, réutilisée
    par l'overlay « sites masqués » (`MeHiddenSitesController`) pour
    recalculer le compteur sans les sites masqués d'un utilisateur.
  - Le bundle **caché** porte `green_hours_set` par site (heures green
    par jour), nécessaire à ce recalcul ; **`MapBundleController::show`
    le retire du payload client** (pas de bloat front). Toute modif de
    cette structure ⇒ bump `CACHE_VERSION` (actuellement **3**).
  - Régénéré par `RebuildMapBundleJob`, dispatché par `WatchScoringTableJob`
    au flip du buffer de scoring (sidecar) — et par les observers Site/Balise.
  - Fallback lazy : si la clé est absente (TTL expiré, flush), reconstruction
    à la volée avec lock Redis anti-thundering-herd.
  - Commande : `php artisan map:rebuild-bundle` (force la régénération).

- **`SiteDetailCache`** (`map.site_{scores|chart|multimodel}.{id}.{...}.v3`, TTL 90 min)
  - Cache transparent des endpoints `/api/sites/{id}/{scores,chart,multimodel}`.
  - `scores` : version "global" cachée ; si l'utilisateur a un scoring perso
    ACTIF sur ce site, le contrôleur applique le rescore par-dessus
    (status + couleurs + recalc day_quality) — sinon le cache est servi tel
    quel (cas dominant).
  - `chart` et `multimodel` : cache complet (pas de logique user-spec).
    `multimodel` est caché par couple `(day, period)`.
  - Invalidation : `WatchScoringTableJob` (flip du buffer → `forgetSite` sur
    tous les sites) + `FetchSiteForecastsJob` (refresh forecasts d'un site).

- **`BalisesBundleCache`** (`map.balises.bundle.v1` + `map.balises.history.{id}.v1`)
  - `/api/balises` : TTL 5 min (les readings bougent vite). Invalidé par
    les 3 jobs `Fetch{PiouPiou,Metar,Windy}ReadingsJob` à la fin de leur cycle.
  - `/api/balises/{id}/history` : TTL 2 min, par balise. Pas d'invalidation
    push (le TTL court suffit, on évite un scan Redis).

Helpers partagés :
- `SunWindowCalculator::compute($lat, $lng, $day)` — fenêtres solaires
  (statique, sans dépendance).
- `DayQualityCalculator::compute($byHour, $window)` — viabilité d'une
  journée (dépend de `Settings` via DI).

> **CACHE_VERSION** : chaque service Map expose une constante
> `CACHE_VERSION` intégrée dans la clé Redis. Toute modification de la
> structure du payload (ajout/retrait de champ, changement de type)
> DOIT s'accompagner d'un bump de cette constante — sinon les vieilles
> entrées Redis peuvent casser la vue (cf. point 11 plus bas).

### Tampon d'icônes SpotAir — `App\Http\Controllers\IconCacheController`

Les icônes des marqueurs (sites + balises) sont fournies par l'API
SpotAir (`https://www.spotair.mobi/icones/...`), qui génère un SVG
unique par combinaison de paramètres. Pour éviter de hammer leur
API à chaque visite et accélérer le boot carte, toutes les icônes
transitent par un **tampon disque** côté serveur :

- URLs publiques : `/icons-cache/site/{p}/{t}/{n}/{o}.svg` et
  `/icons-cache/balise/{d}/{v}/{t}/{bg}/{c}.svg`.
- Stockage : `public/icons-cache/{site|balise}/.../*.svg` (gitignored).
- **Nginx sert le fichier directement** dès le second hit grâce au
  bloc `location ^~ /icons-cache/` qui prend la priorité sur la regex
  statique `\.svg$` et fait un `try_files` avec fallback Laravel.
- Au premier hit pour une combinaison, le contrôleur fetch SpotAir,
  écrit le SVG sur disque (atomique : `tmp + rename`), puis renvoie
  le corps. PHP n'est jamais rappelé pour cette combinaison ensuite.
- Reset : `rm -rf public/icons-cache/` (ou un sous-dossier précis).
  Pas de cache Redis impliqué, pas de commande artisan dédiée — c'est
  voulu (le système de fichiers EST la source de vérité).

> **Permissions** — `public/icons-cache/` est créé à la volée par
> PHP-FPM (`www-data`). Si Docker monte `src/` avec un autre
> propriétaire (cas fréquent en dev), prévoir un
> `chown -R www-data:www-data public/icons-cache/` après le premier
> déploiement, sinon le contrôleur renvoie 500 sur le `mkdir`.

> **Rotation** — le tampon est aujourd'hui *write-once*. Une rotation
> lente et plafonnée (pour absorber les changements de charte SpotAir)
> est cadrée dans `FF_icon_cache_rotation.md`, à implémenter plus tard.

### Géocodage administratif — `App\Services\Geocoding\*`

Les sites et balises sont enrichis (pays / région / département) via
un orchestrateur 2 tiers :
1. **geo.api.gouv.fr** — point-in-polygon sur les communes françaises
   (métropole + DOM). Sans rate limit, couvre 100 % des coords FR.
2. **Nominatim** (OSM public) — fallback monde entier, rate-limité
   1 req/s via `Cache::lock()` distribué (multi-worker safe). Sert
   pour la Belgique, le Luxembourg, l'Allemagne, etc.

Une tentative initiale via BAN (`api-adresse.data.gouv.fr`) a été
abandonnée : BAN renvoie `not-found` pour la majorité des sites de
parapente (loin de toute adresse postale indexée). Cf.
`FF_location_enrichment.md` pour l'historique.

**Déclencheurs** :
- **À la création / modif de coords** : `GeocodableObserver` (Site et
  Balise) → dispatch `GeocodeLocationJob` (async, tries=3 backoff
  exponentiel 60/300/900 s).
- **En masse** : `php artisan geocode:locations [--sites] [--balises]
  [--force] [--chunk=200] [--limit=N]`. Idempotente — sans `--force`,
  ne traite que `geocoded_at IS NULL`. ~2 min pour 1100 sites + 270 balises.

**Filtres admin** : `/admin/sites` et `/admin/balises` exposent les 3
filtres (pays / région admin. / département) via la trait
`HasFilterableIndex`.

**Config** (`config/services.php`, bloc `geocoding`) :
- `GEO_API_GOUV_BASE_URL` (défaut `https://geo.api.gouv.fr`)
- `NOMINATIM_USER_AGENT` (défaut `qui-vole.fr`)
- `NOMINATIM_CONTACT_EMAIL` (à renseigner en prod pour identification polie)
- `NOMINATIM_RATE_LIMIT` (défaut `1` seconde entre appels)

### Invalidation du map bundle et fetch immédiat — observers

Trois observers automatisent l'invalidation et le rafraîchissement
du cache map sans intervention manuelle :

- **`MapBundleInvalidationObserver`** (Site + Balise) — dispatch
  `RebuildMapBundleJob` avec un délai de 5 s sur `created/updated/deleted`,
  uniquement si un champ visible carte a changé (`active`, `latitude`,
  `longitude`, `name`, `altitude_m`, `level`, `landing_lat`,
  `landing_lng`). `RebuildMapBundleJob` est `ShouldBeUnique` avec
  `uniqueFor=30s` et `uniqueId='rebuild-map-bundle'` → 100 toggles en
  rafale produisent **un seul** rebuild. Combiné au délai 5 s, l'effet
  est un debounce naturel.

- **`SiteActivationObserver`** (Site uniquement) — dispatch
  `FetchSiteForecastsJob` sur création d'un site déjà actif OU
  transition inactif → actif, pour alimenter immédiatement les
  prévisions par modèle (`forecasts`, panel multimodèles). ⚠️ **Plus de
  scoring immédiat** : les statuts viennent du sidecar ; un site
  fraîchement activé reste « inconnu » jusqu'au prochain run de celui-ci
  (le marqueur, lui, apparaît via `MapBundleInvalidationObserver`).

- **`GeocodableObserver`** (Site + Balise) — cf. section géocodage
  ci-dessus.

**Limite des observers Eloquent** : ils ne se déclenchent **pas** sur
les `Builder::update()` en masse (tinker, SQL direct via PHPMyAdmin).
Dans ce cas, lancer manuellement `php artisan map:rebuild-bundle` (et
au besoin `php artisan geocode:locations`).

### Paramètres globaux — `App\Services\Settings`

Tous les seuils du scoring (précipitations, rafales, viabilité du jour)
sont stockés dans la table `settings` et accessibles via le service
`App\Services\Settings` (injection DI). API : `get($key, $default)`,
`all()`, `set($key, $value)`, `setMany([...])`, `flush()`. Cache Redis 1 h
invalidé à chaque écriture. Catalogue des défauts dans `Settings::DEFAULTS`
(sert aussi de référence pour le seeder et l'écran admin). Édition par
l'admin : `/admin/settings` (lien « Paramètres » dans la sidebar admin).
Seeder : `php artisan db:seed --class=SettingsSeeder --force` (idempotent).

### Convention direction vent (IMPORTANT)
`wind_dir_consensus` dans `site_scores` = direction **FROM** (convention météo standard).
- Est = 90°, Ouest = 270°, Nord = 0°/360°
- Même convention dans `site_conditions.wind_dir_min/max`
- Dans `buildChartSVG` : flèche = `rotate(wind_dir + 180)` pour montrer où le vent VA

### Jobs
- `FetchForecastsJob` : cron horaire. Construit une chaîne `FetchSiteModelJob×N`
  par site (modèles dûs pour refresh) dans un `Bus::batch()` (`allowFailures`).
  **Ne calcule plus aucun score** (déporté au sidecar) : alimente seulement
  `forecasts` pour le panel multimodèles / `/carte-modeles` / fiabilité.
- `FetchSiteModelJob` : fetch + upsert `forecasts` d'un (site, modèle). Plus
  de scoring.
- `WatchScoringTableJob` : cron **chaque minute**. Détecte le flip de
  `settings.scoring_table` (sidecar) → invalide `SiteDetailCache` (tous sites)
  + map bundle + user-scoring, et dispatch `RebuildMapBundleJob`. C'est le
  point d'invalidation du scoring déporté.
- `FetchSiteForecastsJob` : refresh `forecasts` d'un site (sync, activation /
  tinker) → invalide `SiteDetailCache` du site. **Pas de scoring** (au prochain
  run sidecar). Timeout 300s.
- `RebuildMapBundleJob` : régénère le map bundle global et l'écrit en
  cache Redis. Idempotent.
- **`FetchBaliseReadingsJob`** (abstrait) : base mutualisée des 3 jobs
  balises. Les sous-classes ne déclarent que `source()`, `provider()` et
  leur timeout. La logique commune (fetch → insert sans doublon →
  désactivation des balises mortes → invalidation du bundle) vit dans
  la base. Cache Redis `balises.last_reading_by_balise:{source}` (TTL 1 h)
  pour éviter un `GROUP BY MAX(read_at)` SQL à chaque tick.
- `FetchPiouPiouReadingsJob`, `FetchMetarReadingsJob`,
  `FetchWindyReadingsJob` : étendent `FetchBaliseReadingsJob`. Windy
  parallélise ses appels HTTP via `Http::pool()` (chunks de 10 simultanés).
- `FetchBaliseForecastsJob` : cron horaire. Archive les prévisions des
  13 modèles aux coords de chaque balise active, dans
  `forecast_archive_balises`. Ventile par `horizon_bucket` (nowcast /
  same_day / j_plus_1 / j_plus_2).
- `AggregateBaliseReadingsHourlyJob` : cron horaire à `:05`. Agrège
  `balise_readings` en buckets horaires alignés sur l'heure pile dans
  `balise_readings_hourly` (moyenne circulaire pour la direction,
  AVG/MAX pour les vitesses). Fenêtre glissante 3 h pour les retards.
- `ComputeBaliseConsensusCompareJob` : cron horaire à `:10` (cf. FF
  phase 2.5). Calcule les 3 consensus (A legacy, B amélioré, C
  amélioré + fiabilité) pour les balises du panel
  `in_consensus_compare_panel`, fenêtre ±72 h. Honore le kill switch
  `reliability.shadow_enabled`. **Pas d'impact sur le scoring prod.**
- `ComputeModelReliabilityJob` : cron quotidien à 03:30. Recalcule
  MAE/RMSE/biais/`weight_factor` par modèle × balise × bucket ×
  variable sur la fenêtre glissante `reliability.window_days` (7 j).

### Fiabilité des modèles — `App\Services\Weather\Reliability\*` (phase 2.5)

Système de **shadow mode** qui calcule en continu trois consensus
alternatifs (A legacy, B amélioré sans fiabilité, C amélioré +
pondération dynamique) pour quelques balises de référence, et les
confronte à la vérité-terrain. **Aucun impact sur le scoring de prod**
— sert à valider chiffres en main une éventuelle évolution future de la
voting logic. Cf. `FF_model_reliability.md`.

- **Panel de balises** : drapeau `balises.in_consensus_compare_panel`
  (boolean, par défaut false). Toggle sur la fiche admin
  `/admin/balises/{id}`. Distinct de `reliability_class` (pro/amateur).
- **3 consensus** stockés dans `balise_consensus_compare` :
  - **A — legacy** : reproduction stricte de `ScoringService` (inverse-carré
    EPSILON 0.001, moyenne circulaire pondérée).
  - **B — amélioré** : EPSILON revu, filtrage outliers MAD intra-créneau,
    médiane pondérée. Tous les modèles à poids 1.0.
  - **C — amélioré + fiabilité** : idem B + pondération par
    `weight_factor` issu de `model_reliability`.
- **Variables** : `wind_speed_avg`, `wind_speed_max`, `wind_direction`
  (la direction utilise la distance circulaire pour MAE/RMSE et la
  différence circulaire signée pour le biais).
- **Paramètres `reliability.*`** dans `settings` (groupe « Fiabilité des
  modèles » dans `/admin/settings`) : `shadow_enabled`, `epsilon_new`,
  `mad_floor`, `z_outlier_threshold`, `dir_mad_z_threshold`,
  `use_weighted_median`, `use_mad_filtering`, `min_samples`,
  `factor_min`, `factor_max`, `window_days`.
- **Écrans admin** :
  - `/admin/reliability/compare` — tableau J / J+1 / J+2 par balise et
    variable, avec MAE A/B/C et badge 🏆 sur le meilleur.
  - `/admin/reliability/models` — pivot modèle × bucket avec MAE,
    biais signé, `weight_factor`, `samples_n`. Bouton « Recalculer
    maintenant » (dispatch sync).
- **Exports** :
  - `GET /admin/reliability/export.json` — payload complet horodaté
    (paramètres + panel + datasets + stats MAE agrégées).
  - `GET /admin/reliability/export/consensus-compare.csv` et
    `/model-reliability.csv` — CSV streamé (BOM UTF-8), filtres par
    balise/variable.
  - Le fichier `RELIABILITY_ANALYSIS_CONTEXT.md` (racine du repo)
    accompagne ces exports pour permettre à un Claude analyste de
    faire des points d'étape réguliers sans contexte préalable.
- **Commandes utilitaires** :
  - `php artisan reliability:compute-compare [--balise=ID] [--hours=72]`
    — équivalent sync de `ComputeBaliseConsensusCompareJob`.
  - `php artisan reliability:compute-factors [--balise=ID] [--days=N]`
    — équivalent sync de `ComputeModelReliabilityJob`.
- **Tests** : `tests/Unit/Weather/Reliability/ConsensusCalculatorTest`
  (13 tests, classe pure sans DB).

> **Quand `weight_factor` reste à 1.0** : on est en *cold start*
> (`samples_n < reliability.min_samples`, 50 par défaut). C'est attendu
> les premiers 3-7 jours. C est alors strictement identique à B.

### Module front « Carte des modèles » — `/carte-modeles`

Visualisation didactique de la grille d'un modèle météo NWP avec
coloration par fiabilité agrégée des balises du panel tombant dans
chaque cellule. Module utilisateur (table `modules`, key
`model-grid`), **caché aux non-admins** via `access_level = 'admin'`
le temps que la couverture du panel soit suffisante. Pas une page
sous `/admin/` — c'est conceptuellement une page front filtrée par
visibility module + middleware.

- **Service `App\Services\Map\ModelGridBuilder`** : construit la
  grille en GeoJSON sur une bbox. Alignement standard 0°/0°, pas
  constant = `resolution_km / 111`. Pour chaque cellule, identifie
  les balises du panel dedans, puis agrège `model_reliability`
  (moyenne pondérée par `samples_n` pour `mae`/`weight_factor`/
  `bias_signed`, somme pour `samples_n`).
- **Mode grille complète** (`show_empty=true`, défaut) : ajoute aussi
  les cellules vides (juste contour léger), montre la maille effective
  du modèle. Anti-overload : > 16 000 cellules → `meta.too_large=true`
  + zoom recommandé renvoyé au front (qui affiche « Zoom davantage »).
- **Zoom minimum auto-calculé** par modèle :
  `ceil(log2(360 / (resolution_deg × 64))) + 2`. Le `+ 2` compense le
  ratio largeur/hauteur de la viewport et garantit que les DivIcons
  centrales tiennent dans la cellule. Exemples :
  - ICON Global (13 km) → zoom 7
  - ICON-EU (6.5 km) → zoom 9
  - AROME 0.025° (2.5 km) → zoom 11
  - AROME-HD (1.3 km) → zoom 12
- **Vue Leaflet plein écran** (`resources/views/model-grid/index.blade.php`) :
  - Toolbar : 4 dropdowns (modèle / variable / horizon / métrique) +
    checkbox grille complète + toggle fond clair/sombre (CartoDB
    Voyager par défaut, dark_all en option).
  - Polygones colorés selon métrique : `weight_factor` (gradient
    rouge < 0.5 → gris ≈ 1 → emeraude > 1.5) ou `mae` (gradient vert
    → rouge avec seuils différents pour vitesses vs direction).
  - DivIcons SVG au centre des cellules occupées : rond gris/vert
    pour `samples_n` (seuil 50), flèche ↑ rouge / ↓ bleue pour le
    bias signé (seuils 0.5 km/h vitesses, 5° direction).
  - Légende : implémentée comme `L.Control` natif (position
    `bottomleft`), évite les soucis de z-index avec les panes Leaflet.
  - Popup au clic sur cellule : balises présentes + métriques détaillées.
- **Routes** (hors groupe `/admin`, middleware `['auth', 'admin']`) :
  - `GET /carte-modeles` → page (`model-grid.index`)
  - `GET /carte-modeles/data` → endpoint GeoJSON (`model-grid.data`)
- **Module** : migration `2026_05_19_100000_add_model_grid_module.php`
  (idempotente) + entrée correspondante dans `ModuleSeeder`.

> **Pour rendre le module public** quand la couverture sera
> suffisante (intégration future FFVL) : aller dans
> `/admin/modules` et passer `access_level` à `'user'` ou `'guest'`.
> Aucun code à modifier.

---

## Module — Carte météo (`/carte-meteo`, IMPLÉMENTÉ)

> Renommage : ce qu'on appelait « Carte météo » jusqu'au 2026-05-23
> est désormais « Carte de volabilité ». Le module décrit ici est le
> NOUVEAU module qui visualise les overlays météo bruts du sidecar
> `parapente-consensus-grid`.

Module front qui superpose des overlays raster (vent, températures,
nuages, indicateurs convectifs, plafond de vol estimé, risque orageux…)
sur une carte Leaflet. Les couches viennent d'un sidecar Python
`consensus-grid` qui produit toutes les heures un NetCDF de consensus
multi-modèles + 1 PNG pré-rendu par (variable × step horaire).

**Module en table `modules`** : key=`weather-map`, route=`weather-map.index`,
`access_level='admin'` (caché aux non-admins le temps que les couches
soient stabilisées).

### Architecture côté Laravel — `WeatherMapController`

Quatre routes, toutes sous middleware `['auth', 'admin']` :

| Route | Rôle |
|---|---|
| `GET /carte-meteo` | Vue Blade (Leaflet plein écran). |
| `GET /carte-meteo/manifest` | Proxy de `/v1/overlay` du sidecar. **Cache Redis 10 min uniquement sur succès** ; les erreurs ne sont jamais cachées (évite qu'un hoquet sidecar fige la carte pour 10 min). |
| `GET /carte-meteo/overlay/{variable}/{step}.png` | Fallback dev (en prod, nginx intercepte avant que PHP soit appelé — cf. plus bas). |
| `GET /carte-meteo/health` | Proxy `/health`, polled toutes les 60 s côté front. |
| `GET /carte-meteo/progress` | Proxy `/progress`, polled toutes les 30 s pour le badge « run en cours ». |

**Config** : `services.consensus_grid.base_url` (variable d'env
`CONSENSUS_GRID_BASE_URL`, défaut `http://consensus-grid-v2-api:8082`) et
`CONSENSUS_GRID_TIMEOUT`. Routes déclarées sous le groupe
`middleware(['auth', 'admin'])` de `routes/web.php` à côté de
`carte-modeles`.

### Architecture nginx — `proxy_pass` direct vers le sidecar

```nginx
location ~ ^/carte-meteo/overlay/(.+)$ {
    set $consensus_grid_upstream "consensus-grid-v2-api:8082";
    proxy_pass http://$consensus_grid_upstream/v1/overlay/$1$is_args$args;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    ...
    access_log off;
}
```

Trois subtilités structurantes :

1. **Forme regex + URI explicite** : la combinaison `rewrite + proxy_pass http://$variable;` est buggée dans certaines versions de nginx (renvoie 500 silencieusement). On utilise donc une `location ~ ^…(.+)$` avec capture, et on injecte explicitement `$1$is_args$args` dans l'URL en aval pour préserver la query string (notamment `?run=…`).
2. **DNS dynamique** : `resolver 127.0.0.11 valid=10s;` au scope serveur. Couplé à `proxy_pass http://$variable;`, nginx re-résout le DNS Docker à chaque cycle au lieu de geler l'IP au démarrage. Sans ça, tout `docker compose recreate consensus-grid-v2-api` cassait les overlays jusqu'au prochain restart nginx.
3. **Pas de cache nginx** : le sidecar pré-rend les PNG sur disque et les sert en ~5 ms via `FileResponse`. Le HTTP cache navigateur (`Cache-Control: public, max-age=3600`) + le cache-buster `?run=<run_init_unix>` côté front suffisent. Pas de `proxy_cache_path`, pas de volume dédié.

Conteneur `parapente_nginx` attaché à `meteo-net` dans
`docker-compose.prod.yml` pour pouvoir résoudre `consensus-grid-v2-api:8082`.
Le sidecar doit lui-même déclarer `meteo-net` comme `external: true`
dans son propre `docker-compose.prod.yml` pour que l'alias DNS (nom de
service `consensus-grid-v2-api`) soit enregistré au démarrage.

### Front (`weather-map/index.blade.php`)

Une seule vue Blade, un seul script inline, pas de prefetcher
asynchrone (le sidecar est assez rapide pour servir à la demande).

**Toolbar** : sélecteur de variable (peuplé dynamiquement depuis le
manifest), sélecteur de fond, slider d'opacité, sélecteurs Jour+Heure,
bouton Play/Pause + cadence (0.5/1/2/5 s par créneau, raccourci
clavier Espace).

**Fonds de carte (5 options)** :
| Clé | Label | URL |
|---|---|---|
| `topo` | OpenTopoMap (relief) | `tile.opentopomap.org` (défaut) |
| `osm` | OSM standard | `tile.openstreetmap.org` |
| `satellite` | Satellite + noms | `World_Imagery` Esri + overlay `World_Boundaries_and_Places` (pane `wm-labels` z-index 380 pour rester lisible sous l'overlay météo z-index 350) |
| `light` | Clair | CartoDB Voyager |
| `dark` | Sombre | CartoDB dark_all |

**Étages de panes Leaflet** (z-index) :
- `200` (tiles, défaut Leaflet) — fond de carte
- `350` `wm-overlay` — PNG d'overlay météo (avec `image-rendering: pixelated`
  pour ne pas flouter la résolution native du modèle)
- `380` `wm-labels` — labels du satellite (uniquement actif en mode
  Satellite + noms)
- `400` `wm-arrows` — flèches de vent vectorielles SVG

**Flèches de vent** : décodées **côté navigateur** depuis le PNG
`wind_direction_10m`. La cmap HSV de matplotlib mappe linéairement
input → teinte H (0–360°), donc `RGB → H = direction FROM`. Canvas
offscreen, `getImageData`, sampling à une densité adaptative au
viewport (1 flèche / ~30 px écran, plafonnée à 6000 markers, redessinées
à chaque pan/zoom debouncé). SVG minimaliste en stroke (path
`M11 18 V4 M6 10 L11 4 L16 10`, halo blanc + trait sombre) — beaucoup
plus léger à rendre que l'ancien polygon plein. Cache mémoire
`arrowCache` indexé par step (ImageData décodée), purgé au changement
de run du sidecar.

**Légende** :
- Lit `palette.stops` du manifest sidecar pour construire un
  `linear-gradient` CSS exact, quelle que soit la cmap matplotlib
  (built-in ou custom). Trois emplacements possibles tolérés :
  `info.cmap_stops`, `info.stops`, `info.palette.stops`.
- **Détection des cmaps alpha-encoded** (`clouds_alpha`, `cape_alpha`,
  `cin_alpha`, etc.) : le sidecar ne peut pas exposer le canal alpha
  dans le sampling, toutes les stops ressortent avec la même couleur
  RGB. Le front détecte ce cas (`uniqueColors.size === 1`) et affiche
  un fondu `transparent → couleur opaque` qui rend correctement la
  sémantique « basse intensité = transparent, haute intensité = opaque ».
- 5 graduations linéaires évenly-spaced (4 pour `qui_vole_storm_risk`
  qui est catégoriel 0..3).
- Vents : conversion m/s → km/h à l'affichage (× 3.6, entiers).
  Le PNG reste mappé sur la grille m/s côté sidecar, seul l'axe de
  la légende est converti.
- Fallback : `CMAP_CSS` (table CSS hardcodée matplotlib standards)
  + `VAR_DEFAULT_CMAP` (palette sémantique par variable, ex.
  `precipitation` → toujours rendu en `Blues`, peu importe ce que
  raconte le manifest). Évite qu'un overlay bleu se retrouve avec
  une légende vert→rouge.
- Tous les libellés et palettes ont un **lookup tolérant** (lowercase
  + retrait des underscores) — résiste aux dérives de nommage style
  `temperature_850hpa` vs `temperature_850hPa`, `dewpoint_2m` vs
  `dew_point_2m`.

**Player horaire** : `setInterval` qui incrémente `currentStepIx`,
boucle à la fin de la prévision (~120 h). Sélecteurs Jour/Heure
synchronisés en live ; les manipulations manuelles mettent en pause.
Token de séquence dans `drawArrows` pour éviter les rendus périmés
lors de scrubs rapides.

**Détection live de nouveau run** : poll du manifest toutes les 60 s,
si `run_init_unix` change → purge `arrowCache`, redraw overlay + flèches,
les URLs incluent `?run=<run_init_unix>` donc le navigateur invalide
automatiquement son HTTP cache.

**Badge `/progress`** : pendant qu'un run consensus tourne côté
sidecar, affiche `run en cours : variable (xx %, N min)`. Caché en
`idle` / `completed` / `failed` (sauf en cas d'erreur, où il bascule
en rouge avec le détail).

### Variables exposées (~30 layers — sidecar v6)

Le sélecteur de variable filtre les `wind_direction_*` (consommés en
interne pour les flèches, pas montrés comme couches indépendantes).
Les autres sont organisés par catégories dans `VAR_LABELS` :

- **Surface 10 m / 2 m** : `wind_speed_10m`, `wind_gusts_10m`,
  `precipitation`, `relative_humidity_2m`, `temperature_2m`,
  `dew_point_2m`, `cloud_cover_{low,mid,high}`, `visibility`,
  `weather_code`, `shortwave_radiation`
- **Vent altitude AGL** : `wind_{speed,direction}_{80m,120m,180m}`
- **Altitude ~1500 m / 850 hPa** : `temperature_850hPa`,
  `wind_speed_850hPa`, `wind_direction_850hPa`, `cloud_cover_850hPa`,
  `relative_humidity_850hPa`
- **Indicateurs convectifs / orageux** : `cape`, `convective_inhibition`,
  `convective_precipitation`, `freezing_level_height`
- **Variables propriétaires Qui-Vole** : `qui_vole_cloud_base` (plafond
  de vol estimé via Espy côté sidecar), `qui_vole_storm_risk` (0..3)

Tous les labels sont en français parapente : « Vent au sol — moyen
(km/h) », « Isotherme 0 °C — altitude (m) », « Base des nuages — plafond
de vol (m) », etc., avec **lookup tolérant** (le sélecteur est peuplé
dynamiquement depuis le manifest, un nom inconnu retombe sur lui-même).
La conversion m/s → km/h pour les vents se fait à l'affichage uniquement.

### Points d'attention

- **Le sidecar doit être sur `meteo-net`** (et déclarer cet alias DNS
  via `networks.meteo-net.external: true` dans son propre compose).
  Sinon, `consensus-grid-v2-api` n'est pas résolvable depuis nginx.
- **`wind_direction_10m` reste avec cmap HSV** côté sidecar — c'est la
  source de données pour la couche flèches client-side. Si la cmap
  change, le décodeur RGB → angle casse silencieusement.
- **`palette.stops` est best-effort** : si le sidecar évolue sans
  exposer les stops, le front retombe sur sa table CSS hardcodée +
  palette sémantique par variable (donc pas de régression mais légende
  approximative).
- L'overlay PNG utilise `image-rendering: pixelated` pour ne pas
  flouter la résolution native du modèle. Ne pas migrer vers un
  filtrage lissé sans accord — c'est volontaire pour montrer la maille
  effective ~2.5 km de la grille consensus.

---

## Modules futurs

### Module 2 — Journal de vol
Carnet de vol numérique par utilisateur. Nécessite authentification.

### Module 3 — Comparatif voiles & sellettes
Base de données équipements avec comparaison et notation communautaire.

> Les balises (PiouPiou, METAR, Windy) sont **implémentées** depuis
> V2.x — cf. la section *Jobs* plus haut.

---

## À faire plus tard (backlog)

- **Fonction « I am here »** sur la carte : poser un marqueur (position
  saisie ou géoloc) et filtrer les sites situés à moins de X minutes de route
  de ce point (calcul d'isochrone / temps de trajet).
- **Contenu du wiki technique** : la coque `/aide` est en place (cf. plus
  haut, `WikiPage` / `WikiController`) — reste à rédiger les pages
  attendues (sources de données, modèles météo, voting logic, plafond /
  Espy, fenêtre solaire, rétroaction balises, etc.).

---

## Conventions de code

### PHP / Laravel
- PSR-12 strict, `declare(strict_types=1)` en tête de chaque fichier
- Services dans `app/Services/` — logique métier jamais dans les controllers
- Jobs dans `app/Jobs/` — tout traitement async passe par la queue Redis
- Toujours Eloquent, pas de SQL brut

### Frontend
- **Pas de classes Tailwind dans la vue carte** — CSS inline uniquement (évite dépendance build)
- Alpine.js sans composants imbriqués dans la vue carte
- Leaflet.js pour tout ce qui est cartographique
- Les dropdowns au-dessus de la carte : `position:fixed` + `getBoundingClientRect()`.
  `@floating-ui/dom` est installé (exposé via `window.FloatingUI = { computePosition, offset, flip, shift, autoUpdate }`)
  pour les migrations futures vers un positionnement automatique (flip/shift sur les bords).
- Le SVG du popup est généré dynamiquement par `buildChartSVG(dayData, siteInfo)` en JS pur.
  Les éléments SVG sont créés via `svgHelpers(svg).mk` / `svgHelpers(svg).txt` (helper
  partagé dans `geometry.blade.php`).
- **CSS custom properties** dans `map/_partials/styles/base.blade.php` (`:root`) pour les
  valeurs très répétées : `--font-mono`, `--c-bg-dark`, `--c-border-25/4/5`. Toute nouvelle
  valeur répétée ≥ 3 fois → l'ajouter en var plutôt que la dupliquer.
- **Helper global JS** : `window.AppShell.isDesktop()` (≥ 1024 px, aligné sur le `lg:`
  Tailwind) — utilisé par le `<x-app-shell>` lui-même et par `mapApp()` pour piloter
  l'ouverture par défaut des volets latéraux. Single source of truth pour ce breakpoint.

### BackOffice (admin)
- **Composants standardisés** dans `resources/views/components/admin/` —
  utiliser systématiquement plutôt que de copier-coller des classes Tailwind :
  - `<x-admin.button>` (variants `primary|secondary|danger|ghost`, sizes
    `sm|md`, prop `icon` qui wrappe automatiquement les classes FA
    `fa-...` dans `<i>`).
  - `<x-admin.page-title title="..." subtitle="...">` — titre h1 + ligne
    descriptive cohérents, slot `actions` à droite, slot `subtitle` si
    le sous-titre contient du HTML ou des apostrophes (préférer le slot
    quand on a un doute — l'attribut `:subtitle=` casse vite avec une
    apostrophe dans une chaîne double-quotée).
  - `<x-admin.badge status="published|draft|active|admin|info|warning|danger|neutral|...">` —
    statuts cohérents (palette dot+texte ou icône+texte).
  - `<x-admin.empty-state icon="fa-..." message="...">` — états vides
    cohérents, slot `actions` possible.
  - `<x-admin.alert type="success|error|warning|info">` — bandeau
    d'alerte. Pas besoin de l'utiliser pour `session('status')` — le
    layout admin l'affiche déjà globalement.
  - `<x-admin.input name="..." label="..." type="..." hint="..." />` —
    champ de saisie standard (lit `old()` et `$errors` auto). Pour les
    `<textarea>` / `<select>`, utiliser `<x-admin.field>` qui wrappe
    label/erreur autour d'un slot libre.
  - `<x-admin.section title="..." icon="fa-..." color="gray|sky|violet|emerald|amber|red">` —
    section thématique de formulaire.
- **Trait `HasFilterableIndex`** (`app/Http/Controllers/Concerns/`) pour les controllers
  admin de listing : `applySearch(query, term, columns)`, `applyTriStateFilter(query, raw, column)`,
  `applySorting(query, request, allowed, defaultSort, defaultDir)` (whitelist anti-injection
  sur `orderBy`).

### Base de données
- Toujours migrations Laravel, jamais de modif manuelle
- snake_case pour les colonnes
- Indexes sur FK et colonnes fréquemment filtrées

---

## Commandes utiles

```bash
# Démarrer l'environnement
docker compose up -d

# Accéder au container PHP
docker exec -it parapente_php bash

# Vider les caches (après modif vue/config)
docker exec -it parapente_php php artisan optimize:clear

# Rebuild assets Vite (si modif JS/CSS hors vue carte)
docker exec -it parapente_php npm run build

# Lancer les migrations
docker exec -it parapente_php php artisan migrate

# Tester le fetch météo d'un site (id=1 = Volmerange)
docker exec -it parapente_php php artisan tinker
# >>> \App\Jobs\FetchSiteForecastsJob::dispatchSync(1);

# Traiter la queue manuellement
docker exec -it parapente_php php artisan queue:work --queue=meteo

# Voir les logs du worker
docker logs parapente_worker -f

# Régénérer manuellement le bundle map Redis (force) ou le vider
docker exec -it parapente_php php artisan map:rebuild-bundle
docker exec -it parapente_php php artisan map:rebuild-bundle --clear

# Géocoder en masse les sites/balises (cf. FF_location_enrichment.md)
docker exec -it parapente_php php artisan geocode:locations
docker exec -it parapente_php php artisan geocode:locations --sites --force
docker exec -it parapente_php php artisan geocode:locations --balises --limit=5  # test

# Fiabilité des modèles — shadow mode (cf. FF_model_reliability.md, phase 2.5)
docker exec -it parapente_php php artisan reliability:compute-compare              # toutes balises panel, ±72h
docker exec -it parapente_php php artisan reliability:compute-compare --balise=12  # une seule balise
docker exec -it parapente_php php artisan reliability:compute-factors              # MAE / weight_factor
docker exec -it parapente_php php artisan reliability:compute-factors --days=14    # fenêtre étendue

# Vérifier les données en base (lire TOUJOURS via le buffer actif)
# >>> \App\Models\SiteScore::onActiveBuffer()->where('site_id',1)->where('forecast_at','like','2026-05-08%')->get(['forecast_at','wind_dir_consensus','status']);
# >>> \App\Models\SiteScore::activeTableName();  // site_scores_1 | site_scores_2
# >>> \App\Models\SiteCondition::where('site_id',1)->first(['wind_dir_min','wind_dir_max','wind_speed_min','wind_speed_max']);
```

---

## URLs de développement

| Service     | URL                   |
|-------------|-----------------------|
| Application | http://localhost:8001 |
| phpMyAdmin  | http://localhost:8081 |
| MailDev     | http://localhost:6082 |
| Xdebug port | 6001                  |

> Les ports sont décalés pour coexister avec Dolibarr sur le même serveur.

---

## Points d'attention

1. **Un seul `x-data` dans la vue carte** — ne jamais créer de composants Alpine imbriqués,
   ça casse le scope des dropdowns et du popup.
2. **CSS inline dans la vue carte** — ne pas migrer vers Tailwind sans rebuild Vite.
3. **Les dropdowns doivent être `position:fixed`** avec coordonnées calculées via
   `getBoundingClientRect()` — sinon ils passent derrière Leaflet.
4. **Convention direction vent** : toujours stocker et comparer en FROM direction (météo standard).
   Ajouter +180° uniquement à l'affichage des flèches.
5. **Redis** utilisé pour cache + sessions + queues — ne pas changer `QUEUE_CONNECTION`.
6. **Horizon météo limité à 5 jours** — au-delà c'est de la divination.
7. **Le scheduler** (`php artisan schedule:run`) est à configurer dans `routes/console.php`
   pour le fetch automatique toutes les heures (PENDING).
8. **Authentification front** non encore définie (l'admin a son login `AuthController`,
   rôle admin) — Laravel Breeze prévu côté front (admin/user).
9. **`CHANGELOG.md`** à la racine — proposer de le mettre à jour après chaque
   changement majeur (voir la section *Changelog* en haut de ce fichier).
10. **Shell global `<x-app-shell>`** — ne pas imbriquer de composant anonyme dans
    ses slots (utiliser `@include`) ; un slot vide n'affiche pas le panneau.
11. **Cache et objets sérialisés** — ne **jamais** stocker d'Eloquent
    dans `Cache::remember()` (file ou redis). Quand le schéma de la
    classe évolue (colonne ajoutée, cast modifié…) ou que la classe
    elle-même est remplacée, les anciennes entrées se déballent en
    `__PHP_Incomplete_Class` et crashent la vue (erreur typique :
    *« script tried to access a property on an incomplete object »*).
    Toujours stocker des **primitives** (strings/ints/arrays) et
    reconstruire les Carbon/Eloquent à la lecture. Si le schéma
    change quand même, **bump une `CACHE_VERSION`** dans la clé pour
    rendre les vieilles entrées orphelines. Cf. `App\Services\DataCoverage`
    pour le pattern `safeSection() + inflate*Payload()` (cache
    actuellement désactivé sur cette page, à réactiver une fois le
    diagnostic d'environnement clarifié).
12. **Hiérarchie z-index officielle** (à respecter pour toute nouvelle UI) :
    `30` overlay backdrop mobile (shell) · `40` navbar + volets latéraux
    (shell) · `50` dropdowns navbar (modules / auth) · `70` contrôles
    flottants carte (`#day-selector`, `.dd-menu`) · `80` tooltips
    (`#chart-tooltip`, `.rp-voting-tip`). Pas de `z-index: 9999/99999` —
    si une nouvelle couche est nécessaire, l'insérer dans cette échelle.
13. **Commentaires CSS / JS dans les Blade** — **ne jamais** écrire
    `<x-app-shell>` (ou toute balise composant Blade) dans un commentaire
    `/* ... */` ou `// ...` : Blade les interprète quand même comme une
    balise composant et casse la compilation (erreur typique « expecting
    elseif/else/endif »). Utiliser `<x-app-shell\>` en l'évoquant, ou
    écrire « le shell global » sans la balise. Idem pour `@media`,
    `@keyframes` dans un fichier CSS Blade : encapsuler dans
    `@verbatim ... @endverbatim` (sauf à la racine du fichier où
    `@keyframes` est laissé tranquille).
14. **Cache des données carte** — toutes les données de la vue carte
    sont cachées en Redis via `App\Services\Map\*` (cf. section dédiée
    du Module 1). Toute modif d'un payload exposé sur `/api/map-bundle`,
    `/api/sites/{id}/{scores,chart,multimodel}`, `/api/balises` ou
    `/api/balises/{id}/history` doit s'accompagner d'un bump de la
    `CACHE_VERSION` du service correspondant (sinon les vieilles entrées
    Redis casseront la vue). L'invalidation est **push**
    (`WatchScoringTableJob` au flip du buffer de scoring, jobs fetch
    readings, observers Site/Balise) + **lazy fallback** (TTL backup). En
    dev, vider le cache : `php artisan cache:clear` ou
    `php artisan map:rebuild-bundle --clear`.
15. **Observers Eloquent et opérations en masse** — les trois
    observers (`GeocodableObserver`, `MapBundleInvalidationObserver`,
    `SiteActivationObserver`) ne se déclenchent que sur les
    `save() / create() / update() / delete()` Eloquent. Les
    `Builder::update(['active' => true])` en masse (tinker, PHPMyAdmin,
    SQL direct) **bypassent** ces events. Après une telle opération :
    `php artisan map:rebuild-bundle` (et `geocode:locations` si
    nouveaux modèles). Si un import en masse passe par Eloquent
    (`->each(fn ($m) => $m->save())`), les observers se déclenchent
    normalement — le `RebuildMapBundleJob` est `ShouldBeUnique` 30s
    donc pas de raz-de-marée. Idem pour les écritures via
    `DB::table()->insert()` (utilisé par `SitesImport`) qui contournent
    Eloquent — `geocode:locations` post-import reste nécessaire.

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

---

### Architecture Docker sur le VPS

```
Internet (80/443)
       │
       ▼
Nginx Proxy Manager       (/srv/proxy/ — réseau: proxy)
       │  HTTPS + Let's Encrypt automatique
       ▼
parapente_nginx:80        (réseau: proxy + parapente-external-pod)
       │  PHP-FPM
       ▼
parapente_php:9000        (réseau: parapente-internal-pod + meteo-net)
       │
       ├── parapente_mariadb:3306   (réseau: parapente-internal-pod)
       ├── parapente_redis:6379     (réseau: parapente-internal-pod)
       └── open-meteo-api:8080      (réseau: meteo-net — projet séparé)

parapente_worker          (réseau: parapente-internal-pod + meteo-net)
parapente_scheduler       (réseau: parapente-internal-pod + meteo-net)
```

---

### Réseaux Docker

| Réseau | Type | Rôle |
|---|---|---|
| `proxy` | external, bridge | Partagé entre NPM et Nginx app |
| `parapente-internal-pod` | internal | Communication interne app (PHP ↔ DB ↔ Redis) |
| `parapente-external-pod` | bridge | Accès internet depuis les conteneurs |
| `meteo-net` | external, bridge | Partagé avec le conteneur `open-meteo-api` |

---

### Fichiers de configuration prod

| Fichier | Rôle |
|---|---|
| `docker-compose.prod.yml` | Orchestration production (sans Xdebug, MailDev, phpMyAdmin) |
| `Dockerfile.prod` | Image PHP prod (sans Xdebug, php.ini-production, opcache optimisé) |
| `nginx/nginx.prod.conf` | Config Nginx prod (server_name, real_ip, headers sécurité) |
| `src/.env` | Variables d'environnement Laravel (jamais committé) |
| `.env.prod` | Référence prod pour Docker Compose (jamais committé) |
| `.env` | Copie de `.env.prod` à la racine, lue par Docker Compose |

---

### Open-Meteo auto-hébergé

- Conteneur : `open-meteo-api`
- Réseau interne : `meteo-net`
- URL depuis Laravel : `http://open-meteo-api:8080/v1`
- Port hôte exposé : `8888` (accès externe si besoin)

---

### Commandes de déploiement

**Première installation :**
```bash
ssh franck@213.199.51.57
cd /srv/parapente-app/parapente
git clone <repo> .
cp .env.prod src/.env
cp .env.prod .env
docker network create proxy          # si pas encore créé
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
docker exec parapente_php composer install --no-dev --optimize-autoloader
docker exec parapente_php npm install && npm run build
docker exec parapente_php php artisan migrate --force
docker exec parapente_php php artisan db:seed --force
docker exec parapente_php php artisan config:cache
docker exec parapente_php php artisan route:cache
docker exec parapente_php php artisan view:cache
```

**Mise à jour (déploiement) :**
```bash
cd /srv/parapente-app/parapente

# 1. Code
git fetch origin
git checkout <branche>        # ex: V2
git pull origin <branche>

# 2. Images + (re)démarrage des conteneurs
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d

# 3. Dépendances
docker exec parapente_php composer install --no-dev --optimize-autoloader
docker exec parapente_php npm install
docker exec parapente_php npm run build

# 4. Base de données
docker exec parapente_php php artisan migrate --force
docker exec parapente_php php artisan storage:link                       # idempotent
# si de nouveaux seeders sont arrivés : cibler la classe (PAS `db:seed` seul, qui reseed tout)
# docker exec parapente_php php artisan db:seed --class=ModuleSeeder --force

# 5. Droits + caches (les commandes ci-dessus tournent en root → re-chown ce que PHP-FPM doit écrire)
docker exec parapente_php chown -R www-data:www-data storage bootstrap/cache
docker exec parapente_php php artisan optimize:clear
docker exec parapente_php php artisan optimize                           # config + routes + views

# 6. Redémarrages : PHP (OPcache validate_timestamps=0) PUIS Nginx (re-résoudre l'IP du conteneur PHP recréé)
docker compose -f docker-compose.prod.yml restart parapente-php
docker compose -f docker-compose.prod.yml restart parapente-nginx
```

> Noms : `docker compose ... restart <service>` prend le **nom de service**
> (`parapente-php`, `parapente-nginx`, avec tiret) ; `docker exec <conteneur>`
> prend le **nom de conteneur** (`parapente_php`, avec underscore).

**Vérification santé :**
```bash
docker compose -f docker-compose.prod.yml ps
docker logs parapente_worker --tail 20
docker logs parapente_scheduler --tail 20
```

---

### Points d'attention prod

- `opcache.validate_timestamps=0` en prod — **redémarrer `parapente-php` après chaque déploiement** pour vider l'OPcache
- **Recréation du conteneur PHP ⇒ redémarrer Nginx** : `docker compose ... build/up -d` recrée `parapente_php` avec une **nouvelle IP** ; `parapente_nginx` garde l'ancienne IP en cache (`fastcgi_pass parapente_php:9000` résolu une seule fois) → **502 Bad Gateway** tant qu'on n'a pas fait `restart parapente-nginx`. (Solution propre possible : `resolver 127.0.0.11 valid=10s;` + `set $up parapente_php:9000; fastcgi_pass $up;` dans `nginx/nginx.prod.conf`.)
- Les commandes `artisan`/`composer` lancées via `docker exec` tournent en **root** ; PHP-FPM en **www-data** → après déploiement, `chown -R www-data:www-data storage bootstrap/cache`. Un cache de config/routes/vues incohérent peut donner un 500 (`Target class [view] does not exist`) → `php artisan optimize:clear && php artisan optimize`.
- `src/.env` et `.env.prod` ne sont **jamais committés** (dans `.gitignore`)
- Le `.env` racine (lu par Docker Compose pour les variables MariaDB) doit être **identique à `.env.prod`**
- `APP_DEBUG=false` en prod — ne jamais activer sans redéployer immédiatement
- Les assets Vite (`public/build/`) sont buildés directement sur le VPS via `docker exec parapente_php npm run build`