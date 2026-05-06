# CLAUDE.md — Instructions pour Claude Code

## Présentation du projet

**ParapenteFR** est une plateforme web dédiée aux pilotes de parapente du Grand Est,
construite en Laravel. Le projet est modulaire : chaque grande fonctionnalité est un module indépendant.

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
│   │   │   └── MapController.php        ← Vue carte
│   │   └── Livewire/
│   ├── Models/
│   │   ├── Site.php
│   │   ├── SiteCondition.php
│   │   ├── WeatherModel.php
│   │   ├── Forecast.php
│   │   ├── SiteScore.php
│   │   ├── Balise.php
│   │   └── BaliseReading.php
│   ├── Services/
│   │   └── Weather/
│   │       ├── OpenMeteoService.php     ← Fetch API Open-Meteo
│   │       └── ScoringService.php       ← Voting logic + scores
│   └── Jobs/
│       ├── FetchForecastsJob.php        ← Orchestre par site
│       └── FetchSiteForecastsJob.php    ← Fetch + score 1 site
├── database/
│   ├── migrations/                      ← 8 migrations (voir ci-dessous)
│   └── seeders/
│       ├── WeatherModelSeeder.php       ← 10 modèles Open-Meteo
│       ├── SiteSeeder.php               ← Volmerange EST
│       └── GrandEstSitesSeeder.php      ← 13 sites Grand Est
├── resources/views/
│   ├── layouts/app.blade.php
│   └── map/
│       └── index.blade.php              ← Vue principale carte (541 lignes)
└── routes/
    ├── web.php
    └── api.php                          ← 3 endpoints REST
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
| `forecasts`       | Prévisions brutes Open-Meteo (nullable)                 |
| `site_scores`     | Scores calculés par site/heure (green/orange/red)       |
| `balises`         | Balises PiouPiou/FFVL                                   |
| `balise_readings` | Lectures temps réel balises                             |

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
wind_speed_consensus    ← Vitesse vent consensus
precip_consensus        ← Précipitations consensus
models_count            ← Nombre de modèles ayant des données
models_converging       ← Nombre de modèles convergents
detail                  ← JSON détail du scoring
```

### Sites en base (14 total)
Volmerange EST (49.4468, 6.0999, 420m, vent E 75°-105°) + 13 sites Grand Est :
Jouy-sous-les-Côtes, Beauring, Losheim, Houéville, Létanne, Lion-devant-Dun,
Coo Ouest, Algrange, Fumay, Coo Sud, Revin Fallières, Klusserath, Markstein.

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

**`OpenMeteoService`** :
- `fetchForSiteAndModel()` / `fetchAllModelsForSite()` (pause 200ms entre appels)
- Formule plafond Henning : `(T - Td) / 8 × 1000`
- Format datetime `Y-m-d H:i:s` pour MariaDB (pas ISO avec `T`)

**`ScoringService`** :
- Voting logic complète
- Moyenne circulaire pour direction vent (évite le problème 359°/1°)
- Moyenne inverse carré pour isoler les outliers
- Règles éliminatoires : précip > 0 → rouge ; ≥1 modèle avec pluie → orange
- `upsert()` en masse

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
8. **Authentification** non encore implémentée — Laravel Breeze prévu (admin/user).