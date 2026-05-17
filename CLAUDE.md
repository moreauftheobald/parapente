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
│   │   │   │   └── UserScoringController.php ← CRUD scorings perso utilisateur
│   │   │   ├── Admin/                   ← BackOffice (sites, balises, modèles, APIs,
│   │   │   │                                users, sync, logs, articles, modules…)
│   │   │   ├── HomeController.php        ← Page d'accueil (articles + épinglé « À la une »)
│   │   │   ├── IconCacheController.php   ← Tampon disque icônes SpotAir
│   │   │   ├── MapController.php         ← Vue carte
│   │   │   └── WikiController.php        ← Pseudo-wiki / aide en ligne (/aide)
│   │   ├── Requests/Api/
│   │   │   ├── ScoringRules.php           ← Catalogue de règles validation partagé
│   │   │   └── ValidatesScoringCoherence  ← Invariants relationnels (min ≤ max, etc.)
│   │   └── Controllers/Concerns/
│   │       └── HasFilterableIndex.php     ← Trait search/sort whitelist pour admin
│   ├── Models/
│   │   ├── Site.php, SiteCondition.php, WeatherModel.php, Forecast.php,
│   │   ├── SiteScore.php, Balise.php, BaliseReading.php
│   │   ├── Module.php                    ← Modules du menu (table `modules`)
│   │   ├── Article.php                   ← Articles / changelog accueil (+ flag `is_pinned`)
│   │   └── WikiPage.php                  ← Pages du pseudo-wiki (arborescence parent_id)
│   ├── Support/
│   │   └── Navigation.php                ← Liste des modules visibles (navbar)
│   ├── Services/
│   │   ├── Weather/
│   │   │   ├── Apis/OpenMeteoApi.php         (fetch sites + batch balises)
│   │   │   ├── ScoringService.php            (voting logic)
│   │   │   ├── SiteScoresPayloadBuilder.php  (build `/api/sites/{id}/scores`)
│   │   │   ├── SiteChartPayloadBuilder.php   (build `/api/sites/{id}/chart`)
│   │   │   ├── SiteMultimodelPayloadBuilder.php (build `/api/sites/{id}/multimodel`)
│   │   │   └── UserScoringService.php        (overrides scoring perso)
│   │   ├── Balises/
│   │   │   ├── BaliseProviderInterface.php
│   │   │   ├── BaliseConstants.php           (DEAD_AFTER_DAYS, etc.)
│   │   │   ├── BaliseReadingFormatter.php    (sérialisation JSON)
│   │   │   ├── PiouPiouProvider.php, MetarProvider.php, WindyOpenDataProvider.php
│   │   └── Map/                          ← Cache pré-calculé des données carte
│   │       ├── MapBundleBuilder.php          (bundle markers, /api/map-bundle)
│   │       ├── SiteDetailCache.php           (cache /scores /chart /multimodel)
│   │       ├── BalisesBundleCache.php        (cache /api/balises + /history)
│   │       ├── DayQualityCalculator.php      (helper viabilité jour)
│   │       └── SunWindowCalculator.php       (helper fenêtre solaire)
│   └── Jobs/
│       ├── FetchForecastsJob.php         ← Orchestre par site (Bus::batch)
│       ├── FetchSiteForecastsJob.php     ← Fetch + score 1 site
│       ├── FetchBaliseReadingsJob.php    ← Base ABSTRAITE des 3 jobs balises
│       ├── FetchPiouPiouReadingsJob.php  ← Étend FetchBaliseReadingsJob
│       ├── FetchMetarReadingsJob.php     ← Étend FetchBaliseReadingsJob
│       ├── FetchWindyReadingsJob.php     ← Étend FetchBaliseReadingsJob
│       └── RebuildMapBundleJob.php       ← Régénère le map bundle après scoring
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
| `weather_models`  | Catalogue des modèles NWP (~20 connus, palette dans `config/weather.php`) |
| `weather_apis`    | Sources API météo (Open-Meteo…)                         |
| `forecasts`       | Prévisions brutes Open-Meteo (nullable)                 |
| `site_scores`     | Scores calculés par site/heure (green/orange/red)       |
| `balises`         | Balises PiouPiou/FFVL                                   |
| `balise_readings` | Lectures temps réel balises                             |
| `modules`         | Modules du menu (key, label, icône, route, `is_active`, `access_level` guest\|user\|admin, `requires_registration`, `sort_order`) |
| `articles`        | Articles / changelog accueil (titre, body HTML, `author_id`, `is_published`, `is_pinned`, `published_at`) |
| `wiki_pages`      | Pages du pseudo-wiki (`parent_id` auto-référent, `slug` unique, `title`, `excerpt`, body HTML, `sort_order`, `is_published`, `author_id`) |
| `settings`        | Paramètres globaux (clé unique, valeur JSON, label, description) — seuils de scoring éditables via `/admin/settings` |

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
GET /api/sites              → Liste tous les sites actifs (métadonnées).
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

## Module 1 — Carte météo (IMPLÉMENTÉ)

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
  Le consensus multi-modèles est stocké dans `site_scores.cloud_base_consensus`
  (même voting logic que le reste).
- Format datetime `Y-m-d H:i:s` pour MariaDB (pas ISO avec `T`)

**`ScoringService`** (dépend de `App\Services\Settings` pour lire les
seuils — injection automatique par DI Laravel ; les 4 seuils globaux
sont préchargés au constructor une fois pour la durée du scoring,
évite ~6 lookups par créneau × 120 créneaux/site) :
- Voting logic complète
- Moyenne circulaire pour direction vent (évite le problème 359°/1°)
- Moyenne inverse carré pour isoler les outliers
- Statut horaire (`site_scores.status`) — règles éliminatoires :
  - rouge : précip consensus > `scoring.precip_red_mmh` ;
    rafale consensus > `scoring.gust_red_kmh` (override par site possible
    via `site_conditions.wind_gust_red_kmh`) ;
    direction ou vitesse moyenne hors plage du site
  - orange : précip consensus > `scoring.precip_orange_mmh` ;
    rafale consensus > `scoring.gust_orange_kmh` (override par site possible
    via `site_conditions.wind_gust_orange_kmh`)
  - (la rafale = consensus de `wind_speed_max`, idem voting logic que le reste)
  - Tous les seuils sont éditables depuis `/admin/settings`.
- **Coloration par paramètre** (`computeParamColors()`, méthode publique) :
  isole la couleur green/orange/red de chacun des 5 paramètres (direction,
  vitesse moy., rafales, précip, plafond) — alignée sur les règles
  éliminatoires. Stockée dans `site_scores.detail.<param>.color` à chaque
  scoring. Sert à l'onglet « Détail scoring · 5 jours » du volet droit.
  Plafond : informatif à partir de `site_conditions.cloud_base_min_m` (rouge
  en-dessous, orange dans une marge de 100 m, vert au-dessus, sinon `unknown`).
- `upsert()` en masse
- **Backfill** : `php artisan scores:recompute-detail-colors [--site=<id>]
  [--chunk=500]` recalcule `detail.*.color` sur les scores existants sans
  refetch météo. Idempotent. Lance-la après un déploiement qui change la
  logique de couleurs (sinon les couleurs s'actualiseront au prochain fetch
  horaire).

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

### Cache des données carte — `App\Services\Map\*`

Tous les endpoints alimentant la vue carte sont **pré-calculés** en cache
Redis pour soulager la DB et accélérer le boot mobile :

- **`MapBundleBuilder`** (`map.bundle.v1`, TTL 90 min)
  - Construit le bundle global servi à `/api/map-bundle` : tous les sites
    actifs avec leurs statuts journaliers (issus de `site_scores`), les
    fenêtres solaires, et l'agrégat global par jour (best_status,
    green_slots cumulés).
  - Régénéré par `RebuildMapBundleJob`, dispatché à la fin du `Bus::batch`
    orchestré par `FetchForecastsJob` (toutes les chaînes de scoring ont
    fini) — et aussi à la fin de `FetchSiteForecastsJob` (manuel).
  - Fallback lazy : si la clé est absente (TTL expiré, flush), reconstruction
    à la volée avec lock Redis anti-thundering-herd.
  - Commande : `php artisan map:rebuild-bundle` (force la régénération).

- **`SiteDetailCache`** (`map.site_{scores|chart|multimodel}.{id}.{...}.v1`, TTL 90 min)
  - Cache transparent des endpoints `/api/sites/{id}/{scores,chart,multimodel}`.
  - `scores` : version "global" cachée ; si l'utilisateur a un scoring perso
    ACTIF sur ce site, le contrôleur applique le rescore par-dessus
    (status + couleurs + recalc day_quality) — sinon le cache est servi tel
    quel (cas dominant).
  - `chart` et `multimodel` : cache complet (pas de logique user-spec).
    `multimodel` est caché par couple `(day, period)`.
  - Invalidation : `ScoreSiteJob::handle` et `FetchSiteForecastsJob::handle`
    appellent `forgetSite($siteId)` à la fin du scoring.

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
- `FetchForecastsJob` : cron horaire. Construit une chaîne par site
  (`FetchSiteModelJob×N → ScoreSiteJob`) et les enveloppe dans un
  `Bus::batch()` (`allowFailures`). Le callback `finally` du batch
  dispatch `RebuildMapBundleJob` pour reconstruire le cache map quand
  tous les sites ont fini de scorer.
- `FetchSiteForecastsJob` : fetch tous modèles d'un site (sync) →
  upsert par lots 500 → calcul scores → invalide `SiteDetailCache` du
  site → dispatch `RebuildMapBundleJob`. Timeout 300s.
- `ScoreSiteJob` : recalcule les scores d'un site, puis invalide
  `SiteDetailCache::forgetSite($id)` et `UserScoringService::invalidateSite($id)`.
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

# Vérifier les données en base
# >>> \App\Models\SiteScore::where('site_id',1)->where('forecast_at','like','2026-05-08%')->get(['forecast_at','wind_dir_consensus','status']);
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
    Redis casseront la vue). L'invalidation est **push** (jobs de
    scoring / fetch readings) + **lazy fallback** (TTL backup). En dev,
    vider le cache : `php artisan cache:clear` ou `php artisan map:rebuild-bundle --clear`.

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