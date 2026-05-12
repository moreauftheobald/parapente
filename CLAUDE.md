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
| Frontend        | CSS inline + Alpine.js              |
| Carte           | Leaflet.js                          |
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
│   │   │   │   └── SiteController.php   ← API JSON sites/scores/chart
│   │   │   ├── Admin/                   ← BackOffice (sites, balises, modèles,
│   │   │   │   │                            APIs, users, sync, logs,
│   │   │   │   │                            ArticleController, ModuleController…)
│   │   │   ├── HomeController.php        ← Page d'accueil (articles)
│   │   │   └── MapController.php         ← Vue carte
│   │   └── Livewire/
│   ├── Models/
│   │   ├── Site.php, SiteCondition.php, WeatherModel.php, Forecast.php,
│   │   ├── SiteScore.php, Balise.php, BaliseReading.php
│   │   ├── Module.php                    ← Modules du menu (table `modules`)
│   │   └── Article.php                   ← Articles / changelog accueil
│   ├── Support/
│   │   └── Navigation.php                ← Liste des modules visibles (navbar)
│   ├── Services/
│   │   └── Weather/ …                    ← OpenMeteoApi, ForecastFetcher, ScoringService
│   └── Jobs/
│       ├── FetchForecastsJob.php         ← Orchestre par site
│       └── FetchSiteForecastsJob.php     ← Fetch + score 1 site
├── database/
│   ├── migrations/
│   └── seeders/
│       ├── ModuleSeeder.php              ← Modules du menu
│       ├── WeatherModelSeeder.php, SiteSeeder.php, GrandEstSitesSeeder.php
├── resources/views/
│   ├── components/
│   │   └── app-shell.blade.php           ← Shell global <x-app-shell> (commun à tous les écrans)
│   ├── partials/
│   │   └── app-shell-navbar.blade.php    ← Barre de menu supérieure
│   ├── layouts/
│   │   ├── app.blade.php                 ← (legacy) layout de la vue carte
│   │   └── admin.blade.php               ← BackOffice (utilise <x-app-shell>)
│   ├── home.blade.php                    ← Page d'accueil (/)
│   ├── admin/                            ← Vues BackOffice (articles/, modules/, sites/, …)
│   └── map/index.blade.php               ← Vue carte (/carte)
└── routes/
    ├── web.php
    └── api.php                           ← 3 endpoints REST
```

---

## Base de données

### Migrations (dans l'ordre)

| Table             | Description                                              |
|-------------------|----------------------------------------------------------|
| `users`           | Utilisateurs + rôle admin/user                          |
| `sites`           | Sites de vol (nom, coords, altitude, niveau, région)    |
| `site_conditions` | Conditions idéales par site (vent dir/vitesse, nuages)  |
| `weather_models`  | 10 modèles météo avec poids short/medium                |
| `weather_apis`    | Sources API météo (Open-Meteo…)                         |
| `forecasts`       | Prévisions brutes Open-Meteo (nullable)                 |
| `site_scores`     | Scores calculés par site/heure (green/orange/red)       |
| `balises`         | Balises PiouPiou/FFVL                                   |
| `balise_readings` | Lectures temps réel balises                             |
| `modules`         | Modules du menu (key, label, icône, route, `is_active`, `access_level` guest\|user\|admin, `requires_registration`, `sort_order`) |
| `articles`        | Articles / changelog accueil (titre, body HTML, `author_id`, `is_published`, `published_at`) |

### Colonnes clés `site_conditions`
```
wind_dir_min, wind_dir_max          ← Axe favorable (ex: 75-105 pour Volmerange EST)
wind_speed_min, wind_speed_max      ← Plage de vent acceptable (km/h)
wind_speed_ideal                    ← Vent idéal
precip_max                          ← Précipitations max tolérées
cloud_base_min_m                    ← Plafond nuageux minimum (m)
cloud_cover_low_max                 ← Couverture nuageuse basse max
```

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
GET /api/sites              → Liste tous les sites actifs (métadonnées)
GET /api/sites/{id}/scores  → Scores filtrés fenêtre solaire + sun_windows
GET /api/sites/{id}/chart   → Données horaires pour popup graphique
                              (vent min/moy/max, nuages H/M/B, direction)
```

### Fenêtre de vol solaire (appliquée dans `SiteController`)
- Début : lever du soleil − 30min → **floor** à l'heure (ex: 06:40 → 6h)
- Fin   : coucher du soleil + 30min → **ceil** à l'heure (ex: 18:50 → 19h)
- Calculé via `date_sunrise` / `date_sunset` PHP natif, timezone Europe/Paris

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
- **Page d'accueil** (`/`, `HomeController`) : affiche les articles publiés
  (`Article::published()`), du plus récent au plus ancien.
- **BackOffice** (`layouts/admin.blade.php`) : repose sur `<x-app-shell>` ; la
  navigation des sections admin est dans le panneau gauche, ouvert par défaut.
  Une page admin peut alimenter le panneau droit via `@section('help')`.
- **Modules** : éditables dans `/admin/modules` (actif, niveau de droit
  `guest|user|admin`, compte obligatoire). Visibilité menu = `Module::isVisibleFor()`.
  Un module sans `route_name` est affiché grisé (« non implémenté »).
- **Articles** : éditeur WYSIWYG **TinyMCE** (CDN), upload d'images via
  `POST /admin/articles/upload-image` → disque `public` (⇒ `php artisan storage:link`).
- La **vue carte** (`/carte`) garde encore son ancien layout `layouts/app.blade.php`
  (migration vers `<x-app-shell>` à faire).

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

13 modèles servis : `meteofrance_arome_france_hd`, `meteofrance_arome_france_hd_15m`,
`meteofrance_arpege_europe`, `dwd_icon_eu`, `dwd_icon_d2`, `dwd_icon`,
`ncep_gfs013`, `ecmwf_ifs025`, `ecmwf_aifs025_single`,
`ukmo_global_deterministic_10km`, `bom_access_global`, `cma_grapes_global`,
`jma_gsm`. (Les 3 derniers inactifs par défaut, hémisphère sud / Asie.)

**`OpenMeteoApi`** (`app/Services/Weather/Apis/`) :
- `fetchForSiteAndModel()` : 1 appel par (site, modèle)
- `fetchBatchForBalises()` : appel batch multi-coordonnées (40 points/chunk)
- Pas de pause entre appels (serveur dédié, pas de rate limit)
- Plafond (base des cumulus) — règle d'Espy avec température de déclenchement,
  en **altitude absolue (ASL)** : `cloud_base_m = elevation_modèle + 125 × (T₂ₘ_max_jour − Td₂ₘ)`
  (`temperature_2m_max` journalière, `dew_point_2m` ; `elevation` = point de grille
  du modèle, renvoyé par Open-Meteo ; fallbacks : T horaire / Td approx. depuis l'humidité).
  Le consensus multi-modèles est stocké dans `site_scores.cloud_base_consensus`
  (même voting logic que le reste).
- Format datetime `Y-m-d H:i:s` pour MariaDB (pas ISO avec `T`)

**`ScoringService`** :
- Voting logic complète
- Moyenne circulaire pour direction vent (évite le problème 359°/1°)
- Moyenne inverse carré pour isoler les outliers
- Statut horaire (`site_scores.status`) — règles éliminatoires :
  - rouge : précip consensus > `precip_max` ; rafale consensus > 35 km/h ;
    direction ou vitesse moyenne hors plage du site
  - orange : ≥1 modèle annonce de la pluie ; rafale consensus 25-35 km/h
  - (la rafale = consensus de `wind_speed_max`, idem voting logic que le reste)
- `upsert()` en masse

> **Qualité d'une journée** — calculée à la lecture dans `SiteController::computeDayQuality`
> (pas en base) : viabilité 0-100 = Σ(poids horaire × valeur du statut ×
> facteur de continuité) / Σ(poids horaire) sur la fenêtre solaire. Le poids
> horaire est une cloche centrée ~13h30 (créneaux du milieu de journée >
> très tôt/tard) ; le facteur de continuité pénalise les créneaux volables
> isolés (1 h → 40 %, ≥3 h consécutives → 100 %). Statut du jour dérivé :
> ≥35 vert, ≥12 orange, sinon rouge. Exposé dans `/api/sites/{id}/scores`
> (`day_quality`) et utilisé côté carte pour la couleur des marqueurs.

### Convention direction vent (IMPORTANT)
`wind_dir_consensus` dans `site_scores` = direction **FROM** (convention météo standard).
- Est = 90°, Ouest = 270°, Nord = 0°/360°
- Même convention dans `site_conditions.wind_dir_min/max`
- Dans `buildChartSVG` : flèche = `rotate(wind_dir + 180)` pour montrer où le vent VA

### Jobs
- `FetchForecastsJob` : dispatche 1 job par site sur queue `meteo`
- `FetchSiteForecastsJob` : fetch tous modèles → upsert par lots 500 → calcul scores. Timeout 300s.

---

## Modules futurs

### Module 2 — Journal de vol
Carnet de vol numérique par utilisateur. Nécessite authentification.

### Module 3 — Comparatif voiles & sellettes
Base de données équipements avec comparaison et notation communautaire.

### Balises PiouPiou/FFVL
Intégration API temps réel pour validation des prévisions.

---

## À faire plus tard (backlog)

- **Seuils de rafales par site** : pouvoir surcharger les valeurs limites de
  rafales (orange/rouge) sur chaque fiche de site (`site_conditions`). Si la
  fiche du site n'a pas de valeur → on prend les valeurs générales ; sinon →
  celles du site.
- **Écran « paramètres généraux » dans l'admin** : modifier les réglages
  globaux (forme de la cloche horaire de viabilité — pic / sigma / seuils
  vert/orange ; seuils génériques de rafales ; etc.) plutôt que de les avoir
  en constantes dans le code.
- **Onglet « détail du scoring »** dans le volet droit des sites : tableau
  heure par heure des paramètres ayant servi au scoring (consensus & valeurs
  par modèle, convergences, règle déclenchée, etc.).
- **« Pseudo-wiki » technique** : page (publique ou admin) documentant en
  détail le fonctionnement de l'appli — sources de données, modèles météo,
  mode de calcul du scoring, voting logic, plafond/Espy, fenêtre solaire,
  rétroaction/validation par balises, etc.
- **Fonction « I am here »** sur la carte : poser un marqueur (position
  saisie ou géoloc) et filtrer les sites situés à moins de X minutes de route
  de ce point (calcul d'isochrone / temps de trajet).

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
- Les dropdowns au-dessus de la carte : `position:fixed` + `getBoundingClientRect()`
- Le SVG du popup est généré dynamiquement par `buildChartSVG(dayData, siteInfo)` en JS pur

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