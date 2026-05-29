# Changelog — Qui Vole ?

Ce fichier retrace les évolutions **majeures** du code (nouvelles fonctionnalités,
changements d'architecture, modifications de base de données, ruptures de
compatibilité). Il n'a pas vocation à lister chaque commit.

Conventions :
- Une entrée par évolution majeure, la plus récente en haut.
- Format de date : `AAAA-MM-JJ` (approximative si besoin).
- Catégories suggérées : `Ajouté`, `Modifié`, `Corrigé`, `Supprimé`, `Base de données`, `Déploiement`.

---

## 2026-05-29 — Sites masqués par utilisateur (carte de volabilité)

Un utilisateur **connecté** peut masquer de sa carte de volabilité
(`/carte`) les sites qui ne l'intéressent pas, depuis une page dédiée
accessible via le menu utilisateur. Règle par défaut : **tout est
affiché** ; seuls les sites explicitement marqués « ne pas afficher »
disparaissent, pour cet utilisateur uniquement et seulement connecté.
Modèle d'**exclusion pure** (une ligne = un site masqué). Cf.
`FF_site_blacklist.md`.

### Ajouté

- **Page de gestion `/profil/sites-masques`** (`HiddenSitePageController`
  → vue `user.hidden-sites`, `<x-app-shell>`) : liste des sites actifs
  avec bascule **Masquer / Réafficher** (optimiste). **Barre de
  filtrage** : recherche par nom, sélecteurs **en cascade** pays →
  région → département, filtre d'état (tous / masqués / affichés),
  bouton réinitialiser + compteur de résultats.
- **Liens** dans le menu utilisateur (navbar) et dans la page `/profil`
  (section « Sites masqués » + nav latérale).
- **API CRUD** `auth:web` :
  - `GET /api/users/me/hidden-sites` → `{ hidden_site_ids[], sites[] }`
  - `PUT /api/users/me/hidden-sites/{site}` → masquer (idempotent)
  - `DELETE /api/users/me/hidden-sites/{site}` → réafficher (idempotent)
- **Overlay carte** `GET /api/me/hidden-sites` → ids à filtrer +
  `days_summary` recalculé **sans** les sites masqués (le compteur
  « h de vol possible » du sélecteur de jour reflète ce que
  l'utilisateur voit). Recalcul en mémoire depuis le bundle en cache,
  sans requête DB supplémentaire ; `null` si aucun site masqué.
- **Filtrage carte** dans `mapApp()._siteVisible()` (connecté
  uniquement, pas de toggle sur la carte — la page de gestion est le
  panneau de contrôle).
- **`/api/sites`** expose désormais `country`, `admin_region`,
  `department` (colonnes de géocodage) pour alimenter les filtres.

### Modifié

- **`MapBundleBuilder`** : `buildDaysSummary` extrait en méthode
  statique pure `summarizeDays($sitesPayload, $excludedSiteIds = [])`,
  réutilisée par le build global et par l'overlay utilisateur. Le
  bundle **caché** expose désormais `green_hours_set` par site (retiré
  du payload client par `MapBundleController::show` → pas de bloat
  côté front). **`CACHE_VERSION` 1 → 2** (penser à
  `php artisan map:rebuild-bundle` au déploiement).

### Base de données

- Table **`user_hidden_sites`** (`user_id`, `site_id`, timestamps),
  unique `(user_id, site_id)`, index `user_id`, cascade delete sur les
  deux FK. Migration
  `2026_05_29_120000_create_user_hidden_sites_table.php`.

### Tests

- `tests/Unit/Map/SummarizeDaysTest` (agrégat + exclusions, sans DB),
  `tests/Feature/Api/UserHiddenSiteApiTest` (CRUD, auth, scoping,
  idempotence, overlay), `tests/Feature/User/HiddenSitesPageTest`.

---

## 2026-05-23 — Nouvelle « Carte météo » (overlays consensus-grid) + renommage de l'ancienne carte

L'ancienne carte (`/carte`) est renommée **« Carte de volabilité »** et
un nouveau module **« Carte météo »** (`/carte-meteo`) est introduit,
alimenté par un sidecar Python `consensus-grid` qui pré-calcule un
consensus multi-modèles toutes les heures et expose des overlays PNG
pré-rendus. Page admin-only le temps de stabiliser l'intégration.

### Ajouté

- **Module `weather-map`** dans la table `modules`, libellé « Carte
  météo », route `weather-map.index`, `access_level = 'admin'`.
  Migration `2026_05_22_100000_rename_map_and_add_weather_map_module.php`
  (idempotente, renomme aussi `map` → « Carte de volabilité »).
- **Vue `weather-map/index.blade.php`** — Leaflet plein écran avec :
  - Sélecteur de fond (OpenTopoMap (défaut, relief), OSM standard,
    Satellite + noms (Esri World_Imagery + World_Boundaries_and_Places
    en pane séparé z-index 380 pour rester lisible sous l'overlay),
    Clair (Voyager), Sombre (CartoDB dark_all)).
  - Sélecteur de variable peuplé dynamiquement depuis le manifest du
    sidecar (~22 layers : surface 10 m / 2 m, altitude 850 hPa,
    CAPE / CIN / Lifted Index / précip. convectives / couche limite,
    et variables propriétaires `qui_vole_cloud_base`, `qui_vole_storm_risk`,
    `qui_vole_models_count/converging`).
  - **Slider d'opacité** (10–100 %, défaut 50 %).
  - **Player horaire** : sélecteurs *Jour* + *Heure* (libellés relatifs
    « Aujourd'hui · vendredi 22 mai », heure de Paris), bouton ▶ Play / ⏸ Pause,
    sélecteur de cadence (0.5 / 1 / 2 / 5 s par créneau). Espace = play/pause.
  - **Flèches de vent vectorielles** décodées en canvas côté navigateur
    depuis le PNG `wind_direction_10m` (cmap HSV bijective angle ↔ teinte,
    pas d'endpoint séparé côté sidecar). Densité adaptative au viewport
    (1 flèche / ~30 px écran, plafonnée à 6000 markers), redessinées au
    pan/zoom. SVG minimaliste « → » en stroke avec halo blanc.
  - **Légende dynamique** : lit `palette.stops` du manifest sidecar pour
    construire un `linear-gradient` CSS exact, peu importe la cmap
    matplotlib (built-in ou custom comme `wind_speed_parapente`).
    Détection auto des cmaps alpha-encoded (toutes les stops avec la
    même couleur RGB ⇒ fondu transparent → opaque). 5 graduations
    évenly-spaced (4 pour `qui_vole_storm_risk`). Vents convertis
    m/s → km/h à l'affichage.
  - **Badge `/progress`** : pendant qu'un run consensus tourne côté
    sidecar, affiche `run en cours : variable (xx %, N min)`. Caché
    en `idle` / `completed`.
  - **Détection live de nouveau run** : poll du manifest toutes les
    60 s, bascule automatique des URLs (cache-buster `?run=<run_init_unix>`)
    sans rechargement de la page.

- **`WeatherMapController`** — 4 routes Laravel, toutes sous middleware
  `['auth', 'admin']` :
  - `GET /carte-meteo` → vue Blade
  - `GET /carte-meteo/manifest` → proxy de `/v1/overlay` du sidecar
    (cache Redis 10 min sur succès uniquement, jamais sur erreur)
  - `GET /carte-meteo/overlay/{variable}/{step}.png` → fallback dev /
    en prod nginx intercepte avant que PHP soit appelé
  - `GET /carte-meteo/health` → proxy de `/health` (refresh 60 s côté
    front pour le pill `sidecar`)
  - `GET /carte-meteo/progress` → proxy de `/progress` (refresh 30 s)
- **Config** : `services.consensus_grid.base_url`
  (`CONSENSUS_GRID_BASE_URL`, défaut `http://consensus-grid:8082`)
  et `CONSENSUS_GRID_TIMEOUT`.

- **nginx prod** : un bloc `location ~ ^/carte-meteo/overlay/(.+)$`
  proxifie directement les PNG vers le sidecar (sans PHP) via
  `proxy_pass http://$consensus_grid_upstream/v1/overlay/$1$is_args$args`.
  Pattern regex + capture + URI explicite + `$is_args$args` pour
  préserver la query string. Resolver Docker (`127.0.0.11`) en scope
  serveur, ce qui re-résout le DNS à chaque requête → la recréation
  du conteneur sidecar ne fige plus l'IP côté nginx. Pas de cache
  nginx (le sidecar sert les PNG en ~5 ms via FileResponse, le HTTP
  cache navigateur suffit). Conteneur `parapente_nginx` ajouté au
  réseau Docker `meteo-net` dans `docker-compose.prod.yml`.

- **Carte de volabilité (panneau droit)** :
  - Altitude du site affichée dans l'en-tête, à côté du nom / niveau /
    orientation / fenêtre solaire.
  - **Tooltips au survol** des trois zones du graphe « Synthèse » :
    tuiles nuageuses (▲ Hautes / ▬ Moyennes / ▼ Basses), barres de
    vent (Moy / Max / Min / Direction avec rose des vents), plafond
    estimé (Moy / Max / Min + rappel altitude du décollage). Curseur
    vertical en pointillés + tooltip flottant Alpine qui réutilise
    `app.tooltip` du système multi-modèles. Token de séquence sur
    `drawArrows` pour éviter les rendus périmés lors de scrubs rapides.

### Modifié

- Module `map` (`/carte`) renommé en **« Carte de volabilité »**
  (label uniquement, route inchangée pour ne pas casser les signets).
- Middleware `RecordPageView` : ajout de `/carte-meteo/overlay/` à la
  liste des préfixes ignorés pour ne pas polluer les KPI de trafic
  (migration data `2026_05_22_120000_purge_weather_map_overlay_page_views.php`
  purge rétroactivement les éventuelles entrées historiques, calquée
  sur la purge `/icons-cache/` de mai).

### Déploiement

- `docker-compose.prod.yml` : `parapente-nginx` attaché à `meteo-net`
  pour pouvoir résoudre `consensus-grid:8082` côté upstream.
- Le sidecar `parapente-consensus-grid` doit également déclarer
  `meteo-net` comme réseau externe dans son propre `docker-compose.prod.yml`
  pour que le DNS Docker enregistre l'alias court `consensus-grid` au
  démarrage (sinon il faut `docker network connect --alias consensus-grid`
  manuellement après chaque recréation).
- Pas de migration applicative supplémentaire requise au-delà des deux
  migrations citées ci-dessus.

### Notes contrat sidecar

Format du manifest `/v1/overlay/{variable}` consommé par la légende :

```json
{
  "palette": {
    "cmap": "wind_speed_parapente",
    "vmin": 0.0,
    "vmax": 11.111,
    "stops": [
      {"t": 0.0,   "color": "#190091"},
      ...
      {"t": 1.0,   "color": "#fc0320"}
    ]
  }
}
```

Le front cherche les stops à trois emplacements (`info.cmap_stops`,
`info.stops`, `info.palette.stops`) pour tolérer une légère dérive
de contrat. Fallback final sur l'ancienne table CSS hardcodée
(`CMAP_CSS`) si rien n'est trouvé — aucune régression en cas de
rollback. Les cmaps alpha-encoded (clouds_alpha, cape_alpha, etc.)
sortent toutes les stops avec la même couleur RGB côté sidecar
(l'alpha est dropped au sampling matplotlib) ; le front détecte ce
cas et affiche un fondu transparent → opaque qui rend correctement
la sémantique de la couche.

---

## 2026-05-19 — Nouveau module front « Carte des modèles » + écran horizon

Suite immédiate de la phase 2.5 livrée la veille. Deux ajouts
complémentaires pour l'analyse visuelle de la fiabilité des modèles
météo.

### Ajouté

- **Nouvel écran admin `/admin/reliability/horizon`** — comparaison
  apple-to-apple des 4 buckets sur les `target_at` présents
  simultanément dans les 4 horizons avec observation. Permet de
  mesurer honnêtement la dégradation de la prévision quand l'horizon
  s'éloigne, sans le biais des fenêtres d'observation non recouvrantes
  (l'écran `/admin/reliability/compare` calcule chaque bucket sur
  son propre ensemble). Bandeau récap MAE + tableau strict + tableau
  référence « full » pour transparence. Liens cross-écran ajoutés
  sur compare et models (icône bullseye).

- **Export CSV** et intégration JSON pour l'horizon MAE :
  - `GET /admin/reliability/export/horizon-mae.csv` — 1 ligne par
    `(balise × variable × bucket × set)` où `set ∈ {common, full}`.
  - Section `horizon_mae` ajoutée au payload de
    `/admin/reliability/export.json` (`schema_version` bumpé à 2).
  - `ReliabilityExportService::buildHorizonStats()` — source unique
    de vérité réutilisée par l'écran admin, le CSV et le JSON.
  - `RELIABILITY_ANALYSIS_CONTEXT.md` enrichi : avertissement
    explicite sur `stats` (pas apple-to-apple), documentation
    complète de la section `horizon_mae` + 2 nouvelles questions-types
    pour Claude analyste (§9.11 dégradation par horizon, §9.12 effet
    du filtre common vs full).

- **Nouveau module front `model-grid`** — page `/carte-modeles`
  visualisant la grille d'un modèle météo NWP avec coloration par
  fiabilité agrégée des balises tombant dans chaque cellule. Module
  caché pour les non-admins (`access_level = 'admin'` dans la table
  `modules`) le temps que la couverture du panel soit suffisante.
  Cf. `FF_model_reliability.md` § *Pour aller plus loin* (hors scope
  initial, ajouté en bonus).

  - **`App\Services\Map\ModelGridBuilder`** : génère le GeoJSON de la
    grille sur une bbox donnée. Alignement standard 0°/0°, pas
    constant = `resolution_km / 111`. Pour chaque cellule, identifie
    les balises du panel dont les coords tombent dedans, puis agrège
    `model_reliability` (moyenne pondérée par `samples_n` pour
    `mae`/`weight_factor`/`bias_signed`, somme pour `samples_n`).
    Cellules vides ajoutées si `show_empty=true` (mode grille
    pédagogique). Garde-fou anti-overload : si la bbox déclencherait
    > 16 000 cellules, retour `meta.too_large=true` avec zoom
    recommandé. Zoom minimum auto-calculé selon résolution du modèle
    (+ 2 crans pour assurer que les DivIcons tiennent visuellement).

  - **`App\Http\Controllers\ModelGridController`** (non-admin —
    page front filtrée par module visibility + middleware).
    `index()` rend la page Leaflet, `data()` sert le GeoJSON validé.

  - Routes au top niveau, middleware `['auth', 'admin']` :
    `GET /carte-modeles` et `GET /carte-modeles/data`.

  - **Vue Leaflet** plein écran : toolbar 4 dropdowns (modèle /
    variable / horizon / métrique) + checkbox grille complète +
    toggle fond clair/sombre (CartoDB Voyager par défaut, dark_all
    en option). Légende implémentée comme `L.Control` natif
    (pas un sibling div) pour éviter les soucis de z-index avec les
    panes Leaflet. DivIcons SVG au centre des cellules occupées :
    rond gris/vert pour `samples_n`, flèche ↑ rouge / ↓ bleue pour
    le bias signé. Popups au clic avec détail balises et métriques.

### Migration

- `2026_05_19_100000_add_model_grid_module.php` — insère la ligne
  du module dans `modules` (idempotent, pattern wiki).
- `ModuleSeeder` enrichi en parallèle.

### Notes opérationnelles

- Au déploiement : le script standard `bash deploy.sh` lance la
  migration et insère le module. Aucune intervention manuelle.
- L'admin verra apparaître **« Carte des modèles »** (icône
  table-cells) dans la barre du menu principal.
- Pour rendre le module public quand le panel sera suffisamment
  étendu (avenir, post-intégration FFVL) : aller dans `/admin/modules`
  et passer `access_level` à `user` ou `guest`.

---

## 2026-05-18 — Phase 2.5 : fiabilité dynamique des modèles en shadow mode

Mise en place d'un système d'évaluation continue de la fiabilité des
modèles météo NWP par confrontation à la vérité-terrain (balises). Trois
algorithmes de consensus calculés en parallèle (legacy / amélioré /
amélioré + pondération dynamique), comparés contre l'observation balise
pour valider chiffres en main une éventuelle évolution future de la
voting logic de production. **Aucun impact sur le scoring de prod —
shadow strict**. Cf. `FF_model_reliability.md` § *Phase 2.5*.

### Ajouté

- **Schéma DB**
  - `balises.in_consensus_compare_panel` (boolean) — drapeau manuel
    d'inclusion dans le panel de validation, distinct de
    `reliability_class` (pro/amateur).
  - Table `model_reliability` — fiabilité par tuple
    `(weather_model_id × balise_id × horizon_bucket × variable)` :
    MAE, RMSE, biais signé, `weight_factor` (clampé `[0.25, 2.0]`),
    `samples_n`. Calcul sur fenêtre glissante 7 j par défaut.
  - Table `balise_consensus_compare` — historique des trois consensus
    (A legacy, B amélioré, C amélioré + fiabilité) par
    `(balise × créneau × horizon × variable)` avec observation balise
    et MAD intra-créneau. Rétention 14 j.

- **Services `App\Services\Weather\Reliability\`**
  - `ConsensusCalculator` (classe statique pure, testable hors Laravel) :
    - `legacyLinear` et `legacyCircular` — reproduction stricte du
      consensus actuel (inverse-carré EPSILON 0.001 et moyenne
      circulaire pondérée).
    - `improvedLinear` et `improvedCircular` — EPSILON paramétrable,
      filtrage outliers MAD intra-créneau, médiane pondérée
      (paramétrable).
    - `circularDistance` et `signedCircularDiff` — helpers exposés
      publiquement pour les analyses externes.
  - `ReliabilityCalculator` — lookup `weight_factor` avec cache mémoire
    (par instance) et garde-fous cold start. Méthode
    `recomputeForBalise()` qui joint `forecast_archive_balises` ×
    `balise_readings_hourly` sur la fenêtre glissante, agrège en
    MAE/RMSE/bias par modèle × variable × bucket (linéaire ou
    circulaire selon la variable), puis dérive le `weight_factor`
    normalisé contre la médiane des MAE des modèles éligibles.
  - `BaliseConsensusCompareService` — orchestrateur : pour une balise
    du panel et une fenêtre temporelle, calcule les 3 consensus pour
    chaque créneau × variable × horizon, joint l'observation
    correspondante, upsert dans `balise_consensus_compare`. Honore le
    kill switch `reliability.shadow_enabled`.
  - `ReliabilityExportService` — assembleur unique pour les exports
    CSV (cursor streamé) et JSON complet (paramètres + panel +
    datasets + stats MAE agrégées par variable × bucket).

- **Jobs**
  - `App\Jobs\ComputeBaliseConsensusCompareJob` — schedulé horaire à
    `:10` (après `FetchBaliseForecastsJob` à `:00` et
    `AggregateBaliseReadingsHourlyJob` à `:05`). Fenêtre ±72 h.
  - `App\Jobs\ComputeModelReliabilityJob` — schedulé quotidien à
    `03:30` (après les purges). Recalcule l'ensemble de
    `model_reliability` sur la fenêtre glissante.
  - Les deux jobs sont `ShouldQueue` avec `withoutOverlapping()`,
    timeout généreux (300 s / 600 s), logs structurés par balise.

- **Settings — 11 nouvelles clés `reliability.*`**
  - `shadow_enabled` (bool, true) — kill switch global du job.
  - `epsilon_new` (float, 1.0) — EPSILON pour B et C.
  - `mad_floor` (float, 0.5 km/h) — plancher MAD.
  - `z_outlier_threshold` (float, 3.0) — seuil MAD vitesses.
  - `dir_mad_z_threshold` (float, 3.0) — idem direction.
  - `use_weighted_median` (bool, true) — switch médiane vs moyenne pondérée.
  - `use_mad_filtering` (bool, true) — switch filtrage MAD.
  - `min_samples` (int, 50) — seuil cold start pour `weight_factor`.
  - `factor_min` / `factor_max` (float, 0.25 / 2.0) — clamp `weight_factor`.
  - `window_days` (int, 7) — fenêtre glissante de calcul.
  - Nouveau groupe « Fiabilité des modèles » dans `/admin/settings`,
    avec support du **type `bool`** côté UI (checkbox + hidden input
    pour gérer le cas non coché côté HTML).

- **Écrans admin (nouveau menu « Fiabilité des modèles » dans Données)**
  - `/admin/reliability/compare` — pour une balise × variable, tableau
    heure par heure des consensus A/B/C vs observation, scindé en
    3 sections J / J+1 / J+2. Bandeau global et sectionnel avec MAE
    A/B/C et badge 🏆 sur le meilleur. Couleur sur ΔA/ΔB/ΔC selon
    tolérance par variable (1.5 / 4 km/h ou 10 / 30°). Lignes futures
    pâlies.
  - `/admin/reliability/models` — tableau pivot modèle × bucket pour
    une balise × variable. Affiche MAE / biais signé / `weight_factor`
    / `samples_n`. Coloration cohérente du poids (rouge `<0.5`, ambre
    `<0.9`, gris `≈1`, sky `>1.1`, emeraude `>1.5`). Bouton
    « Recalculer maintenant » (dispatch sync).
  - Toggle « Panel test fiabilité » sur la fiche admin balise
    (`/admin/balises/{id}`), à côté du toggle « Activer ».

- **Exports**
  - `GET /admin/reliability/export.json` — payload complet horodaté
    (paramètres courants + panel + modèles + stats + détail
    `balise_consensus_compare` + détail `model_reliability`).
  - `GET /admin/reliability/export/consensus-compare.csv` et
    `/model-reliability.csv` — CSV streamés (BOM UTF-8 pour Excel),
    filtrables par balise et variable. Boutons de téléchargement sur
    les 2 écrans admin.

- **Commandes artisan utilitaires**
  - `php artisan reliability:compute-compare [--balise=ID] [--hours=72]`
    — pendant sync de `ComputeBaliseConsensusCompareJob`.
  - `php artisan reliability:compute-factors [--balise=ID] [--days=N]`
    — pendant sync de `ComputeModelReliabilityJob`.

- **Documentation**
  - `RELIABILITY_ANALYSIS_CONTEXT.md` (~400 lignes, racine du repo) —
    document destiné à accompagner un export JSON pour permettre à un
    Claude analyste de fournir une lecture pertinente sans contexte
    préalable (sections : projet, phase 2.5, schéma JSON commenté,
    glossaire, ordres de grandeur, pièges connus, paramètres
    ajustables, critères go/no-go phase 4, 10 questions-types).

### Tests

- 13 tests unitaires sur `ConsensusCalculator` (`tests/Unit/Weather/Reliability/`)
  couvrant : cas du sujet 24/25/26/5/70, modèles identiques, direction
  qui chevauche le Nord, filtrage MAD circulaire, cold start (C ≡ B),
  MAD plancher, effet du `weight_factor` en mode moyenne pondérée,
  distance circulaire, différence circulaire signée.

### Notes opérationnelles

- Au premier déploiement : lancer `db:seed --class=SettingsSeeder
  --force` pour amorcer les libellés des 11 nouvelles clés. Cocher
  manuellement le toggle « Panel test fiabilité » sur 3 balises de
  zones variées (Alpes / plateaux / plaine), puis amorcer avec
  `php artisan reliability:compute-compare` et
  `reliability:compute-factors`.
- Performance constatée en prod : ~15 s par balise pour
  `compute-compare` sur 144 h, ~15-30 s pour `compute-factors`.
- Le panel n'a pas vocation à dépasser 5-10 balises (panel statistique,
  pas opérationnel). Pistes d'optimisation documentées dans le FF si
  le besoin émerge.

### Critères avant phase 4

L'intégration des `weight_factor` dans le `ScoringService` de prod
(phase 4) **n'est pas livrée** par cette release. Elle est conditionnée
à 2-3 semaines d'observation et à la validation de critères
statistiques (MAE B ≤ MAE A sur 2 var × 2 buckets × 2 balises, MAE C ≤
MAE B sur ≥ 1 variable, aucun cas `MAE C > 1.5 × MAE A`).

---

## 2026-05-17 — Géolocalisation administrative + cycle d'activation accéléré

Trois améliorations interdépendantes : enrichissement des sites et
balises avec leur découpage administratif (pays / région / département)
pour permettre les filtres dans l'admin, auto-rafraîchissement du
cache map au moindre changement, et fetch météo immédiat à l'activation
d'un site (au lieu d'attendre jusqu'à 12 h le prochain cron).

### Ajouté
- **Géocodage automatique des sites et balises** (cf. `FF_location_enrichment.md`)
  - 6 nouvelles colonnes sur `sites` ET `balises` : `country_code`
    (ISO-2), `country`, `admin_region`, `department`,
    `geocoded_provider`, `geocoded_at`. Indexes : `country_code`,
    `department`, `(country_code, admin_region)`.
  - Service `App\Services\Geocoding\` :
    - `LocationResult` (DTO readonly)
    - `ReverseGeocoderInterface` (contrat single-point)
    - `GeoApiGouvReverseGeocoder` — point-in-polygon sur les communes
      françaises via `geo.api.gouv.fr/communes`. Couvre 100 % des
      coords FR, sans rate limit.
    - `NominatimReverseGeocoder` — fallback monde entier (OSM public),
      rate-limité 1 req/s via `Cache::lock()` distribué (multi-worker safe).
    - `HybridReverseGeocoder` — orchestrateur 2 tiers : geo.api.gouv
      d'abord, Nominatim en fallback. Bindé sur `ReverseGeocoderInterface`.
  - Job `App\Jobs\GeocodeLocationJob` (tries=3, backoff exponentiel
    60/300/900s) + Observer `App\Observers\GeocodableObserver` sur Site
    et Balise → géocodage async à la création et au changement de coords.
  - Commande artisan `php artisan geocode:locations` :
    `--sites` / `--balises` / `--force` / `--chunk=200` / `--limit=N`.
    Idempotente (ne traite que `geocoded_at IS NULL` sans `--force`).
    ~2 min pour 1100 sites + 270 balises.
  - Filtres admin sur `/admin/sites` et `/admin/balises` : 3 nouveaux
    selects (pays / région admin. / département) + colonnes affichées.
  - Config `services.geocoding.{geo_api_gouv,nominatim}` (env vars
    `GEO_API_GOUV_BASE_URL`, `NOMINATIM_USER_AGENT`,
    `NOMINATIM_CONTACT_EMAIL`, `NOMINATIM_RATE_LIMIT`).
- **Auto-rebuild du map bundle** sur changement Site/Balise
  - Observer `App\Observers\MapBundleInvalidationObserver` sur Site +
    Balise → dispatch `RebuildMapBundleJob` (délai 5s) sur
    `created/updated/deleted`, uniquement si un champ visible carte a
    changé (`active`, `latitude`, `longitude`, `name`, `altitude_m`,
    `level`, `landing_lat`, `landing_lng`).
  - `RebuildMapBundleJob` devient `ShouldBeUnique` (lock 30s,
    uniqueId fixe) — 100 toggles en rafale → 1 seul rebuild.
  - `GeocodeLocationJob` passe en `saveQuietly()` pour éviter une
    boucle observer (geocoding → save → rebuild inutile).
  - **Limite** : les `Builder::update()` en masse (tinker, SQL)
    bypassent les events → garder `php artisan map:rebuild-bundle`
    pour ce cas.
- **Fetch météo + scoring immédiat à l'activation d'un site**
  - Observer `App\Observers\SiteActivationObserver` → dispatch
    `FetchSiteForecastsJob` sur :
    - création d'un site déjà actif
    - transition inactif → actif
  - Le job fetch les ~13 modèles en synchrone (~30-60s), lance le
    scoring, invalide les caches du site, dispatch `RebuildMapBundleJob`.
  - Activer 10 sites en rafale → 10 jobs séquentiels (~5-10 min total)
    au lieu de jusqu'à 12 h pour le prochain cron horaire à couvrir
    tous les modèles.
  - Pas appliqué aux balises (leur cycle de fetch est déjà court — 5
    min via `Fetch*ReadingsJob` qui auto-découvrent les balises actives).

### Base de données
- Migration `2026_05_17_130000_add_geocoding_to_sites_and_balises` :
  6 colonnes + 3 indexes sur `sites` et `balises` (cf. ci-dessus).

### Déploiement
- Après migrate, lancer **une fois** :
  ```
  docker exec parapente_php php artisan geocode:locations
  ```
  pour enrichir les ~1370 entrées existantes (~2 min).
- Optionnel : `NOMINATIM_CONTACT_EMAIL` dans `.env` pour l'identification
  polie auprès de Nominatim (User-Agent complet).

### Notes d'architecture
- Tentative initiale avec BAN (`api-adresse.data.gouv.fr`) abandonnée :
  BAN renvoie `not-found` pour la majorité des sites de parapente
  (loin de toute adresse postale indexée). geo.api.gouv.fr couvre 100 %
  des coords FR via point-in-polygon. Cf. `FF_location_enrichment.md`
  pour l'historique complet du diagnostic.

---

## 2026-05-17 — Article épinglé, pseudo-wiki et harmonisation BackOffice

Trois chantiers esthétiques/fonctionnels regroupés sur une même journée :
épinglage d'un article en tête d'accueil (« philosophie » de Qui Vole),
section d'aide en ligne (pseudo-wiki) et passe d'harmonisation visuelle
de l'interface admin.

### Ajouté
- **Article épinglé** sur l'accueil
  - Colonne `articles.is_pinned` (avec index), scope `Article::pinned()`.
  - Au plus un article épinglé à la fois — contrainte garantie côté
    contrôleur (`Admin\ArticleController::store/update` dépinglent les
    autres avant l'écriture).
  - Affichage dédié en tête de la page d'accueil (bloc style ambre
    « À la une » au-dessus du flux d'actualités).
  - Admin : checkbox « Épingler en tête d'accueil » dans le formulaire,
    badge « Épinglé » dans la liste.
- **Pseudo-wiki / aide en ligne** sur `/aide`
  - Table `wiki_pages` : arborescence simple (`parent_id` auto-référent),
    slug unique, body HTML (TinyMCE), `excerpt`, `sort_order`,
    `is_published`, `author_id`. Index sur `(parent_id, sort_order)` et
    `(is_published, sort_order)`.
  - Modèle `App\Models\WikiPage` : relations `parent()`, `children()`,
    `author()`, scope `published()`, helpers `publishedRoots()` et
    `ancestors()`.
  - Contrôleurs `WikiController` (public, `index` + `show`) et
    `Admin\WikiPageController` (CRUD complet avec slug auto, prévention
    des boucles parent/enfant, upload TinyMCE dédié).
  - Routes : `GET /aide` (`wiki.index`), `GET /aide/{slug}`
    (`wiki.show`) ; admin `/admin/wiki/*` (`admin.wiki.*`).
  - Vues publiques : `<x-app-shell>` avec navigation arborescente dans
    le panneau gauche, fil d'Ariane, sous-pages en footer.
  - Module « Aide » (clé `wiki`) ajouté à la barre de menu via
    migration data idempotente (et entrée dans `ModuleSeeder` pour la
    cohérence).
- **Composants admin standardisés** dans `resources/views/components/admin/`
  - `<x-admin.page-title>` — titre h1 + sous-titre + slot `actions`.
  - `<x-admin.badge>` — palette de statuts (published/draft/active/
    admin/info/warning/danger/neutral/...).
  - `<x-admin.empty-state>` — état vide cohérent (slot `actions`).
  - `<x-admin.alert>` — bandeau success/error/warning/info.
  - `<x-admin.input>` — champ texte avec label/hint/erreur auto-gérés
    (lit `old()` et `$errors` automatiquement).
  - `<x-admin.field>` — wrapper générique label + erreur pour
    `<textarea>` / `<select>` complexes.
  - `<x-admin.section>` — section colorée avec icône (palette
    gray/sky/violet/emerald/amber/red).

### Modifié
- `<x-admin.button>` : la prop `icon="fa-..."` est désormais wrappée
  automatiquement dans `<i class="..."></i>` (avant : rendu brut). Pour
  passer un emoji ou un SVG inline, écrire la valeur littérale.
- `layouts/admin.blade.php` affiche maintenant globalement les flash
  `session('status' | 'error' | 'warning')` via `<x-admin.alert>` ; les
  ~5 vues qui répétaient le bloc localement ont été nettoyées.
- 20 vues admin refactorées pour utiliser ces composants : suppression
  de la duplication des constantes `$inputCls` / `$labelCls` /
  `$errorCls`, normalisation des `<thead>` (cohérence `bg-gray-950`),
  unification des badges et empty-states.
- `RecordPageView` (middleware analytics) : ajout de `/icons-cache/` à
  `shouldSkipPath`. Les URLs des icônes SpotAir passent par PHP au
  premier hit avant que Nginx ne prenne la relève — sans ce skip, ça
  polluait `/admin/traffic` (chaque icône non encore cachée comptait
  comme une visite).

### Corrigé
- Vue `/admin/traffic` : les `<text>` des graphes étaient déformés
  horizontalement (SVG `preserveAspectRatio="none"` + viewBox `100×184`
  rendu en `~1180×192` → étirement ~12×). Les labels sortent
  désormais du SVG et sont rendus en HTML positionné en `%` (police
  monospace cohérente).

### Base de données
- `2026_05_17_100000_add_is_pinned_to_articles.php` — colonne
  `articles.is_pinned` (bool, défaut false) + index.
- `2026_05_17_110000_create_wiki_pages_table.php` — table `wiki_pages`.
- `2026_05_17_110100_add_wiki_module.php` — migration data idempotente
  (upsert) pour ajouter le module « Aide » dans `modules`.
- `2026_05_17_120000_purge_icons_cache_page_views.php` — purge
  rétroactive des entrées `/icons-cache/*` dans `page_views` (les
  visites artificielles loggées avant le skip middleware).

### Déploiement
```bash
docker exec parapente_php php artisan migrate --force
docker exec parapente_php php artisan optimize:clear && php artisan optimize
docker compose -f docker-compose.prod.yml restart parapente-php
docker compose -f docker-compose.prod.yml restart parapente-nginx
```

---

## 2026-05-17 — Tampon disque pour les icônes SpotAir

Mise en place d'un tampon sur disque pour toutes les icônes SVG
servies par l'API SpotAir (sites + balises). Les icônes ne sont plus
chargées directement depuis `spotair.mobi` par les navigateurs ; elles
transitent par `public/icons-cache/{site|balise}/.../*.svg`, peuplé à
la demande au premier hit puis servi en statique par Nginx.

### Ajouté
- **`App\Http\Controllers\IconCacheController`** : 2 méthodes
  `site()` / `balise()`, fetch SpotAir + écriture atomique
  (`tmp + rename`), validation des paramètres, garde-fous
  anti-réponse vide / SpotAir injoignable.
- **Routes** `GET /icons-cache/site/{p}/{t}/{n}/{o}.svg` et
  `GET /icons-cache/balise/{d}/{v}/{t}/{bg}/{c}.svg` (contraintes
  regex strictes sur tous les paramètres).
- **Bloc Nginx dédié** `location ^~ /icons-cache/` (dev + prod) :
  prend la priorité sur la regex statique `\.svg$`, sert le fichier
  s'il existe sinon fallback Laravel pour génération lazy.

### Modifié
- `siteIconUrl()` et `baliseIconUrl()` (vue carte) : pointent vers
  les URLs du tampon au lieu de `spotair.mobi`.
- Légende de la carte (`map/_partials/html/left-panel.blade.php`) :
  les 10 icônes-exemples passent aussi par le tampon.
- `.gitignore` : exclusion de `/public/icons-cache`.

### Notes
- Reset complet du tampon : `rm -rf src/public/icons-cache/`. Reset
  partiel par type : `rm -rf src/public/icons-cache/site/` ou
  `.../balise/`.
- Premier hit après reset : `chown -R www-data:www-data` sur
  `public/icons-cache/` peut être nécessaire selon les permissions du
  mount Docker.
- Volume en régime stable estimé à ~250 SVG / ~4 Mo (mesuré en prod
  avec 149 sites + 228 balises actives). Plafond théorique à 1000+1000
  : ~1500 SVG / ~15 Mo.
- Discussion future : rotation lente du tampon (refresh sur changement
  de charte SpotAir) — cf. `FF_icon_cache_rotation.md`.

---

## 2026-05-16 — Passe de refactoring et d'optimisation (6 phases)

Passe complète de refactoring et d'optimisation du code, découpée en
6 phases successives — chacune commitée, poussée, testée en local et
en prod (desktop + mobile) indépendamment. Aucun changement fonctionnel
observable. Net : **~ -900 LOC**, structure projet plus testable, perfs
ciblées sur les hot paths.

### Ajouté
- **`App\Services\Balises\BaliseConstants`** : centralise les
  constantes partagées entre fournisseurs/jobs balises (`DEAD_AFTER_DAYS`).
- **`config/weather.php`** : palette des modèles NWP (`MODEL_COLORS`)
  extraite du controller, avec entrée `fallback` pour le gris neutre.
- **`App\Jobs\FetchBaliseReadingsJob`** (abstrait) : factorise les
  3 jobs `Fetch{PiouPiou,Metar,Windy}ReadingsJob` (90 % du code
  partagé : fetch → insert → désactivation des balises mortes →
  invalidation cache). Les sous-classes ne déclarent plus que la
  source, le provider et leur timeout.
- **`App\Services\Weather\SiteScoresPayloadBuilder` / `SiteChartPayloadBuilder` / `SiteMultimodelPayloadBuilder`** :
  3 services dédiés aux 3 endpoints de `/api/sites/{id}/*`. Logique
  métier extraite du controller (250+ LOC) → testable et réutilisable.
- **`App\Services\Balises\BaliseReadingFormatter`** : sérialisation
  centralisée des lectures balises (`meteorology()` + `floatOrNull()`),
  élimine les 10 occurrences de `$x !== null ? (float) $x : null`.
- **`App\Http\Requests\Api\ScoringRules::flyingConditions()`** :
  catalogue des règles de validation partagé entre `Store/UpdateUserScoringRequest`.
- **`App\Http\Controllers\Concerns\HasFilterableIndex`** (trait) :
  helpers `applySearch` / `applyTriStateFilter` / `applySorting` (avec
  whitelist anti-injection) utilisés par les controllers admin Site,
  Balise, WeatherModel, User.
- **Composant Blade `<x-admin.button>`** : bouton standardisé pour le
  BackOffice avec variants `primary|secondary|danger|ghost` et tailles
  `sm|md`. Migration progressive des ~50 boutons admin existants.
- **Helper JS `window.AppShell.isDesktop()`** : single source of truth
  pour le breakpoint `min-width:1024px` (volets latéraux ouverts
  d'emblée en desktop), exposé dans `app-shell.blade.php`.
- **CSS custom properties** dans `map/_partials/styles/base.blade.php` :
  `--font-mono`, `--c-bg-dark`, `--c-border-25/4/5`. Centralisent les
  valeurs hex/rgba répétées ≥ 3 fois dans les fichiers de styles map.
- **`svgHelpers(svg)`** factory dans `geometry.blade.php` : retourne
  `{ mk, txt }` bound au SVG racine. Remplace les 4 définitions
  locales `mk`/`txt` dupliquées dans popup-chart et balise-chart.
- **Dépendance npm `@floating-ui/dom`** : installée et exposée via
  `window.FloatingUI = { computePosition, offset, flip, shift, autoUpdate }`.
  Disponible pour migration progressive des dropdowns / tooltips qui
  utilisent encore le calcul manuel `position:fixed`.
- **Migration additive `2026_05_16_*_add_balises_source_active_index`** :
  index composite `balises(source, active)`, idempotent
  (`CREATE INDEX IF NOT EXISTS`). Filtre utilisé par les 3 jobs balises
  + le bundle `/api/balises`.

### Modifié
- **`Api/SiteController`** : passe de **627 → 142 LOC** (-77 %).
  Devient un fin orchestrateur ; toute la logique métier est dans les
  3 payload builders + `SunWindowCalculator` + `DayQualityCalculator`
  (services qui existaient déjà mais étaient dupliqués inline).
- **`Api/BaliseController`** : utilise `BaliseReadingFormatter::meteorology()`
  pour la sérialisation des lectures (PiouPiou + METAR + Windy).
- **`ScoringService`** : précharge les 4 seuils globaux (`precip_orange/red`,
  `gust_orange/red`) au constructor. Évite ~6 lookups `Settings::get()`
  par créneau × 120 créneaux/site (les valeurs sont identiques pendant
  toute la durée d'un scoring).
- **`WindyOpenDataProvider::fetchLatestReadings()`** : parallélisé via
  `Http::pool()` (chunks de 10 simultanés). Pour 50 balises : ~80 % de
  latence en moins (5 batches parallèles vs 50 séquentiels). Réduit
  drastiquement le risque de timeout du job.
- **`MapBundleBuilder::build()`** : élimine le N+1 SQL (1 query
  `whereIn('site_id')->upcoming()` pour TOUS les sites, puis `groupBy`
  PHP). À 14 sites c'est anecdotique, à 100+ c'est décisif.
- **`FetchBaliseReadingsJob` (base)** : cache Redis
  `balises.last_reading_by_balise:{source}` (TTL 1 h, réécrit à chaque
  cycle) — évite un `GROUP BY MAX(read_at)` SQL à chaque tick × 3 sources.
- **`OpenMeteoApi`** : constante `HOURLY_VARS_BALISES` (4 variables au
  lieu d'une string en dur déconnectée de `HOURLY_VARS` côté sites).
- **Vue carte (`app.blade.php`)** :
  - Cache mémoire Alpine `_multimodelByDay` : re-navigation entre jours
    déjà chargés = 0 fetch (vs 1 par jour avant). Reset à `pickSite`/`closeRightPanel`.
  - `AbortController` sur `/api/balises` : élimine la race condition
    entre 2 polls qui se chevauchent (réponse en retard qui écrase la
    fraîche en cas de réseau lent).
- **`SiteSeeder`** : `insertGetId()` / `insert()` bruts →
  `Eloquent::updateOrCreate` (matching par `slug` + `site_id`). Idempotent
  en redéploiement.

### Performance
- **API externes** : Windy `fetchLatestReadings()` ~80 % plus rapide
  (parallélisation pool).
- **DB** : N+1 SQL éliminé sur `MapBundleBuilder` ; index composite
  sur `balises(source, active)` ; cache Redis `last_reading_by_balise`
  qui évite un GROUP BY par tick × 3 sources.
- **Client** : cache mémoire `multimodel5ByDay` (navigation entre
  jours sans refetch) ; dédup polling balises (`AbortController`).

### Supprimé / nettoyé
- Méthodes privées dupliquées `SiteController::getSunWindow()` et
  `SiteController::computeDayQuality()` (réutilisent désormais les
  services existants `SunWindowCalculator`/`DayQualityCalculator`).
- Constante `MODEL_COLORS` privée du `SiteController` (déplacée en
  config).
- 3 occurrences de `private const DEAD_AFTER_DAYS = 7` dans les jobs
  balises (centralisées dans `BaliseConstants`).
- Définitions locales de `mk()` / `txt()` dans 4 fonctions SVG
  (popup-chart × 2, balise-chart × 3).

### Déploiement
- Cette passe ajoute une dépendance npm (`@floating-ui/dom`) → étape
  `npm install && npm run build` standard du déploiement.
- Nouvelle migration additive (idempotente via `CREATE INDEX IF NOT EXISTS`)
  → `php artisan migrate --force` standard.

---

## 2026-05-16 — Cache pré-calculé des données carte (`App\Services\Map\*`)

Tous les endpoints alimentant la vue carte sont désormais cachés en
Redis : boot de `/carte` accéléré drastiquement (passage de 15+ requêtes
HTTP à 1-2 ; latence DB transformée en lecture Redis).

### Ajouté
- **Endpoint `GET /api/map-bundle`** : bundle pré-calculé pour le boot
  de la carte. Contient tous les sites actifs + statuts journaliers
  agrégés + fenêtres solaires + agrégat global par jour. Cache Redis
  partagé entre tous les utilisateurs, régénéré à la fin de chaque
  cycle de scoring. Cache-Control public + ETag.
- **Endpoint `GET /api/me/scoring-overrides`** (auth) : sur-couche
  utilisateur du bundle global — renvoie les overrides scoring perso
  (badge actif/inactif + statuts journaliers recalculés). Léger
  (1-3 sites max en pratique), fusionné côté client.
- **Service `App\Services\Map\MapBundleBuilder`** : construit le map
  bundle global, cache Redis versionné (`map.bundle.v1`, TTL 90 min)
  avec fallback lazy et lock anti-thundering-herd.
- **Service `App\Services\Map\SiteDetailCache`** : cache transparent
  des endpoints `/api/sites/{id}/{scores,chart,multimodel}`. `scores`
  cache la version « global » avec overlay scoring perso appliqué par
  le contrôleur si user authentifié.
- **Service `App\Services\Map\BalisesBundleCache`** : cache transparent
  de `/api/balises` (TTL 5 min) et `/api/balises/{id}/history` (TTL 2 min).
- **Services helpers** : `SunWindowCalculator` (fenêtre solaire) et
  `DayQualityCalculator` (viabilité d'un jour) — extraits de
  `SiteController` pour être partagés avec `MapBundleBuilder`.
- **Job `RebuildMapBundleJob`** : régénère le map bundle et l'écrit
  en cache Redis. Idempotent.
- **Commande `php artisan map:rebuild-bundle [--clear]`** : force la
  régénération (ou le vidage) du cache map en dev/prod.
- Tests : 16 nouveaux tests Feature couvrant la structure du bundle,
  le cache hit/miss, l'invalidation par les jobs, l'overlay scoring
  perso, et le 404 sur balise inactive.

### Modifié
- **`FetchForecastsJob`** refactoré : utilise `Bus::batch()` autour des
  chaînes de fetch+score (1 par site) avec `allowFailures()`. Le
  callback `finally` du batch dispatch `RebuildMapBundleJob` quand
  toutes les chaînes ont terminé — garantit la cohérence du cache map
  avec les scores fraîchement calculés.
- **`ScoreSiteJob`** : à la fin du scoring, invalide `SiteDetailCache`
  du site et les caches user-scoring qui en dépendent.
- **`FetchSiteForecastsJob`** : invalide aussi `SiteDetailCache` +
  dispatch `RebuildMapBundleJob` à la fin (opérations manuelles).
- **`FetchPiouPiouReadingsJob`, `FetchMetarReadingsJob`,
  `FetchWindyReadingsJob`** : invalident `BalisesBundleCache` à la
  fin de leur cycle d'ingestion.
- **`SiteController`** refactoré : chaque endpoint extrait sa logique
  pure dans une méthode `buildXxxPayload(): array` (compatible cache),
  le contrôleur ne fait plus que `cache->remember()` + transformations
  user-spec éventuelles. Sortie JSON identique à l'existant.
- **`BaliseController`** refactoré dans le même esprit.
- **Vue carte (`map._partials.scripts.app.blade.php`)** : `loadSites()`
  fait 1 fetch `/api/map-bundle` (au lieu de 15+ appels en cascade) +
  optionnellement 1 fetch `/api/me/scoring-overrides` si user
  authentifié. `loadSiteScores()` devient lazy (appelé au clic sur un
  marker, pour l'onglet « Détail scoring »). `buildDays()` supprimé,
  l'agrégat global est pré-calculé côté serveur.

### Performance (mesures locales sqlite — gains supérieurs en prod)
| Endpoint | Cache miss | Cache hit | Gain |
|---|---|---|---|
| `/api/sites/{id}/scores` | 272 ms | 24 ms | ×11 |
| `/api/sites/{id}/chart` | 31 ms | 17 ms | ×2 |
| `/api/sites/{id}/multimodel` | 111 ms | 18 ms | ×6 |
| `/api/balises` | 364 ms | 17 ms | ×21 |

### Points d'attention
- Toute évolution du payload d'un endpoint caché doit s'accompagner
  d'un **bump de `CACHE_VERSION`** dans le service correspondant
  (sinon les vieilles entrées Redis cassent la vue).
- En cas de migration / changement de schéma touchant `site_scores`
  ou `balise_readings` : penser à `php artisan cache:clear` au
  déploiement (le TTL backup couvre 90 min max).
- L'invalidation push (jobs → cache forget) est doublée d'un TTL
  backup (90 min pour les sites, 5 min pour les balises) — robustesse
  même si un hook est oublié.

---

## 2026-05-15 — Refonte mobile de la vue carte

### Modifié
- La **vue carte** (`/carte`) est désormais bâtie sur `<x-app-shell>`
  comme toutes les autres pages, au lieu d'un layout dédié
  `layouts/app.blade.php` (supprimé). Bénéfices :
  - **Volets latéraux en overlay sur mobile** (au lieu de pousser le
    contenu) : les volets gauche (filtres / légende) et droit (détail
    site / balise) glissent par-dessus la carte sur mobile, avec
    backdrop semi-transparent. La carte reste visible derrière.
  - **Navbar toujours accessible** : le bouton ☰ (volet gauche) et le
    bouton ? (volet droit) sont désormais dans la navbar globale,
    accessibles en permanence — plus de cas où le menu du haut est
    masqué par les volets.
  - Sur desktop, le volet droit s'élargit dynamiquement
    (`clamp(420px, 45vw, 640px)` en `lg`, `50vw` en `xl`) pour
    accueillir les graphes et tableaux scoring sans débordement.
- **Sélecteur de jour & fond de carte sur mobile** : sur petit écran
  (< 640 px) le sélecteur de jour flottant en haut-droite de la carte
  est remplacé par une section en haut du volet gauche. Le sélecteur
  de fond de carte y était déjà ; cela libère totalement l'espace
  carte sur mobile.
- **Hiérarchie z-index unifiée** (30 backdrop / 40 navbar+volets /
  50 dropdowns navbar / 70 contrôles flottants carte / 80 tooltips).
  Plus de `z-index: 99999` qui écrase tout.
- `mapApp()` expose désormais `leftOpen` / `rightOpen` (au lieu de
  `leftCollapsed` / `rightPanelOpen`) — ces variables sont partagées
  avec le shell global et pilotées par les boutons de la navbar.

### Corrigé
- `#right-panel .rp-wrap { min-width: 50vw }` qui forçait un
  débordement horizontal sur écran étroit a été supprimé.

### Supprimé
- `resources/views/layouts/app.blade.php` (legacy, plus utilisé).

---

## 2026-05-15 — Trafic / fréquentation (admin)

### Ajouté
- Nouvelle section **`/admin/traffic`** : tableau de bord de
  fréquentation self-hosted, alimenté par un middleware
  `RecordPageView` branché sur le groupe `web`.
  - **KPI tiles** : visites + uniques sur 3 fenêtres (aujourd'hui,
    7 j, 30 j) avec delta % vs période précédente.
  - **Graphe horaire** : visites par heure pour la journée en cours
    (24 buckets, SVG inline).
  - **Graphe quotidien** : visites par jour sur 30 j (SVG inline).
  - **Top pages** (30 j), **type d'appareil**
    (bureau/mobile/tablette/bot) avec barres de progression,
    **OS + navigateur** avec %, **sites référents** (30 j).
- **Conformité RGPD** : pas de cookie posé, pas de bannière de
  consentement nécessaire. Le `visitor_hash` est un SHA-256
  (ip + UA + jour + APP_KEY) qui tourne à minuit — uniques/jour
  comptables, suivi inter-jour impossible.
- Filtres appliqués par le middleware : seules les pages GET/HEAD
  avec status 2xx/3xx ; les `/api/*`, `/admin/*`, AJAX, assets et
  bots sont écartés (bots quand même enregistrés pour traçabilité,
  mais filtrés des KPI « humains »).
- Parseur User-Agent maison (substring matching, sans dépendance) :
  reconnaît iOS/Android/Windows/macOS/Linux/ChromeOS + Chrome/
  Firefox/Safari/Edge/Opera/Brave/Vivaldi + bots les plus courants.

### Base de données
- Migration `2026_05_15_140000_create_page_views_table.php` (table
  `page_views` avec index sur `visited_at`, `(visited_at, device_type)`,
  `(visitor_hash, visited_at)`).
- Nouveau setting **`pageviews.retention_days`** (défaut 365) éditable
  dans `/admin/settings` (nouveau groupe « Trafic / analytics »).
- Job **`PurgePageViewsJob`** schedulé tous les jours à 03h15 qui
  supprime les lignes au-delà de la rétention (lots de 5000).

### Architecture
- `App\Http\Middleware\RecordPageView` — branché en `append` du
  groupe `web` dans `bootstrap/app.php`. Ne casse jamais la requête
  (try/catch + log warning).
- `App\Services\Analytics\UserAgentParser` — heuristique
  substring/regex, ordre d'évaluation soigné (Edge avant Chrome,
  Chrome avant Safari, iPad/tablette avant mobile, etc.).
- `App\Http\Controllers\Admin\TrafficController` — agrégations SQL
  natives (`HOUR(visited_at)`, `DATE(visited_at)`,
  `COUNT(DISTINCT visitor_hash)`), tout filtre bot côté humain.
- Vue Tailwind + SVG inline pour les charts (cohérent avec le reste
  de l'admin, pas de dépendance JS).

---

## 2026-05-15 — Source balises : Windy.com (Open Data API v2)

### Ajouté
- Nouveau provider **`WindyOpenDataProvider`** (source `windy`) qui
  consomme la **Stations API v2** (mise en service janv. 2026) en mode
  *Open Data* (`/api/v2/opendata/station` + `…/{id}/observation`).
- **Saisie de la clé API dans `/admin/settings`** (nouveau groupe
  *« Sources balises »*, clé `windy.api_key`). Champ password masqué
  avec toggle d'affichage et preview des 4 derniers caractères. Sans
  clé, le provider est **silencieux** (pas d'appel HTTP, log warning).
- Provider câblé dans :
  - `GeoDeploymentService::providers()` → le déploiement géographique
    par ville embarque automatiquement Windy.
  - `BalisesDiscover` (commande `php artisan balises:discover --source=windy`).
  - `/admin/sync` → nouvelle section « Balises — Windy.com (Open Data) »
    avec garde-fou si la clé n'est pas configurée.
- Nouveau job **`FetchWindyReadingsJob`** scheduled toutes les 30 min
  (cadence conservatrice : Windy ne propose pas de batch, c'est 1
  appel HTTP par balise active).

### Settings — type `secret` / `string`
- Le service `Settings` accepte désormais `type=secret` (champ password
  full-width dans la vue admin, autocomplete=new-password) et
  `type=string`. Validation `nullable|string|max:500`.
- Refactor mineur de `SettingsController::update` (cast unifié par type).

### Base de données
- Migration **`2026_05_15_130000_add_reliability_class_to_balises.php`** :
  ajoute deux colonnes pré-requises pour ouvrir les sources de masse :
  - `balises.reliability_class` ENUM(`pro`, `amateur`) nullable —
    backfill des balises existantes : pioupiou + metar passent toutes
    en `pro`. Les futures balises Windy sont à tagger heuristiquement
    (par `model`/`vendor` renvoyé par Windy, à durcir).
  - `balises.height_agl_m` SMALLINT UNSIGNED nullable — hauteur de
    l'anémomètre au-dessus du sol (10 m mât aérodrome vs 2 m jardin).
  - Index `(active, reliability_class)` pour permettre au futur scoring
    de filtrer rapidement sur *pro only*.

### Format Windy v2 figé (sonde 2026-05-15)
- **Catalogue** : `{ data: [...], pagination: {page, pageSize,
  totalPages, offset, totalItems} }`. ~14 300 stations Open Data
  mondiales à la mise en service de l'API. Filtre bbox **ignoré
  côté serveur** → on scanne toutes les pages et on filtre client.
- **Champs station** : `id`, `name`, `lat`, `lon`, `elev_m`,
  `agl_wind`, `agl_temp`, `station_type` (texte libre saisi par les
  propriétaires — variantes orthographiques attendues, parser
  insensible à la casse + substring match), `is_online`,
  `last_observation_time`, `share_option`.
- **Observation** : `data.{wind, wind_dir, wind_gust, pressure, ts}`,
  séries temporelles alignées sur le même axe `ts` (unix
  **millisecondes** — c'est précisé parce que Windy facture le
  détail). Pas de `temp` / `humidity` sur certaines stations
  (Netatmo sans module T°) → traités nullable.
- **Vent** publié en **m/s** côté Windy → conversion en km/h.
  **Direction** en convention FROM (norme météo, comme PiouPiou
  après inversion).
- **Pression** disponible (en Pa, pas hPa) mais ignorée — pas de
  colonne en base.

### Constats opérationnels
- **77 % des stations Open Data sont offline** (sonde sur 100
  premières) → notre filtre `is_online` retient ~23 %.
- Le `station_type` confirme le verdict du FF : **Davis Vantage Pro
  2** ≈ 50 %, Netatmo / Ecowitt / Ambient le reste. Quasiment aucune
  station « pro » au sens parapente. Le mapping
  `reliability_class='amateur'` par défaut est bien calibré.
- **Filtrage scoring sur `reliability_class`** non encore implémenté :
  la voting logic actuelle ignore le flag — par construction, ouvrir
  Windy n'aggrave rien tant qu'on ne reçoit que des stations *pro*
  (ce qui est probable au démarrage). À ajouter avant d'introduire
  Netatmo / Davis amateur en masse.

---

## 2026-05-15 — Qualité des données : détection des doublons (admin)

### Ajouté
- Nouvelle section **`/admin/data-quality`** qui liste les paires de
  doublons potentiels sur la base des coordonnées géographiques.
  - **Sites** : distance Haversine + écart d'altitude + chevauchement
    d'orientation (intersection des arcs `wind_dir_min`→`wind_dir_max`).
    Les critères altitude / orientation sont **ignorés** si la donnée
    manque sur l'une des deux entités.
  - **Balises** : distance, **tous réseaux confondus**. Les paires
    **intra-réseau** sont triées en tête (suspicion forte), les paires
    **inter-réseaux** sont marquées « info » (souvent légitimes : un
    pioupiou et un METAR sur le même aéroport, par ex.).
- Pour chaque paire : side-by-side des deux entités (statut actif/inactif),
  boutons **Désactiver A** / **Désactiver B** (passent par les toggles
  existants) et **Ignorer la paire** (mémorisée en base).
- Toggle **« Voir aussi les paires ignorées »** + bouton **Restaurer**.
- 4 nouveaux **seuils éditables** dans `/admin/settings` (groupe
  *« Qualité des données »*) :
  - `quality.site_dup_distance_m` (défaut 200)
  - `quality.site_dup_altitude_m` (défaut 30)
  - `quality.site_dup_orientation_overlap_pct` (défaut 60)
  - `quality.balise_dup_distance_m` (défaut 300)

### Base de données
- Nouvelle table **`ignored_duplicates`** (polymorphe `site|balise`,
  paires canonisées `entity_a_id < entity_b_id`, unique sur le triplet).
- Migration `2026_05_15_120000_create_ignored_duplicates_table.php`.
- `SettingsSeeder` à relancer (idempotent) pour insérer les clés `quality.*`.

### Architecture
- `App\Services\DataQualityService` : détection O(n²) avec early-exit
  sur l'écart latitudinal, suffisante jusqu'à plusieurs dizaines de
  milliers d'entités (à bucketiser au-delà).
- `App\Models\IgnoredDuplicate` + `App\Http\Controllers\Admin\DataQualityController`.
- Calcul du chevauchement angulaire par discrétisation 360° (gère
  les arcs traversant le Nord, ex. 315→45).

---

## 2026-05-15 — Déploiement géographique (admin)

### Ajouté
- Nouvelle carte **« 🌍 Déploiement géographique »** en tête de
  `/admin/sync` : on saisit une **ville** et un **rayon en km**, l'app
  géocode (API Open-Meteo, gratuite, sans clé), calcule une bbox +
  filtre Haversine au rayon exact, puis :
  - **active** tous les sites de la base situés dans le rayon
    (`sites.active = true`) — sans rien désactiver hors zone ;
  - **découvre + active** les balises **PiouPiou** et **METAR** via
    les providers existants (`PiouPiouProvider`, `MetarProvider`).
- Synthèse de retour : nb sites / balises **nouvellement activés**,
  **déjà actifs**, et **dans la zone** par réseau.
- Les balises désactivées manuellement (`active=false`) restent off
  (cohérent avec la politique de `BaliseController` — la désactivation
  manuelle bloque la réactivation auto).

### Architecture
- `App\Services\GeoDeploymentService` : géocodage + bbox + Haversine +
  activation sites + boucle providers.
- `DataSyncController::deploy()` + route `POST /admin/sync/deploy`.

## 2026-05-14 — Robustesse de l'écran « Couverture des données »

### Corrigé
- **500 récurrent sur `/admin/data-coverage`** (notamment après une
  visite de la carte) causé par d'anciens payloads sérialisés dans le
  cache (file en dev, redis en prod) contenant des Eloquent
  `WeatherModel` complets — au déballage avec le nouveau code (qui
  attend des `stdClass` issus de `lightModel()`), PHP renvoyait des
  `__PHP_Incomplete_Class` et Blade crashait sur `$row['model']->id`.
  Le `try/catch` du service ne pouvait pas attraper l'erreur car elle
  survenait dans le template, après que le service ait renvoyé sa
  payload.

### Modifié
- `App\Services\DataCoverage` :
  - **Cache désactivé** sur les 4 sections (rebuild systématique à
    chaque requête — page admin consultée ponctuellement, build
    < 100 ms). La signature `safeSection()` est conservée pour
    permettre une réactivation ultérieure.
  - **Stockage en primitives** (strings/ints uniquement, plus de
    Carbon en cache) : si on réactive le cache un jour, les
    timestamps seront sérialisés en string ISO 8601 et reconstruits
    en Carbon à la lecture via `inflate*Payload()`.
  - **Requête `weather_fetch_log` bornée** à 14 jours / 2000 lignes
    (avant : chargement de toute la table en mémoire sans `LIMIT`).
  - **Résilience par section** : chaque méthode est wrapped dans un
    `try/catch` qui log via `Log::error` et renvoie un payload vide
    si la build échoue — la page reste affichable au lieu d'un 500
    nu.

### Déploiement
Après ce déploiement, **nettoyer agressivement les caches** une seule
fois pour purger les anciennes entrées sérialisées :
```bash
docker exec parapente_php php artisan optimize:clear
docker exec parapente_php rm -rf storage/framework/cache/data/* \
    storage/framework/views/* bootstrap/cache/*.php
docker compose restart parapente-php
```

---

## 2026-05-14 — Élargissement du catalogue de modèles météo

### Ajouté
- **6 nouveaux modèles** désormais disponibles côté Open-Meteo
  self-hosted, ajoutés à `OpenMeteoApi::supportedModelCodes()` et au
  `WeatherModelSeeder` :
  - `meteofrance_arome_france0025` — AROME 0.025° (~2.5 km, horizon
    51h, w_short 0.95). Complète AROME-HD (1.3 km, 48h) avec une
    grille standard. **Actif**.
  - `cmc_gem_gdps` — GEM GDPS Canada (15 km, 240h, w_short 0.65,
    w_medium 0.80). Complément global moyen-terme. **Actif**.
  - `ncep_gfs_graphcast025` — GraphCast (NOAA/DeepMind, IA, 25 km,
    240h, w_short 0.60, w_medium 0.80). **Actif**.
  - `ncep_aigfs025` — AI-GFS NOAA (25 km, 240h, w_short 0.55,
    w_medium 0.75). **Actif**.
  - `ncep_aigefs025` — AI-GEFS ensemble NOAA. **Inactif** par défaut
    (source serveur encore vide ~12 K).
  - `ncep_hgefs025_ensemble_mean` — Hybrid GEFS ensemble mean NOAA.
    **Inactif** par défaut (un ensemble *mean* lisse trop les pics de
    vent utiles au scoring parapente).
- Migration `2026_05_14_130000_add_new_weather_models` qui insère
  ces 6 lignes pour les BDD existantes (idempotent). `WeatherModelSeeder`
  mis à jour pour les fresh installs.
- Ajout supplémentaire de `cmc_gem_rdps` (GEM régional Canada,
  10 km, 84h) — **inactif** par défaut, couverture limitée à
  l'Amérique du Nord. Migration `2026_05_14_140000_add_cmc_gem_rdps_model`.

---

## 2026-05-14 — Écran admin « Couverture des données météo »

Nouvel écran d'administration pour mesurer en un coup d'œil le taux
réel de remplissage des données collectées sur leurs périodes de
rétention respectives, et la fraîcheur des fetches par modèle météo.
Outil de diagnostic pour repérer rapidement les modèles cassés, les
balises en panne ou les jours blancs avant que ça ne biaise les calculs
en aval (notamment la future fiabilité dynamique, cf.
`FF_model_reliability.md`).

### Ajouté
- **`/admin/data-coverage`** : page d'administration (lien dans la
  sidebar « Système ») composée de 4 tableaux :
  1. *Modèles météo &mdash; fraîcheur* : un modèle par ligne, avec
     dernier fetch site / balise, cadence prévue vs réalisée, décalage
     en %, nombre de lignes upsertées au dernier run, et heure du run
     provider quand elle est connue.
  2. *Prévisions sites &mdash; J → J+4* : matrice (modèles × jours),
     cellule = `heures distinctes / (24 × sites actifs)` en %.
  3. *Prévisions balises &mdash; J-7 → J+5* : même matrice sur
     `forecast_archive_balises`, dénominateur = balises actives.
  4. *Relevés balises &mdash; J-7 → J* : **groupé par réseau**
     (PiouPiou, METAR, …) avec une ligne de synthèse cliquable pour
     déplier le détail balise par balise. Agrégat réseau =
     `Σ heures reçues / (24 × nb_balises_du_réseau)`. Plus la
     dernière réception côté `balise_readings`.

  Code couleur : vert ≥ 95 %, orange 50–95 %, rouge &lt; 50 %, gris si
  aucune donnée. Cache Redis 5 min.

- **`App\Services\DataCoverage`** : service qui produit les 4
  agrégations en jointures SQL groupées par `DATE(target_at)` /
  `DATE(forecast_at)` / `DATE(hour_at)`, avec garde-fous quand les
  tables n'existent pas encore (resté tolérant comme le job de purge).

### Base de données
- **`weather_fetch_log`** : nouvelle table journal des fetches météo,
  une ligne par exécution de `FetchSiteModelJob` ou
  `FetchBaliseForecastsJob` (colonnes : `weather_model_id`, `scope`
  `site`/`balise`, `fetched_at`, `rows_upserted`, `provider_run_at`
  nullable). Sert au tableau de fraîcheur. Rétention 30 jours via
  `PurgeOldForecastsJob`.

### Modifié
- `FetchSiteModelJob` et `FetchBaliseForecastsJob` enregistrent
  désormais une ligne dans `weather_fetch_log` à chaque exécution
  (succès ou fetch vide).
- `PurgeOldForecastsJob` purge `weather_fetch_log` au-delà de 30 jours.

### Notes
- L'extraction de `provider_run_at` côté Open-Meteo n'est pas câblée
  dans cette PR (la réponse JSON ne l'expose pas systématiquement).
  La colonne est en place, prête à être renseignée quand on
  instrumentera l'API.

### Corrigé
- **Code du modèle AROME-HD 15min** : `meteofrance_arome_france_hd_15m`
  → `meteofrance_arome_france_hd_15min` (alignement sur le code servi
  par l'instance Open-Meteo self-hosted). L'ancien code retournait
  systématiquement une réponse vide. Migration de mise à jour fournie
  (`2026_05_14_110000_fix_arome_15min_model_code`) — pas besoin de
  reseed.

### Modifié (suite)
- **Calcul de couverture horizon-aware** : pour les tableaux
  *Prévisions sites* et *Prévisions balises*, le dénominateur de
  chaque cellule (modèle × jour) est désormais ajusté par
  `max_horizon_h`. Un nowcast à 6 h n'est plus pénalisé sur J+2/J+3 :
  ces cellules sont marquées « hors horizon » (gris fonçé distinct)
  au lieu d'apparaître en rouge. Les modèles d'horizon &lt; 24 h sont
  explicitement étiquetés *non archivé* dans le tableau prévisions
  balises (filtre métier de `FetchBaliseForecastsJob`).

### Outillage
- **Commande artisan `readings:backfill-hourly [--days=7] [--balise=ID]`** :
  reconstruit `balise_readings_hourly` à partir de `balise_readings`
  brut, pour ne pas attendre que la fenêtre glissante de 3 h du job
  horaire ait progressivement rempli les 7 jours après un
  déploiement.

### Corrigé (suite — UKMO Global et modèles globaux similaires)
- **`OpenMeteoApi::parseResponse`** rendait `relative_humidity_2m`
  obligatoire pour conserver un créneau. Or **UKMO Global** (et
  potentiellement d'autres modèles globaux) ne servent pas cette
  variable sur Open-Meteo : conséquence, l'API renvoyait HTTP 200
  mais tous les créneaux étaient filtrés → 0 ligne en base, modèle
  apparaît comme « cassé ». Le parser n'exige plus désormais que
  `wind_direction_10m` + `wind_speed_10m` ; humidité, nuages,
  précipitations, plafond restent optionnels et le scoring tombe en
  repli proprement (`cloud_base = null` si humidité absente, etc.).
- Log informatif (`Log::info`) ajouté quand un parse retourne
  zéro créneau alors que la réponse contenait des données — facilite
  le diagnostic d'autres mismatches de variables à l'avenir.

### Débogage admin
- **Bouton « Tester » par modèle** dans le tableau de fraîcheur
  (`/admin/data-coverage`) : déclenche un fetch synchrone sur le 1er
  site actif et affiche le résultat brut (succès/échec, nb créneaux,
  durée, échantillon des 3 premiers créneaux). Indispensable pour
  diagnostiquer rapidement un modèle qui « ne renvoie rien » sans
  attendre la prochaine cadence.

### Routage par API (UKMO sur l'API publique)
- **Nouvelle entrée `weather_apis` `openmeteo_public`** pointant sur
  `https://api.open-meteo.com/v1` (auth_type none, quota déclaré
  10 000 req/jour). Le `WeatherApiRegistry` mappe ce code sur
  `OpenMeteoApi` (même implémentation, base URL différente).
- **UKMO Global routé sur cette API publique** via migration
  `2026_05_14_120000_add_openmeteo_public_api`. Motif : notre instance
  self-hosted ne sert pas `wind_speed_10m` ni `wind_direction_10m`
  pour ce modèle (interpolation à 10 m non effectuée), ce qui le rend
  inutilisable pour le scoring parapente. L'API publique, elle,
  fournit ces variables correctement. Volume attendu ~80 req/jour,
  largement sous le quota.
- `FetchBaliseForecastsJob` itère désormais sur **tous les modèles**
  bound à une API Open-Meteo (`openmeteo` OU `openmeteo_public`), en
  reconfigurant `setConfig()` par modèle. Avant : seuls les modèles
  bound à `openmeteo` étaient batchés, UKMO aurait été silencieusement
  écarté de l'archive balises.

### Diagnostic
- **Bouton « Brut »** sur le tableau de fraîcheur : appelle un nouvel
  endpoint `/admin/models/{id}/inspect` qui renvoie la réponse JSON
  d'Open-Meteo telle quelle, avec en plus un récap par variable
  `hourly` (présence : non_null/total + 3 premières valeurs). Utile
  pour diagnostiquer en un clic pourquoi un modèle renvoie 0 créneau
  après parsing (cas qui a permis d'identifier le mismatch UKMO
  ci-dessus).

### Robustesse
- **`DataCoverage` ne stocke plus d'Eloquent dans le cache Redis**
  (cause d'erreurs « incomplete object » après modification du
  schéma `weather_models` ou de la classe `WeatherModel`). Les
  objets cachés sont désormais de petits `stdClass` (id, name,
  code, active, max_horizon_h). Plus de 500 lors d'une lecture
  post-déploiement.
- **Invalidation automatique** du cache `DataCoverage` à chaque
  toggle/update de modèle, site ou balise via les contrôleurs
  admin correspondants.

---

## 2026-05-13 — Comparaison modèles ↔ balises, phase 1 (infra collecte)

Premier jalon de la comparaison entre les prévisions des modèles météo
et les observations réelles des balises. Phase 1 : infrastructure de
collecte uniquement, prépare le calcul de fiabilité dynamique des
modèles (cf. `FF_model_reliability.md` pour le cadrage complet).

Pas d'impact utilisateur final à ce stade : on accumule de la donnée
pendant ≥ 7 jours avant d'attaquer la phase 2 (calcul de fiabilité par
modèle / horizon, écran admin de surveillance).

### Ajouté
- **`FF_model_reliability.md`** à la racine : cadrage de la feature
  complète (4 phases, ~8 j d'effort). Capture le concept (pondération
  dynamique du consensus multi-modèles via auto-apprentissage léger
  sur fenêtre 7 j glissants), le modèle de données proposé (table
  `model_reliability`), les métriques d'erreur (% direction circulaire,
  vitesse avec plancher dynamique `max(obs, 5 km/h)`), la formule de
  `weight_factor` (ratio médian clampé `[0.25, 2.0]`), les écrans admin
  (settings en onglets + tableau pivot de surveillance), le mapping
  balise→site par IDW (phase 4), les alternatives écartées et les
  risques.
- **`AggregateBaliseReadingsHourlyJob`** : agrège `balise_readings` en
  buckets horaires alignés sur l'heure pile, pour permettre une
  comparaison directe avec `forecast_archive_balises`. Direction en
  moyenne circulaire via `SUM(SIN)` / `SUM(COS)` en SQL puis `atan2`
  en PHP. Fenêtre glissante 3 h pour capter les lectures tardives,
  idempotent via upsert. Scheduler : `hourlyAt(5)`, décalé pour passer
  après les polls PiouPiou / METAR à `:00`.
- **Modèle Eloquent `BaliseReadingHourly`** + relation `balise()`.

### Base de données
- **Nouvelle table `balise_readings_hourly`** : agrégat horaire des
  lectures balises. Colonnes `balise_id` (FK cascade), `hour_at`
  (datetime, heure pile), `wind_direction` (smallint nullable),
  `wind_speed_avg` / `wind_speed_max` / `temperature` (decimal
  nullable), `readings_count` (smallint). `UNIQUE(balise_id, hour_at)`
  + index `hour_at` pour la purge. Rétention 7 jours, alignée sur la
  fenêtre J-6 → J de la comparaison à venir.

### Modifié
- **`PurgeOldForecastsJob`** : nouvelle constante
  `HOURLY_RETENTION_DAYS = 7`, purge les lignes
  `balise_readings_hourly` plus anciennes. Laisse intacts les 30 j de
  `forecast_archive_balises` (utilisés par la voting logic existante).
- **`routes/console.php`** : entrée scheduler
  `aggregate-balise-readings-hourly`, cron à `:05` toutes les heures,
  `withoutOverlapping()`.

---


Implémentation complète du scoring perso en trois lots (auth front, backend
+ cache + UI gestion, intégration carte). Cf. `FF_personnal_scoring.md`
pour le cadrage. Bloquant : authentification front livrée dans la foulée.

### Ajouté
- **Authentification front** (compte « user ») : pages publiques
  `/inscription` (`register`), `/connexion` (`login`), déconnexion
  `/deconnexion`. Form requests dédiés (8 caractères min, lettres +
  chiffres, throttle 5/min). Profil utilisateur `/profil` avec sections
  Identité (nom, pseudo unique, email, bio), changement de mot de passe
  (error bag `updatePassword`), suppression de compte (error bag
  `deleteAccount`). Approche maison sans Laravel Breeze, cohérente avec
  l'admin existant.
- **Écran complet `/profil/scorings`** (`user.scorings`) : gestion des
  scorings perso sous forme de grille de cartes responsive (1/2/3
  colonnes), une carte par scoring avec rose des vents SVG (arc favorable
  colorisé), jauge de plage de vent avec marker idéal, détails rafales /
  plafond / couverture nuages, badge actif/inactif visible, horodatage
  d'activation. Filtres dans le volet gauche (statut, recherche par nom
  de site, tri récent / nom / ancien). Compteur X/10 actifs · Y/50 stockés.
  Modal de création/édition avec validation cohérence min ≤ idéal ≤ max
  et orange < red.
- **Section résumée scorings perso** sur `/profil` (2 tuiles compteur +
  bouton « Gérer mes scorings »).
- **API REST `/api/users/me/scorings/*`** (auth:web via cookie de
  session) : `GET` (liste avec site joint), `POST` (création, contraint
  aux sites `active = true`, cap soft à 50 stockés), `PATCH`, `DELETE`,
  `POST {id}/activate` (rotation LRU dans une transaction si ≥10 actifs),
  `POST {id}/deactivate`.
- **Carte météo user-aware** :
  - Badge sur le marker (bleu plein = scoring perso actif, contour gris =
    enregistré mais inactif).
  - Filtre « Mes sites uniquement » dans le volet gauche (auth seulement),
    bandeau « Scoring perso · {pseudo} » dans le volet droit sur un site
    couvert par un scoring perso actif.
  - Onglet « Détail scoring » : cellules **split diagonales** (triangle
    haut-gauche = standard, bas-droite = perso) sur les 5 paramètres ET
    la ligne « Statut global ». Diagonale blanche élargie à 8 % pour
    rester visible même quand les deux moitiés concluent à la même
    couleur — signal qu'un scoring perso est en jeu. Tooltip JS dédié
    avec les deux libellés.
  - Couleur du halo (statut du jour) recalculée avec les conditions
    perso (réutilise les consensus déjà persistés sur `site_scores`,
    pas de fetch supplémentaire).

### Modifié
- **`/api/sites`** devient user-aware : nouveau champ `user_scoring`
  (`'active' | 'inactive' | null`) par site.
- **`/api/sites/{id}/scores`** : nouveau champ `scoring_source`
  (`'user' | 'global'`) ; quand l'user a un scoring actif sur le site,
  `status` et `detail.*.color` reflètent le scoring perso ; on conserve
  systématiquement `status_global` + `detail.*.color_global` à côté pour
  alimenter la comparaison côté carte.
- **`ScoringService`** : extraction d'une interface
  `App\Contracts\FlyingConditions` partagée par `SiteCondition` et
  `UserSiteCondition` via un trait `Concerns\HasFlyingConditions` (zéro
  duplication). Nouvelle méthode publique `rescore(FlyingConditions,
  SiteScore)` qui rejoue les règles éliminatoires + couleurs sans
  recalculer le consensus.
- **`FetchSiteForecastsJob`** : à la fin de chaque salve de fetch + scoring,
  invalide les caches `user_scoring:*` du site
  (`UserScoringService::invalidateSite`).
- **`Settings::flush()`** : invalide en plus tous les caches
  `user_scoring:*` (résolution paresseuse via `app()` pour éviter la
  dépendance circulaire).
- **`bootstrap/app.php`** : ajout de `EncryptCookies`, `StartSession` et
  `PreventRequestForgery` au groupe middleware `api` pour permettre l'auth
  via cookie de session côté API (pas de Sanctum). Redirection des
  invités passée de `admin.login` à `login`.
- **Navbar** : entrée « Mon profil » + « Mes scorings perso » dans le menu
  utilisateur (dropdown en haut à droite). Le formulaire de connexion en
  popover poste désormais vers `login` (front), plus vers `admin.login`.

### Base de données
- **Migration `users`** : ajout de `pseudo` (string unique nullable) et
  `bio` (text nullable).
- **Nouvelle table `user_site_conditions`** : miroir de `site_conditions`
  (`wind_dir_min/max`, `wind_speed_min/max/ideal`, override rafales
  orange/red, `cloud_base_min_m`, `cloud_cover_low_max`, `notes`) +
  `user_id` / `site_id` (cascadeOnDelete) + `is_active` (bool) +
  `activated_at` (datetime nullable). `UNIQUE(user_id, site_id)`, index
  composite `(user_id, is_active, activated_at)` pour la requête LRU.
- **Migrations patchées pour SQLite** : `change_balises_source_to_string`
  et les deux `make_*_nullable_in_sites` deviennent no-op sous SQLite (le
  `ALTER TABLE ... MODIFY` n'y est pas supporté). Permet aux tests
  features de tourner.

### Architecture
- **Nouveau service `App\Services\Weather\UserScoringService`** : calcule
  les scores perso à la volée à partir des consensus déjà persistés sur
  `site_scores` (pas de duplication en base), cache Redis 1 h avec clé
  `user_scoring:{user_id}:site:{site_id}`. Activation transactionnelle
  avec rotation LRU des 10 actifs max (constante
  `UserSiteCondition::MAX_ACTIVE`). Cap soft à 50 stockés
  (`MAX_STORED`).

### Tests
- 26 nouveaux tests features et unitaires : authentification (register,
  login, profil), API user scorings (CRUD, autorisations, cohérences,
  LRU), service user scoring (rescore, cache, invalidations), API sites
  user-aware (`user_scoring`, `scoring_source`, `color_global`,
  `status_global`), page `/profil/scorings`. Suite complète à 54/54.
- **`tests/TestCase`** : `withoutVite()` global (les vues Blade
  rendraient une 500 sans `public/build/manifest.json`).

### Déploiement
- `php artisan migrate` pour appliquer les deux nouvelles migrations.
- `php artisan optimize:clear` après déploiement (les vues map / profil
  sont recompilées).

---

## 2026-05-13 — Écran admin « Paramètres généraux » + seuils paramétrables

### Ajouté
- **Page admin `/admin/settings`** (lien « Paramètres » dans la sidebar
  admin, icône ⚙) qui regroupe les seuils globaux du scoring : trois
  sections (Précipitations / Rafales / Viabilité d'une journée) avec une
  description par champ. Enregistrement en un clic via `PATCH
  /admin/settings`.
- **Table `settings`** (clé unique + valeur JSON + label / description),
  alimentée par `SettingsSeeder` à partir du catalogue `Settings::DEFAULTS`.
- **Service `App\Services\Settings`** : accès cache (Redis, 1 h, invalidé
  à chaque écriture) avec catalogue des défauts comme fallback. API :
  `get($key, $default)`, `all()`, `set($key, $value)`, `setMany([...])`,
  `flush()`. Lié en singleton dans `AppServiceProvider`.
- **Override des rafales par site** : nouveaux champs `wind_gust_orange_kmh`
  et `wind_gust_red_kmh` (nullable) sur `site_conditions`, exposés dans la
  fiche d'édition du site (admin). Laisser vide pour utiliser la valeur
  globale ; remplir pour surcharger.

### Modifié
- **Précipitations · règle de scoring** : la voting logic n'utilise plus
  qu'un critère sur le **consensus** (vs. l'ancienne règle « ≥1 modèle
  prévoit de la pluie »). Deux seuils globaux paramétrables : `scoring
  .precip_orange_mmh` (au-delà → orange) et `scoring.precip_red_mmh`
  (au-delà → rouge). Défauts : `0.0` et `0.1` mm/h.
- **Rafales · règle de scoring** : `scoring.gust_orange_kmh` et
  `scoring.gust_red_kmh` deviennent éditables (défauts 25 / 35 km/h),
  surchargeables par site (cf. ci-dessus).
- **Viabilité du jour** : les paramètres `viability.peak_hour`,
  `viability.sigma`, `viability.val_green`, `viability.val_orange`,
  `viability.run_base`, `viability.run_step`, `viability.green_threshold`,
  `viability.orange_threshold` deviennent éditables (anciennement des
  constantes dans `SiteController`).
- **ScoringService** : nouvelle dépendance `Settings` (injectée par
  DI). Constantes `GUST_ORANGE_KMH` / `GUST_RED_KMH` retirées.

### Supprimé
- **`site_conditions.precip_max`** (colonne + UI + helper
  `isPrecipitationAcceptable`) : remplacée par les seuils globaux. La
  migration de retour (down) recrée la colonne avec la valeur par défaut
  `0.0` pour permettre un éventuel rollback.

### Base de données
- **`+ settings`** (`key` unique, `value` JSON, `label`, `description`).
- **`site_conditions`** : `- precip_max`, `+ wind_gust_orange_kmh`,
  `+ wind_gust_red_kmh` (nullable, decimal 5,1).

### Déploiement
- Après `php artisan migrate --force`, exécuter
  `php artisan db:seed --class=SettingsSeeder --force` pour poser les
  valeurs par défaut. Idempotent.

---

## 2026-05-13 — Onglet « Détail du scoring » (voting logic)

### Ajouté
- **Nouvel onglet « Détail scoring · 5 jours »** dans le volet droit des
  sites, intercalé entre « Synthèse » et « Modèles météo · {jour} ». Affiche
  cinq tableaux empilés (J → J+4), un par jour. En lignes : les cinq
  paramètres de la voting logic — *Direction*, *Vitesse*, *Rafales*,
  *Précipitations*, *Plafond* — plus une ligne *Statut global* en bas. En
  colonnes : les heures de la fenêtre solaire. Chaque cellule est une
  pastille colorée (vert *OK* / orange *Prudence* / rouge *Éliminatoire* /
  gris *N/A*) avec tooltip natif (consensus + convergence).
- **`ScoringService::computeParamColors()`** (méthode publique) : isole la
  coloration par paramètre, alignée sur `applyEliminatoryRules()`. Direction
  et vitesse moyenne en binaire vert/rouge (dans la plage / hors plage),
  rafales selon les seuils 25/35 km/h, précipitations vert/orange/rouge
  selon consensus et présence d'au moins un modèle annonçant de la pluie,
  plafond *informatif* à partir de `site_conditions.cloud_base_min_m` (rouge
  en-dessous, orange dans une marge de 100 m, vert au-dessus).
- **Commande artisan `scores:recompute-detail-colors`** : backfill des
  `site_scores` déjà en base (lit les consensus et `precip.values` persistés
  dans `detail`, applique `computeParamColors`, met à jour `detail.*.color`).
  Options `--site=` et `--chunk=`, idempotente, sans refetch météo.

### Modifié
- **`site_scores.detail`** : chaque sous-bloc (`wind_dir`, `wind_speed`,
  `wind_gust`, `precip`, `cloud_base`) reçoit un champ `color` ∈ {green,
  orange, red, unknown}.
- **`GET /api/sites/{id}/scores`** expose désormais un champ `detail`
  allégé par créneau (consensus + convergence + color, sans les `values`
  brutes pour limiter le payload), ainsi que `wind_gust` et `cloud_base`.

---

## 2026-05-12 — Refonte de l'écran carte météo

### Ajouté
- **Volet gauche en onglets** « Paramètres » / « Légende », repliable en barre
  étroite (☰). *Paramètres* : calque balises, affichage des sites par statut
  (favorables / incertains / défavorables), sélecteur de fond de carte
  (dropdown). *Légende* : pictogrammes des sites (halo de statut) et des
  balises (couleur = force du vent, fond = fraîcheur du relevé).
- **Volet droit « site »** : titre sur une ligne (nom · niveau · orientation
  favorable · ☀ lever → coucher) + 3 onglets — « Synthèse · {jour} » (graphe
  nuages + vent min/moy/max + flèches de direction, certitude de la prévision,
  bargraph du plafond de vol min/consensus/max), « Modèles météo · {jour} » et
  « Modèles · 5 jours » (6 graphes multi-modèles + consensus).
- **Volet droit « balise »** : titre sur une ligne (nom · réseau · maj il y a …)
  + onglet « Relevés météo » — dernier relevé synthétique (direction, vitesse,
  rafales/min, temp/hum), rose des vents heure par heure, graphe vitesse du jour.
- **Estimation du plafond de vol** (base des cumulus), heure par heure, en
  altitude absolue (règle d'Espy : `elevation_modèle + 125 × (T₂ₘ_max_jour −
  Td₂ₘ)`), avec consensus multi-modèles (même voting logic) — affiché en
  bargraph (onglet Synthèse) et en courbes par modèle (onglets Modèles).
- Sélecteur de jour flottant dans le coin haut-droite de la carte ; barre de
  menu globale (`partials.app-shell-navbar`) sur l'écran carte ; clic sur un
  marqueur → ouverture du volet droit (qui occupe la moitié de l'écran).

### Modifié
- **Scoring horaire** : prise en compte des **rafales** (consensus de
  `wind_speed_max`) — > 35 km/h → rouge (éliminatoire), 25-35 → orange. Avant,
  seules la direction et la vitesse moyenne entraient dans le statut.
- **Couleur des marqueurs / sélecteur de jour** : basée sur une **qualité de
  journée** (viabilité = continuité des créneaux volables × poids des créneaux
  de milieu de journée, calculée à la lecture) au lieu de « une heure verte →
  marqueur vert ».
- **Plafond** : passage de la formule de Henning (point de rosée dérivé de
  l'humidité, valeur AGL) à Espy avec `dew_point_2m` du modèle,
  `temperature_2m_max` comme température de déclenchement, et l'élévation du
  point de grille comme référence → valeur en altitude absolue (ASL).
- Suppression de l'ancienne barre d'outils de la carte (le sélecteur de jour
  passe en flottant) ; partials obsolètes supprimés (`map/_partials/html/
  {popup-chart,balise-popup,panel}.blade.php`, `styles/popup`, `html/
  {toolbar,legend}`).

### Corrigé
- **Convention de cap de vent des balises** : OpenWindMap (PiouPiou) renvoie
  `wind_heading` en convention TO (direction *vers laquelle* souffle le vent) ;
  `PiouPiouProvider` la normalise désormais en convention FROM (météo standard,
  contrat de `BaliseProviderInterface`), et `baliseIconUrl` transmet la valeur
  telle quelle à SpotAir. Corrige l'incohérence rose des vents ↔ icône carte.
- Unité du plafond dans les tooltips des graphes (« m » au lieu de « km/h »).

### Base de données
- `site_scores.cloud_base_consensus` (plafond de vol consensus, m ASL,
  nullable).

### API / Météo
- `OpenMeteoApi` requête en plus `dew_point_2m` (hourly) et `temperature_2m_max`
  (daily).
- `/api/sites/{id}/scores` expose `day_quality` (viabilité + statut par jour) ;
  `/api/sites/{id}/chart` expose `cloud_base`, `cloud_base_min/max` par heure ;
  `/api/sites/{id}/multimodel` expose `cloud_base` par modèle + dans le consensus.

### Déploiement
- `php artisan migrate --force` (colonne `cloud_base_consensus`).
- Re-fetch + re-scoring des sites actifs (formule plafond, normalisation cap
  balises, seuil rafales) : `Site::active()->get()->each(fn($s) =>
  FetchSiteForecastsJob::dispatchSync($s->id))`.
- `php artisan optimize:clear` (vues Blade).
- Le scheduler relance le fetch horaire automatiquement ensuite.

---

## 2026-05-12 — Interface globale, articles & modules

### Ajouté
- **Shell d'interface global** (`<x-app-shell>`) commun à tous les écrans :
  barre de menu supérieure (liens des modules selon les droits + formulaire de
  connexion en menu déroulant), panneau latéral gauche « détail », panneau
  latéral droit « aide / légende / actions ». Navbar en partial
  `resources/views/partials/app-shell-navbar.blade.php`.
- **Page d'accueil** (`/`) : affiche les articles publiés empilés
  verticalement, du plus récent au plus ancien.
- **Module Articles / Changelog** dans l'admin (`/admin/articles`) : éditeur
  WYSIWYG TinyMCE (chargé via CDN), upload d'images sur le disque `public`
  (route `POST /admin/articles/upload-image`), publication/dépublication.
- **Gestion des modules** dans l'admin (`/admin/modules`) : par module —
  actif (oui/non), niveau de droit (`guest` / `user` / `admin`), compte
  utilisateur obligatoire (oui/non). L'affichage dans la barre de menu en
  découle (`Module::isVisibleFor()`).

### Modifié
- Le BackOffice (`layouts/admin.blade.php`) repose désormais sur `<x-app-shell>` ;
  la navigation des sections admin est passée dans le panneau latéral gauche.
- Route par défaut `/` → page d'accueil (était la carte). La carte est
  désormais sur `/carte` (route nommée `map` inchangée).
- `App\Support\Navigation` lit la liste des modules depuis la base de données
  (fallback : menu vide si la table n'est pas encore migrée).

### Base de données
- Nouvelle table `modules` (clé, label, icône, route, `is_active`,
  `access_level`, `requires_registration`, `sort_order`) + `ModuleSeeder`.
- Nouvelle table `articles` (titre, corps HTML, `author_id`, `is_published`,
  `published_at`).

### Supprimé
- `config/modules.php` (remplacé par la table `modules`).

### Déploiement
- Mis en production le 2026-05-12 (branche `V2`).
- Penser à `php artisan migrate`, `php artisan db:seed --class=ModuleSeeder`
  et `php artisan storage:link` (images des articles).
- Procédure de déploiement de `CLAUDE.md` complétée : recréer un conteneur PHP
  impose de **redémarrer Nginx** (IP figée → 502), re-`chown` `storage`/`bootstrap/cache`,
  `optimize:clear` + `optimize`, puis `restart parapente-php` & `restart parapente-nginx`.

---

## 2026-05 — Météo auto-hébergée (Open-Meteo)

### Modifié
- Bascule vers un **serveur Open-Meteo dédié** (image `open-meteo/open-meteo`)
  agrégeant ~13 modèles publics. Suppression des fetch directs sur Météo-France
  DPS, DWD OpenData, ECMWF Open Data, MET Norway.
- URL configurable via `OPEN_METEO_BASE_URL` ; appel batch multi-coordonnées
  pour les balises (40 points / chunk), timeout 60 s.

---

## 2026-05 — BackOffice

### Ajouté
- BackOffice `/admin` : authentification (rôle admin), gestion des sites,
  des balises, des modèles météo, des APIs météo, synchronisation des données,
  logs / monitoring, gestion des utilisateurs.

---

## 2026-05 — Module 1 : Carte météo

### Ajouté
- Vue carte (`/carte`) : marqueurs colorés par statut de vol, popup graphique
  (tuiles nuageuses, bargraphes vent, flèches direction), side panel timeline.
- API REST : `/api/sites`, `/api/sites/{id}/scores`, `/api/sites/{id}/chart`.
- Services météo : `OpenMeteoApi`, `ForecastFetcher`, `ScoringService`
  (voting logic, moyenne circulaire des directions, règles éliminatoires).
- Jobs : `FetchForecastsJob`, `FetchSiteForecastsJob`.
- Modèle de données : `sites`, `site_conditions`, `weather_models`,
  `forecasts`, `site_scores`, `balises`, `balise_readings`.
