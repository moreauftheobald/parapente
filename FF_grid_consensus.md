# FF — Consensus multi-modèles sur grille (overlay carte + moteur de scoring)

> **Statut** : Cadrage. À développer après stabilisation des modules
> en cours (fiabilité phase 2.5, scoring perso).
> **Priorité** : **Stratégique** — initialement cadré comme feature
> visuelle, le sujet est en réalité **le levier de scaling du moteur
> de scoring** vers 1 000 sites France et 10 000 sites Europe (cf. § 1.5).
> **Date de rédaction** : 2026-05-19 (révisé 2026-05-20).

Ce document consigne la discussion de cadrage. Il sert de point de
reprise quand on décidera d'implémenter le sujet.

---

## 1. Concept

Afficher sur la carte un **overlay météo global** type Windy.com mais
spécifique parapente :

- **Couleur transparente** sur tout le territoire (variable au choix :
  vitesse de vent, plafond de vol estimé, précipitations, voire score
  de volabilité parapente).
- **Flèches de direction de vent** réparties sur la grille, orientées
  selon la direction (FROM → flèche tournée +180°, même convention que
  les popups existants).
- Données = **consensus multi-modèles** calculé sur chaque barycentre
  de la grille, en réutilisant la voting logic de `ScoringService` (ou
  sa version améliorée — cf. `FF_model_reliability.md`).
- **Slider temporel** (horizon 0 h → 120 h), permettant de visualiser
  l'évolution du temps sur les 5 prochains jours.

Différentiation vs Windy / Meteoblue :
- **Consensus** explicite (Windy n'affiche qu'un modèle à la fois).
- **Indicateur de confiance** : intensité de couleur modulée par
  `models_converging`.
- **Plafond de vol estimé** (règle d'Espy) comme variable affichable.
- **Score de volabilité parapente** (statut green/orange/red) applicable
  cellule par cellule avec un « site générique » → carte de la
  volabilité à l'échelle du territoire. Aucun concurrent ne fait ça.

---

## 1.5 Implication stratégique : levier de scaling du moteur

Initialement cadré comme une **feature visuelle**, ce module est en
réalité **l'architecture qui débloque le scaling du moteur de
scoring** vers les cibles long-terme du projet.

### Profil du goulot à venir

| Échelle           | Sites | Scoring PHP/cycle | Fetch Open-Meteo/h |
|-------------------|-------|-------------------|---------------------|
| Aujourd'hui       | ~200  | 20-100 s          | ~2 600 calls        |
| Cible France      | 1 000 | **2-8 min**       | ~13 000 calls       |
| Cible Europe      | 10 000| **17 min - 1h20** | **130 000 calls**   |

L'architecture actuelle (fetch 13 modèles par site, scoring séquentiel
en PHP) ne tient pas au-delà de ~1 000 sites. À 10 000, on dépasse le
cycle horaire — le scoring n'aurait pas terminé avant le suivant.

### Le retournement architectural

Une fois le consensus calculé **sur une grille**, scorer un site
devient un **lookup dans la grille** (lecture par coordonnées) +
application des règles `site_conditions`. **Le coût de scoring
devient quasi-constant** indépendamment du nombre de sites.

| Étape                 | Architecture actuelle      | Architecture grille            |
|-----------------------|----------------------------|--------------------------------|
| Fetch                 | 13 × N sites               | 13 fetches grille (1 par modèle) |
| Voting logic          | N × calcul PHP             | 1 × calcul numpy vectorisé     |
| Status par site       | Inline avec scoring        | Lookup grille + règles site    |
| **Total à 10 k sites** | **> 1 h, ingérable**       | **~3-5 min**                   |

### Conséquences

- **Le sidecar Python n'est plus une brique optionnelle**, c'est le
  futur moteur de calcul météo de l'app.
- **Le `ScoringService` PHP est conservé tel quel** — sa source de
  données change, sa logique métier (sécurité CCR, règles
  éliminatoires, etc.) reste exactement la même.
- **La carte visuelle (overlay couleur + flèches) est un sous-produit
  gratuit** du pipeline de scoring.
- **L'ajout massif de sites devient indolore** (import FFVL massif,
  expansion Europe, etc.).
- **Préparation naturelle du module « I am here »**
  (`FF_Iam_Here.md`) : filtrer 10 000 sites par temps de route
  devient trivial — les statuses sont déjà calculés et indexés.

### L'intégration côté Laravel : zero refactor

Le sidecar Python expose une **API Open-Meteo-compatible** (cf. § 3.2).
Côté Laravel, le consensus apparaît comme **un modèle ordinaire**
(`qui_vole_consensus`) dans la table `weather_models`. Le
`OpenMeteoApi` existant route vers le sidecar via un simple mapping
de configuration. **Aucune modification de `ScoringService`, jobs ou
schéma.** Le consensus s'ajoute, puis remplace progressivement les
13 modèles bruts dans le scoring.

Cf. § 6 pour le découpage en phases qui rend cette migration
indolore (parallel run, shadow mode, bascule progressive).

---

## 2. Bornes / garde-fous retenus

### 2.1 Couverture géographique

- **Bbox carrée** : `lat 41-52 N`, `lng -5 à 10 E` (~1 300 × 1 200 km).
- Couvre France métropolitaine + une bande de Belgique, Luxembourg,
  ouest Allemagne, nord-est Espagne. Gratuitement, ça englobe tous les
  sites étrangers du seed (Markstein, Klusserath, Coo, Beauring,
  Losheim…).
- Pas de masquage polygonal de la France : la bbox carrée simplifie
  tout (calculs, indices, tuiles raster).
- Extension hors-Europe non envisagée à ce stade.

### 2.2 Résolutions et zoom

L'utilisateur n'a pas besoin d'une grille fine quand il regarde la
France entière, ni d'une grille grossière quand il zoome sur une
vallée. Mapping logique :

| Zoom Leaflet | Échelle visuelle    | Résolution utile |
|--------------|---------------------|------------------|
| 5-6          | France / Europe     | 13-25 km         |
| 7-8          | Quart de pays       | 7-10 km          |
| 9-10         | Région / massif     | 2.5 km           |
| 11+          | Vallée / commune    | 1.3 km           |

**Décision MVP** : zoom plafonné à **11**, donc résolution max =
**2.5 km** (grille native AROME 0.025°). Le passage à 1.3 km est
reporté en phase 3 (cf. § 6).

### 2.3 Variables

L'utilisateur choisit **une variable affichée** via la couleur
transparente de l'overlay. Les flèches de vent restent affichées en
permanence par-dessus (orientation = direction consensus, couleur
neutre).

Variables proposées pour la coloration :

1. **Vitesse moyenne du vent** (`wind_speed_avg`) — variable par défaut.
2. **Intensité des précipitations** (`precipitation`).
3. **Hauteur du plafond de vol estimé** (`cloud_base_consensus`,
   règle d'Espy).
4. **Confiance du consensus** (`models_converging / models_count`) —
   variable dérivée qui matérialise le travail du `ScoringService`.
   **C'est la variable qui n'existe nulle part ailleurs et qui
   matérialise la valeur ajoutée de Qui-Vole vs Windy / Meteoblue.**
5. **Rafales** (`wind_speed_max`) — usage secondaire (la lecture
   macro privilégie la moyenne), mais le coût d'inclusion est nul
   (mêmes données brutes, mêmes interpolations, juste une palette
   en plus). Conservé pour les pilotes qui veulent vérifier
   l'enveloppe haute du vent prévu.

La direction du vent (`wind_direction`) n'est pas une option de
coloration — elle alimente les flèches.

Plus tard, en phase 3 : **statut de volabilité parapente**
(green/orange/red) comme variable dérivée — appliquer la voting
logic complète à chaque cellule avec un « site générique » (plage
de vent neutre).

### 2.3.1 Palette de couleurs (unifiée)

**Une seule échelle bleu → rouge** pour toutes les variables, seules
les bornes (min / max et seuils intermédiaires) changent d'une
variable à l'autre. L'utilisateur n'a qu'une grille de lecture à
apprendre :

| Couleur | Signification universelle |
|---------|---------------------------|
| Bleu    | Situation favorable / confortable |
| Vert    | Acceptable |
| Jaune   | Vigilance |
| Orange  | Marginal |
| Rouge   | Défavorable / inconfortable |

Application par variable :

| Variable        | Bleu (favorable)   | Rouge (défavorable)    |
|-----------------|--------------------|------------------------|
| Vent moyen      | 0 km/h             | > 40 km/h              |
| Rafales         | 0 km/h             | > 60 km/h              |
| Précipitations  | 0 mm/h             | > 5 mm/h               |
| Plafond         | Élevé (> 2500 m)   | Bas (< 1000 m)         |
| Confiance       | Consensus fort     | Forte divergence       |

Techniquement : une seule fonction de palette (ex. `matplotlib.cm.RdYlBu_r`
ou palette custom dérivée), paramétrée par `(vmin, vmax)` propres à
chaque variable. ~10 lignes de Python.

### 2.3.2 Valeur pédagogique et intégration wiki

L'exposition de la **confiance** comme variable à part entière a une
dimension pédagogique forte : l'utilisateur comprend visuellement
ce qu'est un consensus multi-modèles, voit où les prévisions sont
solides et où elles ne le sont pas.

Le **pseudo-wiki** (`/aide`, `WikiController` / `WikiPage`) est déjà
en place techniquement. Son enrichissement éditorial est en cours
(mode d'emploi rédigé, section « fonctionnement du site » et
« météorologie générale » prévues). La documentation de la carte
consensus s'intègre naturellement dans ce scope, sans nouvelle
brique à développer :

- Une page « Comment fonctionne le consensus multi-modèles » dans la
  section technique, **incluant l'héritage de la plongée tech** :
  la voting logic est directement inspirée des recycleurs CCR
  (Closed Circuit Rebreather) où 3 capteurs O2 votent pour
  déterminer la pression partielle d'oxygène. Même principe
  (médiane, exclusion des outliers, indicateur de confiance),
  même enjeu (sécurité), transposé du milieu plongée vers la
  météo parapente. Cet angle narratif renforce la crédibilité de
  l'approche (pas une bidouille empirique, un principe
  d'ingénierie de sécurité éprouvé depuis 30 ans) et donne une
  filiation pilote (culture de la sécurité partagée entre sports
  d'engagement).
- Une page « Lire la carte de confiance » dans la section
  météorologie / mode d'emploi.
- Une **légende riche** côté carte renvoyant vers ces pages
  (au-delà d'un simple gradient de couleur : exemple visuel des
  configurations typiques — consensus fort vent faible / consensus
  fort vent fort / consensus faible vent variable). Le lien depuis
  la variable « confiance » pointe en priorité vers la page CCR
  pour que l'utilisateur curieux comprenne d'où vient la
  philosophie de l'app.

### 2.4 Horizon temporel

- MVP : **24 h** (horizon court, scénario « je vais voler aujourd'hui
  ou demain matin »).
- Phase 2 : **120 h** (5 jours, aligné sur le reste de l'app).

### 2.5 Cadence de mise à jour

- Cron **horaire**, calé après la fin du fetch Open-Meteo (idéalement
  à `:20` ou `:25`).
- Pas de temps réel sub-horaire — les modèles eux-mêmes ne se mettent
  à jour que toutes les 1 à 6 h selon le modèle.

---

## 3. Architecture cible

### 3.1 Vue d'ensemble

```
Serveur Open-Meteo self-hosted (déjà en place)
        │
        │  fichiers GRIB téléchargés dans son volume Docker
        ▼
[Container sidecar Python: consensus-grid]
        │
        │  ┌── Worker (cron horaire) ────────────────────────┐
        │  │ 1. Lit les GRIB des 13 modèles sur la bbox FR    │
        │  │ 2. Interpolation bilinéaire → grille 2.5 km      │
        │  │ 3. Consensus inverse-carré (numpy vectorisé)     │
        │  │ 4. Écrit NetCDF compressé                        │
        │  │ 5. Génère tuiles raster + flèches décimées       │
        │  └─────────────────────────────────────────────────┘
        │
        ├──> /data/consensus/run_<ts>.nc    (NetCDF)
        ├──> /data/tiles/<var>/<h>/<z>/<x>/<y>.webp
        ├──> /data/arrows/<h>/stride_<N>.json
        │
        │  ┌── FastAPI (toujours up, port 8082) ──────────────┐
        │  │ Expose un endpoint compatible Open-Meteo :        │
        │  │  GET /v1/forecast?latitude=X&longitude=Y          │
        │  │       &models=qui_vole_consensus                  │
        │  │       &hourly=wind_speed_10m,wind_direction_10m,..│
        │  │                                                   │
        │  │ → lookup bilinéaire dans le NetCDF courant        │
        │  │ → renvoie JSON exact format Open-Meteo            │
        │  └─────────────────────────────────────────────────┘
        │
        ▼
[Laravel — OpenMeteoApi]
        │  Routing par code modèle (config) :
        │   - 13 modèles publics → URL Open-Meteo officielle
        │   - qui_vole_consensus → URL sidecar (http://consensus-grid:8082/v1)
        │  Aucune autre modification.

Nginx
        │  sert /tiles/* et /arrows/* en statique (cache long)
        ▼
[Frontend Leaflet]
        │  - L.tileLayer pointant sur /tiles/<var>/<hour>/{z}/{x}/{y}.webp
        │  - L.canvas() lisant /arrows/<hour>/stride_<N>.json
        │  - Slider temporel = change <hour> dans les deux URLs
```

**Point clé** : le sidecar a **deux faces** :
- **Producteur** (le worker cron) qui calcule le consensus.
- **Serveur** (FastAPI) qui répond aux requêtes Laravel comme le ferait Open-Meteo lui-même.

Côté Laravel, l'unique changement est un mapping de configuration
qui route le code modèle `qui_vole_consensus` vers le sidecar.
**Tout le reste de l'app fonctionne sans modification** : le
`ScoringService`, les jobs `FetchSiteForecastsJob`, les
controllers, les admins, les seeders. Le consensus apparaît
naturellement dans la liste des modèles, dans la carte de
comparaison, dans le scoring, sans une ligne de code spécifique.

### 3.2 Stack logicielle

| Brique                       | Techno retenue                                 |
|------------------------------|------------------------------------------------|
| Lecture GRIB                 | `xarray` + `cfgrib` (Python)                  |
| Interpolation bilinéaire     | `xarray.interp` ou `scipy.interpolate`        |
| Calcul consensus             | `numpy` vectorisé (équivalent ScoringService) |
| Format de stockage           | **NetCDF4** compressé (zlib niveau 5)         |
| Génération tuiles raster     | `rio-tiler` + `matplotlib` (palettes custom)  |
| Tuiles webP / PNG            | sortie `rio-tiler`, ~2 ko/tuile              |
| Décimation flèches           | numpy slicing (`arr[::stride, ::stride]`)     |
| **API Open-Meteo-compat**    | **FastAPI + uvicorn**                         |
| Sidecar conteneur            | `python:3.12-slim` + image custom            |
| Orchestration cron           | Cron du conteneur Python (KISS)               |
| Service HTTP des tuiles      | Nginx (statique, cache long)                  |
| Frontend overlay couleur     | `L.tileLayer` (Leaflet natif)                |
| Frontend flèches             | `L.canvas()` + draw custom (perf > SVG)       |

Modifications côté Laravel (minimes) :
- **Mapping `model_endpoints`** dans `config/weather.php` :
  routage du code modèle vers l'URL Open-Meteo ou vers le sidecar
  selon le cas (cf. extrait code en § 3.4).
- **Entrée dans `WeatherModelSeeder`** pour le modèle
  `qui_vole_consensus` (mêmes colonnes que les autres modèles).
- **Vue Blade** pour la page carte (sélecteur variable + slider).
- **Entrée dans `modules`** (key `grid-consensus`).

**Aucune** modification de `ScoringService`, `OpenMeteoApi` (hors
mapping), jobs, observers, controllers existants.

### 3.3 Pourquoi Python et pas PHP ?

PHP fait ~50 µs/cellule pour le consensus brut, numpy fait
~50 ns/cellule vectorisé. Sur 150 000 cellules × 24 h × 5 variables
= 18 M points, ça fait **15 minutes vs 1 seconde**. Le coût
opérationnel d'un sidecar Python (1 conteneur) est largement
inférieur au coût d'un job PHP qui mettrait 15 min/heure à tourner.

À noter : on **ne porte pas** `ScoringService` en Python. La voting
logic métier (règles éliminatoires, statut green/orange/red,
seuils `site_conditions`, héritage CCR) reste **exclusivement en
PHP**. Le sidecar produit seulement la **moyenne consensus brute**
sur la grille (~50 lignes de numpy). Le `ScoringService` PHP
consomme cette moyenne comme il consommerait celle d'un modèle
quelconque.

Bonus : `xarray` + `cfgrib` lisent nativement les GRIB qu'Open-Meteo
télécharge déjà — pas de couche HTTP intermédiaire pour la phase
calcul.

### 3.4 Intégration côté Laravel (extrait)

```php
// config/weather.php
'model_endpoints' => [
    'default'             => env('OPEN_METEO_BASE_URL'),
    'qui_vole_consensus'  => env('CONSENSUS_API_URL', 'http://consensus-grid:8082/v1'),
],
```

```php
// app/Services/Weather/Apis/OpenMeteoApi.php (modification triviale)
private function baseUrlForModel(string $code): string
{
    $endpoints = config('weather.model_endpoints');
    return $endpoints[$code] ?? $endpoints['default'];
}
```

```php
// database/seeders/WeatherModelSeeder.php (entrée à ajouter)
[
    'code'           => 'qui_vole_consensus',
    'name'           => 'Consensus Qui-Vole',
    'family'         => 'qui_vole',
    'resolution_km'  => 2.5,
    'is_active'      => true,
    'horizon_hours'  => 120,
],
```

C'est tout. Le scoring d'un site avec `qui_vole_consensus` comme
unique modèle actif passe par exactement la même voting logic
PHP (`ConsensusCalculator::improvedLinear/Circular`), avec un seul
modèle en entrée — résultat : la valeur consensus brute est
remontée telle quelle, et les règles métier (`site_conditions`,
règles éliminatoires) s'appliquent normalement.

### 3.5 Format de réponse FastAPI

Le sidecar doit produire un JSON **strictement compatible** avec ce
que renvoie Open-Meteo pour les mêmes paramètres. Exemple type :

```json
{
  "latitude": 49.4468,
  "longitude": 6.0999,
  "elevation": 420.0,
  "generationtime_ms": 0.8,
  "timezone": "GMT",
  "hourly_units": {
    "time": "iso8601",
    "wind_speed_10m": "km/h",
    "wind_direction_10m": "°",
    "wind_gusts_10m": "km/h",
    "precipitation": "mm",
    "cloud_cover_low": "%"
  },
  "hourly": {
    "time": ["2026-05-20T00:00", "2026-05-20T01:00", ...],
    "wind_speed_10m": [12.4, 13.1, ...],
    "wind_direction_10m": [85, 90, ...],
    "wind_gusts_10m": [18.0, 19.2, ...],
    "precipitation": [0.0, 0.0, ...],
    "cloud_cover_low": [10, 15, ...]
  }
}
```

Implémentation FastAPI estimée : ~150 lignes (parsing de la
querystring Open-Meteo, lookup `xarray` au lat/lng demandé,
sérialisation au bon format). Cf. § 6 phase 1.

---

## 4. Volumétrie cible (MVP, grille 2.5 km, 24 h, bbox carrée FR)

| Élément                          | Volume                  |
|----------------------------------|-------------------------|
| Cellules de la bbox FR à 2.5 km  | ~150 000                |
| NetCDF consensus / run           | ~400 MB brut, ~100 MB compressé |
| Tuiles raster (4 var × 24 h)     | ~2-3 GB par run         |
| Flèches décimées (5 strides × 24 h) | ~50 MB par run       |
| Stockage total run courant       | ~3 GB                   |
| Avec historique J-1 / J-2        | ~10 GB                  |
| Calcul Python par run            | ~1-2 min single-core    |
| Génération tuiles par run        | ~1-2 min                |
| Total temps de run               | **~3-5 min / heure**    |

---

## 5. Comparaison avec une cible 1.3 km (info, non retenu MVP)

| Critère                         | 2.5 km (MVP) | 1.3 km (phase 3) |
|---------------------------------|--------------|------------------|
| Cellules bbox FR                | ~150 k       | ~600 k           |
| NetCDF consensus / run          | 100 MB       | 400 MB           |
| Tuiles raster (4 var × 24 h)    | 2-3 GB       | 8-10 GB          |
| Calcul Python                   | 1-2 min      | 5-8 min          |
| Effort dev incrémental          | référence    | ~0 (constante)   |

Le passage 2.5 → 1.3 km est trivial en dev (un paramètre à changer
dans le script Python + un rebuild de la pyramide). Mais ça multiplie
par ~4 le stockage et le temps de calcul. À justifier par un usage
réel (zoom 12+ régulièrement consulté).

---

## 6. Découpage suggéré (ordre d'implémentation)

Le découpage est conçu pour que **chaque phase soit indépendamment
déployable et déjà utile**. La migration moteur est progressive,
réversible, et observée en parallèle de l'ancien pipeline.

### Phase 1 — Sidecar Python + carte visuelle (~3-4 semaines)
- Sidecar Python : lecture GRIB, interpolation, consensus brut, NetCDF.
- Génération tuiles raster pour 1 variable (ex. vent moyen).
- **FastAPI endpoint** compatible Open-Meteo (~150 lignes).
- Mapping côté Laravel : ajout de `qui_vole_consensus` dans
  `weather_models` + routage du code modèle vers le sidecar.
- Page Blade minimale : carte + sélecteur variable + heure unique
  (pas de slider).
- Cron horaire dans le conteneur Python.
- **Critère de succès phase 1** :
  - Un overlay coloré fluide sur la France entière, mis à jour
    chaque heure.
  - Le modèle `qui_vole_consensus` apparaît dans `/admin/models` et
    dans la carte de comparaison `/carte-modeles`, ses données sont
    récupérables par le `FetchSiteForecastsJob` exactement comme un
    autre modèle.
  - **Pas de modification du scoring** — le consensus est juste un
    14ème modèle observable, sans impact métier.

### Phase 2 — Flèches et slider temporel (~1 semaine)
- Décimation des flèches par stride dans le sidecar.
- Couche `L.canvas()` avec rendu custom des flèches.
- Slider temporel 0-24 h (puis 0-120 h une fois validé).
- Sélecteur de variable étendu aux 5 affichables (vent moyen,
  rafales, précip, plafond, confiance).

### Phase 3 — Shadow mode scoring (~2-3 semaines)
**Préalable** : 2-3 semaines d'observation phase 1+2 en prod, pour
vérifier la qualité du consensus calculé par le sidecar contre les
balises (réutilisation du framework `FF_model_reliability.md`
phase 2.5).

- Ajout d'une option `scoring.use_consensus_only` dans `settings`
  (défaut : `false`).
- Modification du `FetchSiteForecastsJob` : si l'option est active,
  ne fetche que `qui_vole_consensus` pour le scoring (au lieu des 13
  modèles publics).
- Exécution **en shadow** sur quelques sites pilotes : on garde
  l'ancien scoring en prod, on calcule en parallèle le nouveau, on
  compare les statuses sur 1-2 semaines.
- **Critère de succès phase 3** :
  - Écart statut (green/orange/red) < 5 % entre ancien et nouveau
    scoring sur l'ensemble des sites pilotes.
  - Pas de régression sur le `day_quality`.
  - Temps de scoring divisé par > 10.

### Phase 4 — Bascule progressive (~1-2 semaines)
- Activation progressive de `use_consensus_only` site par site
  (toggle admin via `/admin/sites/{id}`), puis par batch.
- Suivi des écarts pendant 1-2 semaines.
- Désactivation progressive des 13 modèles publics dans
  `weather_models` (`is_active = false`).
- Cleanup : `forecasts` cesse d'être alimenté pour les modèles
  désactivés. Purge des anciennes données après 30 j.
- **Critère de succès phase 4** : un seul modèle actif dans
  `weather_models` (`qui_vole_consensus`), cycle de scoring < 1 min
  pour les ~200 sites actuels, marge confortable pour le scaling.

### Phase 5 — Affinement et extensions (~1 semaine, optionnel)
- Passage à 1.3 km (si l'usage zoom 12+ le justifie ET si la
  qualité du scoring l'exige sur sites en relief complexe).
- Variable « score de volabilité parapente » (intégrer la voting
  logic complète avec un site générique).
- Modulation de l'alpha par `models_converging` (couleur transparente
  = consensus faible).

### Réversibilité

À tout moment entre les phases 3 et 4, on peut **revenir au scoring
multi-modèles** en désactivant `use_consensus_only` et en
réactivant les 13 modèles publics. Le code de scoring n'a pas
été supprimé, juste contourné. Cette propriété est **clé** pour
limiter le risque de la bascule.

---

## 7. Risques identifiés

1. **Pyramide de tuiles lourde à régénérer** — si on génère
   bêtement tous les couples `(variable × hour × zoom × x × y)`
   à chaque run, on fait travailler I/O et CPU pour rien. Mitigation :
   ne régénérer que les variables dont les données ont changé
   (toutes en pratique, mais on peut envisager du diff par horizon).
2. **Synchronisation avec le serveur Open-Meteo** — il faut que les
   GRIB soient bien à jour quand le sidecar tourne. Mitigation :
   le sidecar tourne à `:20`, après la fin du fetch Open-Meteo qui
   se cale à `:00`. Si décalage, accepter de servir le run précédent.
3. **Cohérence avec le scoring par site existant** — couvert par
   construction dès la phase 3 (le scoring par site lit la même
   cellule de la même grille). Mitigation phase 1-2 (où les deux
   pipelines coexistent) : tests d'intégration comparant les
   valeurs consensus par site (ancien) vs par lookup (nouveau)
   sur 5-10 sites pilotes.
4. **Disponibilité du sidecar FastAPI** — si le sidecar tombe, le
   modèle `qui_vole_consensus` devient injoignable. Tant qu'on est
   en phase 1-2 (consensus = 14ème modèle), c'est sans gravité
   (les 13 autres restent). Dès la phase 4, c'est critique.
   Mitigation : healthcheck Docker, restart auto, fallback dans
   `OpenMeteoApi` qui détecte l'indisponibilité et utilise le
   dernier run réussi en cache (et alerte admin).
5. **Charge réseau au boot de la page** — 24 heures × 5 variables
   = 120 jeux de tuiles potentiellement préchargés. Stratégie :
   ne charger que l'heure courante au boot, précharger en arrière-plan
   au déplacement du slider.
6. **Convention de direction du vent** — bien stocker en FROM
   direction (météo standard) et ne pas oublier le `+180°` à
   l'affichage des flèches (piège connu, cf. CLAUDE.md point 4).
7. **Couverture partielle AROME-HD / AROME en bord de bbox** — la
   bbox carrée FR déborde à l'est (Allemagne, Suisse) et au sud
   (Espagne). AROME ne couvre pas ces zones. Mitigation : les
   modèles européens (ICON-EU, IFS, ARPEGE) restent disponibles
   partout dans la bbox ; le consensus accepte un sous-ensemble
   variable de modèles selon la position. Documenter pour les
   utilisateurs (légende « précision réduite hors France
   métropolitaine »).
8. **Précision spatiale 2.5 km vs 1.3 km AROME-HD natif** — en
   phase 3+, scorer un site via lookup grille 2.5 km revient à
   moyenner sur ~6 km² autour des coordonnées. Pour des sites en
   relief complexe (vallée étroite, crête), ça peut lisser des
   effets locaux que AROME-HD capturait. Mitigation : surveillance
   en shadow mode (phase 3), passage à 1.3 km en phase 5 si écart
   significatif détecté.
9. **Format Open-Meteo évolutif** — si Open-Meteo change son format
   de réponse JSON, le FastAPI doit suivre. Mitigation : tests
   d'intégration vérifiant que le format renvoyé par le sidecar est
   bit-identique à un appel Open-Meteo natif sur les mêmes
   paramètres.

---

## 8. Estimation grossière

| Phase   | Effort       | Stockage incrémental | Effet                |
|---------|--------------|----------------------|----------------------|
| Phase 1 | ~3-4 sem.    | 3 GB                 | Carte visuelle live + `qui_vole_consensus` disponible comme 14ème modèle |
| Phase 2 | +1 sem.      | +50 MB               | Flèches + slider temporel + 5 variables |
| Phase 3 | +2-3 sem.    | ~0                   | Shadow mode scoring, mesure qualité |
| Phase 4 | +1-2 sem.    | -50 % DB             | Bascule scoring complet, cleanup |
| Phase 5 | +1 sem.      | ×4 si 1.3 km         | Affinement optionnel |

**Total chantier complet : ~8-11 semaines** réparties sur ~4 mois
(observations en prod entre phases). Mais :
- **Phase 1 livre une feature visuelle utile dès la fin de la 4e
  semaine** — la communauté voit le résultat tôt.
- **Phases 3-4 sont la migration moteur** — elles préparent le
  scaling et peuvent être planifiées en fonction du rythme de
  croissance du nombre de sites.
- **Phase 5 est optionnelle** — à n'engager que si l'usage le
  justifie.

---

## 9. Décisions reportées (à trancher au démarrage)

- Conteneur sidecar Python : nouvelle entrée dans
  `docker-compose.prod.yml` ou projet Docker séparé ?
- Palette des couleurs : reprendre la palette par variable
  utilisée dans les popups carte (cohérence visuelle) ou définir
  une palette continue type Windy (plus lisible en plein écran) ?
- Mode dégradé sidecar indispo : `OpenMeteoApi` doit-il avoir un
  fallback automatique (lecture cache du dernier run) ou échouer
  proprement avec alerte admin ?
- Phase 1 — politique d'historique du `qui_vole_consensus` dans
  `forecasts` : faut-il fetcher et stocker pour les ~200 sites dès
  la phase 1 (consensus = observable 14ème modèle), ou seulement
  à partir de la phase 3 (pour limiter le volume DB tant que le
  consensus n'est pas exploité) ?
