# FF — Fiabilité par maille via stations météo (rayon de confiance)

> Statut : **cadré, non implémenté**. Discussion du 2026-06-10.
> Prérequis déjà en production : archives de prévisions par bucket aux
> coords des stations (`forecast_archive_stations`, 30 j, consensus
> inclus), agrégat horaire des observations
> (`weather_station_observations_hourly`, 30 j), observations brutes 7 j.

## Concept

Pondérer le consensus multi-modèles du sidecar `consensus-grid-v2`
**maille par maille** selon la fiabilité observée de chaque modèle sur
les 30 derniers jours, par bucket d'horizon (nowcast / same_day /
j_plus_1 / j_plus_2) et par variable.

Principe retenu :
1. La fiabilité est calculée **au niveau de chaque station météo** du
   réseau (comparaison prévisions archivées ↔ observations réelles).
2. Chaque station a un **rayon de confiance de 25 km**.
3. Une maille hérite de la fiabilité de la **station la plus proche**
   qui la couvre.
4. Maille couverte par aucune station → **consensus brut sans
   pondération** (tous les facteurs à 1.0).

Objectif complémentaire (pilier C) : comparer le consensus sidecar
archivé (`qui_vole_consensus`) aux observations stations pour calculer,
par paramètre, le **biais moyen** et l'**offset temporel** du consensus.

## Modèle de données retenu

### Nouvelle table `model_reliability_stations` (Laravel, écrite quotidiennement)

```
weather_station_id   FK weather_stations (cascade)
weather_model_id     FK weather_models (cascade) — consensus inclus
horizon_bucket       enum nowcast|same_day|j_plus_1|j_plus_2
variable             string (wind_speed_avg, wind_speed_max, wind_direction, temperature…)
mae, rmse            decimal
bias_signed          decimal (différence circulaire signée pour la direction)
weight_factor        decimal (médiane des MAE de la station / MAE du modèle, clampé)
samples_n            int
computed_at          datetime
UNIQUE (weather_station_id, weather_model_id, horizon_bucket, variable)
```

Volumétrie : ~1500 stations × 14 modèles × 4 buckets × 3-4 variables
≈ 250-350 k lignes, réécrites 1×/jour. Pas de problème MariaDB.

**Aucune table par maille.** Le mapping maille → station est une
fonction pure de la géométrie (grille sidecar + coords stations),
recalculée à la volée côté sidecar (cf. architecture).

### Index à ajouter

- `forecast_archive_stations (horizon_bucket, target_at)` — pour la
  jointure d'agrégation.

### Setting

- `reliability.station_radius_km` (défaut 25, éditable `/admin/settings`,
  lu par le sidecar comme les autres clés `settings`).

## Architecture retenue — 2 étages

### Étage 1 — Laravel : `ComputeStationReliabilityJob` (quotidien ~03h40)

- Stations éligibles : `active = 1 AND has_wind_sensor = 1`
  (le flag `in_reliability_panel` sert d'exclusion manuelle).
- **Agrégation 100 % SQL** (pas de boucle PHP par station) : une requête
  `GROUP BY weather_station_id, weather_model_id` par bucket, joignant
  `forecast_archive_stations` × `weather_station_observations_hourly`
  sur `(weather_station_id, target_at = hour_at)`, fenêtre
  `reliability.window_days` (à passer à 30).
  - Variables linéaires : `AVG(ABS(diff))`, `SQRT(AVG(POW(diff,2)))`,
    `AVG(diff)`, `COUNT(*)`.
  - Direction : distance/différence circulaires via
    `SIN/COS/RADIANS/ATAN2` en SQL (pattern déjà utilisé par
    l'agrégation horaire).
- PHP : dérivation du `weight_factor` (médiane des MAE des modèles
  éligibles de la station → ratio clampé `factor_min`/`factor_max`,
  neutre si `samples_n < min_samples`) + **upserts par lots de 500**.
- Le modèle `qui_vole_consensus` est inclus dans le calcul : ses lignes
  donnent directement le **biais moyen du consensus** par station ×
  bucket × variable (pilier C, partie biais).

### Étage 2 — Sidecar : mapping maille → station à la volée

Au début de chaque run consensus :
1. Charger coords des stations ayant des lignes éligibles + la table
   `model_reliability_stations` (filtrée `samples_n ≥ min_samples`).
2. KD-tree (`scipy.spatial.cKDTree`) sur coords projetées
   (équirectangulaire, correction `cos(lat)` — suffisant à 25 km).
3. `tree.query(centres_mailles, distance_upper_bound=25)` → vecteur
   `maille → station_id | aucune` (quelques ms pour la France entière).
4. Poids du modèle m sur la maille c =
   `poids_base × weight_factor[station(c), m, bucket, variable]` ;
   maille sans station → 1.0 partout (consensus brut).
5. Cache du mapping tant que le hash de la liste des stations ne change
   pas.

> ⚠️ L'étage 2 vit dans le repo du sidecar `consensus-grid-v2`, pas
> dans ce repo. Ce repo ne fournit que la table + les settings.

## Alternatives écartées

- **Table matérialisée par maille** : avec l'héritage « nearest », elle
  dupliquerait la ligne de la station sur N mailles (dizaines de
  millions de lignes/jour) sans information ajoutée. Écartée.
- **Mapping calculé côté Laravel** : Laravel ne connaît pas la grille
  du sidecar (et elle diffère par modèle). Le mapping doit vivre où la
  grille vit. Écarté.
- **Requêtes géo SQL par maille** : O(mailles) requêtes vs une passe
  KD-tree vectorisée. Écarté.
- **Réutiliser `model_reliability` (balises) avec clé polymorphe** :
  mélange deux vérités-terrain de qualité différente et complique les
  index. Table dédiée stations préférée.

## Bornes / garde-fous

- `min_samples` (existant, défaut 50) : cold start neutre.
- `factor_min` / `factor_max` (existants, 0.25 / 2.0) : clamp.
- Rayon 25 km **configurable** (`reliability.station_radius_km`).
- Variables non mesurées par les stations (précip fine, plafond…) :
  pas de pondération (facteur 1.0).
- Relief : en montagne la station la plus proche peut être dans une
  autre masse d'air. Raffinement futur possible côté sidecar :
  `distance_effective = d + k × |Δaltitude|`. Pas dans la v1.

## Pilier C — biais et offset temporel du consensus (suite)

- **Biais** : couvert par l'inclusion de `qui_vole_consensus` dans
  `model_reliability_stations` (bias_signed par station × bucket ×
  variable).
- **Offset temporel** : non couvert par cette FF. Chantier séparé :
  pour chaque (station, variable), tester des décalages −3 h…+3 h entre
  série consensus et série observée et retenir le lag minimisant la
  MAE. Table dédiée à cadrer plus tard.

## Découpage suggéré

1. Migration `model_reliability_stations` + index composite sur
   `forecast_archive_stations` + setting `station_radius_km`. (0,5 j)
2. `ComputeStationReliabilityJob` (agrégation SQL + weight_factor +
   upserts par lots) + commande artisan sync `reliability:compute-stations`
   + schedule quotidien. (1 j)
3. Écran admin : pivot stations × modèles (réutiliser le pattern
   `/admin/reliability/models`) + bascule de `ModelGridBuilder` vers la
   nouvelle table (lecture seule). (1 j)
4. Côté sidecar (autre repo) : loader + KD-tree + application des poids.
5. Plus tard : dépréciation du shadow mode balises A/B/C
   (`ConsensusCalculator`, `balise_consensus_compare`,
   `ComputeBaliseConsensusCompareJob`) et du code mort METAR balises.

## Risques

- **Densité du réseau** : zones sans station < 25 km (mer, montagne
  profonde, étranger proche) resteront en consensus brut — c'est le
  comportement voulu, mais à visualiser (carte des modèles) pour ne pas
  sur-interpréter.
- **Qualité hétérogène des stations** (MF pro vs Infoclimat amateur) :
  une station amateur bruitée dégrade la pondération de ses 25 km.
  Levier : exclusion manuelle (`in_reliability_panel`) ou pondération
  par réseau, à décider après observation.
- **Dérive de cadence des réseaux** : si un réseau cesse de publier,
  `samples_n` tombe sous le seuil et la pondération redevient neutre
  (dégradation propre — pas de poids fantômes).
- **Cohérence Laravel ↔ sidecar** sur la sémantique du `weight_factor`
  (clamp, min_samples appliqué à l'écriture côté Laravel OU à la
  lecture côté sidecar — choisir UNE convention et la documenter).
