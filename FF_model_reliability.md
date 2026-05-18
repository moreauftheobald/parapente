# FF — Fiabilité dynamique des modèles météo

> **Statut** : phases 1 et 2.5 **livrées en prod** (2026-05-18, branche V2).
> Phases 2 (consolidation), 3 (UI utilisateur) et 4 (intégration consensus)
> à planifier ensuite.
> **Date de rédaction** : 2026-05-13 (révisé 2026-05-18).
> **Pré-requis bloquant phase 4** : 2-3 semaines d'observation des
> consensus A / B / C en production, validation des critères de réussite
> (§ *Phase 2.5 — Critère de réussite*).

Ce document consigne la discussion de cadrage. Il sert de point de
reprise quand on décidera d'implémenter les phases suivantes.

---

## Travaux livrés — phase 2.5 (2026-05-18)

Validation par triple-consensus shadow livrée et déployée en prod sur
`qui-vole.fr`. Découpé en 5 commits sur la branche
`claude/weather-model-voting-yTBxM`, fusionnée dans `V2` :

| Commit | Périmètre | Livrables |
|--------|-----------|-----------|
| 1 | Schéma + settings + toggle balise | 3 migrations (`add_in_consensus_compare_panel_to_balises`, `create_model_reliability_table`, `create_balise_consensus_compare_table`), 2 modèles Eloquent (`ModelReliability`, `BaliseConsensusCompare`), 11 nouvelles clés `reliability.*` dans `Settings::DEFAULTS`, support du type `bool` côté UI settings, toggle « Panel test fiabilité » sur la fiche admin balise. |
| 2 | Services + tests | `ConsensusCalculator` (classe statique pure : `legacyLinear`, `legacyCircular`, `improvedLinear`, `improvedCircular` + helpers MAD/médiane/circulaire), `ReliabilityCalculator` (lookup `weight_factor` + cache mémoire + méthode batch), `BaliseConsensusCompareService` (orchestrateur), commande artisan `reliability:compute-compare`, 13 tests unitaires couvrant cas standards et limites. |
| 3 | Jobs + scheduling | `ComputeBaliseConsensusCompareJob` (horaire `:10`), `ComputeModelReliabilityJob` (quotidien 03:30), extension `ReliabilityCalculator::recomputeForBalise()` pour MAE/RMSE/bias/weight_factor (linéaire + circulaire). Méthode `signedCircularDiff()` pour bias direction. Commande artisan `reliability:compute-factors`. |
| 4 | Écrans admin | `/admin/reliability/compare` (J / J+1 / J+2 par balise/variable, MAE A/B/C, badge 🏆 sur le meilleur, ΔA/ΔB/ΔC colorés) et `/admin/reliability/models` (pivot modèle × bucket avec MAE/biais/poids/n + bouton « Recalculer maintenant »). Entrée sidebar admin. |
| 5 | Exports + contexte | `ReliabilityExportService` (CSV cursor + JSON complet), 3 endpoints `/admin/reliability/export.*`, boutons CSV/JSON sur les 2 écrans. `RELIABILITY_ANALYSIS_CONTEXT.md` (~400 lignes) destiné à accompagner les exports JSON pour analyse externe (Claude chat, R, Python). |

État au déploiement (2026-05-18, 3 balises panel) :
- ~3500 tuples dans `balise_consensus_compare` (fenêtre ±72 h)
- ~520 tuples dans `model_reliability` (toutes variables × buckets)
- Temps de calcul : ~15 s par balise pour `compute-compare`,
  ~15-30 s pour `compute-factors`. Largement dans les timeouts
  (300 s / 600 s).

---

## Concept

Mesurer en continu l'**erreur de chaque modèle météo contre la réalité
observée** (balises), séparément par horizon de prévision (J, J-1,
J-2), sur une fenêtre glissante de 7 jours. En tirer une pondération
dynamique des modèles utilisée dans la **voting logic du consensus
multi-modèles** (et non dans la voting logic de volabilité d'un site,
qui reste basée sur les seuils statiques).

L'objectif est un **auto-apprentissage léger, sans ML** : les modèles
les plus précis à un horizon donné gagnent plus d'influence pour
prédire ce même horizon. Si tel modèle Météo-France était fiable à J-2
sur les 7 derniers jours, son poids pour prédire J+2 augmente.

Limite explicite de la phase 2 : la fiabilité est calculée **par
balise** (au point exact où on a une vérité-terrain). L'extension aux
sites de vol (qui n'ont pas de balise sur place) fait l'objet de la
phase 4 (mapping IDW).

---

## Pré-existant (rappel infra)

### Déjà en place avant ce sujet
- Table `forecast_archive_balises` (cf. migration 2026_05_07) : pour
  chaque couple `(balise, modèle, target_at, horizon_bucket)`, stocke
  les valeurs `wind_direction / wind_speed_avg/min/max / temperature`
  prévues par le modèle. Quatre buckets : `nowcast` (0–6 h),
  `same_day` (6–24 h), `j_plus_1` (24–48 h), `j_plus_2` (48–72 h).
- Job `FetchBaliseForecastsJob` horaire, qui upsert les 13 modèles
  Open-Meteo aux coordonnées de chaque balise active.

### Livré en phase 1 (commit du 2026-05-13)
- Table `balise_readings_hourly` : agrégat horaire des lectures balises
  (direction en moyenne circulaire, vitesse moyenne, rafale, température).
- Job `AggregateBaliseReadingsHourlyJob` horaire à `:05`.
- Rétention 7 jours (purge intégrée à `PurgeOldForecastsJob`).

### Biais connu du design d'archivage
Le job upsert sur `(balise, modèle, target_at, horizon_bucket)`. Comme
il tourne toutes les heures, chaque bucket est ré-écrit plusieurs fois
au fil de la convergence vers la cible. Conséquence : la ligne stockée
représente la **prédiction faite au bord proche** du bucket (ex.
`j_plus_2` contient la prédiction faite ~49 h avant la cible, pas
~72 h). C'est acceptable pour distinguer 4 horizons distincts, mais
ce n'est pas exactement "la prévision d'il y a 72 h". Pistes
d'amélioration possibles (non retenues en V1, à reconsidérer si la
fiabilité ne discrimine pas bien) :
- Inverser la stratégie d'upsert (garder le premier upsert = horizon
  le plus lointain).
- Stocker à des horizons discrets (72 h / 48 h / 24 h / 6 h ± 1 h).
- Garder l'historique complet (pas d'upsert) — explosion du volume.

---

## Architecture retenue

### Modèle de données — nouvelle table

```
model_reliability
├── id
├── weather_model_id        FK weather_models    cascade
├── balise_id               FK balises           cascade
├── horizon_bucket          enum (nowcast | same_day | j_plus_1 | j_plus_2)
├── err_dir_pct             decimal(5,2)  nullable    -- % d'erreur direction circulaire
├── err_wind_avg_pct        decimal(6,2)  nullable    -- % d'erreur vitesse moyenne
├── err_wind_max_pct        decimal(6,2)  nullable    -- % d'erreur rafale
├── err_temp_pct            decimal(6,2)  nullable    -- % d'erreur température
├── err_combined            decimal(6,2)              -- score pondéré (cf. formule)
├── weight_factor           decimal(4,2)              -- multiplicateur après clamp
├── samples_n               int                       -- nombre d'observations appariées
├── computed_at             datetime
├── timestamps()
└── UNIQUE (weather_model_id, balise_id, horizon_bucket)
```

Indexes : `(balise_id, horizon_bucket)` pour la lecture côté écran
admin, `(weather_model_id, horizon_bucket)` pour les jointures futures
côté voting logic.

### Paramètres globaux — table `settings`

| Clé | Défaut | Rôle |
|---|---|---|
| `reliability.weight_dir_pct` | `0.20` | Coefficient α direction dans `err_combined` |
| `reliability.weight_wind_avg_pct` | `0.35` | Coefficient α vitesse moyenne |
| `reliability.weight_wind_max_pct` | `0.35` | Coefficient α rafale |
| `reliability.weight_temp_pct` | `0.10` | Coefficient α température |
| `reliability.speed_floor_kmh` | `5` | Plancher dynamique pour normalisation vitesse |
| `reliability.min_samples` | `50` | Seuil sous lequel `weight_factor = 1.0` (garde-fou cold start) |
| `reliability.factor_min` | `0.25` | Borne basse du multiplicateur |
| `reliability.factor_max` | `2.0` | Borne haute du multiplicateur |
| `reliability.window_days` | `7` | Fenêtre glissante de calcul |

Tous éditables via l'écran admin (voir plus bas).

### Métriques d'erreur

**Direction (circulaire)** :
```
err_dir_deg = min(|d_pred - d_obs|, 360 - |d_pred - d_obs|)
err_dir_pct = err_dir_deg / 180 × 100
```
→ 0 % = exact, 100 % = vent exactement à l'opposé.

**Vitesse (moyenne, rafale) — plancher dynamique** :
```
V_ref         = max(obs_kmh, speed_floor_kmh)        -- défaut floor = 5 km/h
err_speed_pct = |v_pred - v_obs| / V_ref × 100
```
Cette formule pénalise correctement les **basculements de régime**
(modèle annonce 15 km/h alors qu'il y a 0 km/h réel → 300 % d'erreur)
sans exploser à zéro, et garde une erreur proportionnée en vent fort.

**Température** : MAE relatif simple `|t_pred - t_obs| / max(|t_obs|, 5) × 100`.
La temp pèse peu (`α = 0.10`), pas la peine de raffiner.

**Score combiné** :
```
err_combined = α_dir·err_dir + α_avg·err_avg + α_max·err_max + α_temp·err_temp
```
avec Σ α_* = 1.0 (à valider à l'admin, sinon on normalise à la lecture).

### Calcul du `weight_factor`

```
Pour chaque (balise, horizon_bucket) :
    median_E = médiane des err_combined des modèles ayant samples_n ≥ min_samples

Pour chaque tuple (modèle, balise, horizon_bucket) :
    si samples_n < min_samples :
        weight_factor = 1.0           -- garde-fou cold start
    sinon :
        ratio         = median_E / err_combined
        weight_factor = clamp(ratio, factor_min, factor_max)
```

→ un modèle 2× meilleur que la médiane → facteur 2 (plafonné).
→ un modèle 4× pire que la médiane → facteur 0.25 (plancher).

**Pourquoi pas remplacer entièrement les poids statiques** : préserve
la diversité de l'ensemble. Si un modèle a été chanceux sur 7 jours
(météo simple, peu de fronts), il ne peut pas monopoliser le consensus
— il sera juste un peu plus écouté.

### Job — `ComputeModelReliabilityJob`

Cron : `5 */3 * * *` (à H+5 toutes les 3 h : 00:05, 03:05, 06:05, …).
Décalé pour passer après l'agrégation horaire des balises (`:05`) et
après le fetch des prévisions (`:00`).

Algorithme :
1. Récupère les modèles actifs + balises actives.
2. Pour chaque (modèle × balise × bucket) :
   - Jointure `forecast_archive_balises` × `balise_readings_hourly` sur
     `target_at = hour_at`, fenêtre `now() - window_days`.
   - Calcule `err_dir_pct`, `err_wind_avg_pct`, `err_wind_max_pct`,
     `err_temp_pct` ligne par ligne, puis moyenne.
   - Stocke `samples_n` = nombre de paires effectives.
3. Pour chaque (balise × bucket) : calcule la médiane des
   `err_combined` parmi les modèles avec `samples_n ≥ min_samples`.
4. Calcule `weight_factor` par tuple (formule ci-dessus).
5. Upsert sur `model_reliability` (clé unique : model + balise + bucket).

Idempotent. Coût : 1 jointure de ~7 × 24 × N_balises × N_modèles
lignes (~hundreds of thousands d'enregistrements), agrégée. Devrait
tourner en quelques secondes même à 50 balises × 10 modèles.

### Écrans admin

**Écran 1 — Paramètres de fiabilité** (nouveau, ou onglet de
`/admin/settings`) :

L'admin `/admin/settings` actuel devient trop chargé. Réorganisation
en **onglets** :
- *Scoring* (seuils précip, rafale, paramètres de viabilité — clés
  existantes `scoring.*` et `viability.*`)
- *Fiabilité des modèles* (nouvelles clés `reliability.*`)
- *Interface* (futures clés `ui.*`, cf. FF_accessibility_palette)
- *Système* (purge, rétention, planificateur)

Édition formulaire classique, validation côté Form Request, flush
cache Redis du service `App\Services\Settings` à la sauvegarde.

**Écran 2 — Surveillance des calculs de fiabilité** (nouveau,
`/admin/model-reliability` par exemple) :

Tableau pivot :
- Lignes : modèles météo (avec couleur, nom, état actif)
- Colonnes : (balise × horizon_bucket) — peut être plié par balise si
  trop large (groupes de 4 colonnes par balise)
- Cellule : `weight_factor` coloré (rouge < 0.5, gris ≈ 1, vert > 1.5),
  `samples_n` en sous-titre, `err_combined` brut en tooltip
- Filtres : balise, horizon, modèle actif uniquement, samples_n ≥ X
- Bandeau du haut : timestamp du dernier `ComputeModelReliabilityJob`,
  bouton "recalculer maintenant" (dispatchSync depuis l'admin)
- Sous-tableau : détail par variable (err_dir / err_avg / err_max /
  err_temp) accessible au clic sur une cellule

Sert à :
- Vérifier visuellement que les classements ont du sens (ECMWF gagne
  en J-2 sur ARÔME en zone montagne, AROME-HD gagne en nowcast, etc.).
- Détecter une dérive (un modèle qui s'effondre brusquement = alerte).
- Calibrer les coefficients α via observations (si on voit que la
  rafale discrimine peu les modèles, on baisse `weight_wind_max_pct`).

### Intégration voting logic (phase 4)

**Important** : la voting logic touchée est celle qui calcule le
**consensus multi-modèles** (`ScoringService::computeConsensus*()`), pas
les **règles éliminatoires de volabilité** (seuils précip/rafale qui
restent statiques et basés sur `settings.scoring.*`).

Pour un slot de prévision à `forecast_at = T` :
1. Calcule `horizon_h = T - now()` en heures.
2. Dérive le `horizon_bucket` correspondant (nowcast/same_day/j_plus_1/j_plus_2).
3. Pour chaque modèle M contribuant au slot, calcule le poids effectif :
   ```
   poids_eff(M, T) = poids_statique(M, bucket) × weight_factor_effectif(M, T)
   ```
   où `weight_factor_effectif` est l'agrégation IDW sur les balises
   proches du site (cf. phase 4 ci-dessous).
4. La voting logic existante (moyenne pondérée, moyenne circulaire
   pour la direction) utilise ces poids effectifs au lieu des poids
   statiques `weather_models.weight_short` / `weight_medium`.

### Mapping balise→site (phase 4) — IDW pondéré par les carrés des distances

Pour un site S à scorer, on n'a pas de balise directement dessus. On
agrège la fiabilité des balises voisines par **inverse distance
squared weighting** :

```
Pour chaque (modèle M, horizon H, site S) :
    balises_voisines = balises actives à distance ≤ R_MAX du site S
    si vide :
        weight_factor_effectif(M, S, H) = 1.0   -- fallback statique
    sinon :
        Σ_num = Σ_i [ (1/d_i²) × weight_factor(M, balise_i, H) ]
        Σ_den = Σ_i (1/d_i²)
        weight_factor_effectif(M, S, H) = Σ_num / Σ_den
```

Points à creuser au moment d'implémenter la phase 4 :
- Rayon `R_MAX` (50 km ? 100 km ?) — éditable dans `settings`.
- Distance 3D vs 2D — un site à 1500 m d'altitude ne peut pas
  s'appuyer sur une balise littorale à 50 km à vol d'oiseau. Pénaliser
  Δaltitude via une distance "effective" `d_eff = √(d_horiz² + (Δalt × κ)²)`
  avec κ paramétrable.
- Garde-fou : si toutes les balises voisines ont `samples_n < min_samples`
  → `weight_factor_effectif = 1.0`.

---

## Phase 2.5 — Validation par triple-consensus en shadow mode

> **Ajoutée le 2026-05-18**. Intercalée entre phase 2 (calcul de
> fiabilité) et phase 4 (branchement réel). Permet de prouver chiffres
> en main que les améliorations B et C font mieux que l'algorithme actuel
> A avant de toucher au consensus de prod.

### Concept

On calcule, pour chaque créneau d'un petit ensemble de **balises de
référence**, **trois consensus en parallèle** et on les confronte à la
vérité-terrain (lecture balise agrégée par `balise_readings_hourly`) :

- **Consensus A — actuel** : exactement ce que fait `ScoringService`
  aujourd'hui. Moyenne inverse-carré linéaire (EPSILON = 0.001) +
  moyenne circulaire simple pour la direction. Sert de **référence**.
- **Consensus B — amélioré, sans fiabilité** :
  - EPSILON revu (1.0 par défaut) pour ne plus écraser les écarts
    inférieurs à la précision réelle des modèles.
  - **Médiane pondérée** au lieu de moyenne pondérée — moins sensible
    aux outliers (un modèle qui annonce 70 km/h alors que les autres
    voient 25 ne tire plus la moyenne).
  - **Normalisation MAD intra-créneau** : on déduit chaque valeur de la
    médiane, on divise par la MAD du créneau (× facteur 1.4826 pour
    être cohérent avec un écart-type robuste), puis on filtre les
    modèles dont |z| dépasse un seuil (typiquement 3). Élimine les
    aberrations avant agrégation.
  - **Toutes les fiabilités égales (1.0)** : on isole le gain de la
    seule méthode statistique.
- **Consensus C — amélioré, avec fiabilité** : identique à B, plus la
  pondération par `weight_factor` issu de `model_reliability` (cf.
  phase 2). Mesure l'apport **additionnel** de la fiabilité dynamique
  par-dessus l'amélioration statistique.

Les **règles de scoring de volabilité** (statut vert/orange/rouge des
sites) ne sont **pas touchées**. La prod tourne sur consensus A
inchangé. La phase 2.5 ne fait qu'**observer**.

### Variables couvertes

- `wind_speed_avg` (km/h) — vitesse moyenne du vent.
- `wind_speed_max` (km/h) — rafale.
- `wind_direction` (deg) — direction circulaire.

Température exclue (pas critique pour la décision de voler).

### Balises de référence

Un panel **petit mais varié** (visé : 3 balises au démarrage) pour
balayer plusieurs régimes météo sans surcharger ni l'admin ni le
calcul :

| Zone | Profil typique | Balise visée |
|------|---------------|--------------|
| Alpes du Nord | Vallées encaissées, brises thermiques marquées, modèles globaux faibles | Secteur Samoëns / Haute-Savoie |
| Causses (Massif Central) | Plateau exposé, vents synoptiques + brise de pente | Puncho d'Agast (Millau) |
| Grand Est | Plaine / Vosges, transitions de fronts, plus proche des sites seed du projet | À choisir parmi les balises actives |

**Conflit sémantique évité** : le champ `reliability_class` existant
(migration `2026_05_15_130000`) sert un autre objectif — classer une
balise comme `'pro'` ou `'amateur'` pour la future intégration
Windy/Netatmo dans le **scoring de site**. On **ne réutilise pas**
ce champ.

Nouveau champ dédié sur `balises` :

```
balises.in_consensus_compare_panel  boolean  default false  not null
```

Il pilote l'inclusion dans le calcul phase 2.5. Édition manuelle par
l'admin via la fiche balise (`/admin/balises/{id}`) — un toggle à côté
du toggle « actif » existant, géré par une méthode `toggleComparePanel`
sur `Admin\BaliseController` (même pattern que `toggleActive`).

Avantage : aucun seeder ni script de backfill géographique. L'admin
sélectionne à la main les balises qu'il veut suivre, et peut ajuster
le panel à tout moment (ajouter une 4ᵉ balise, retirer une qui dérive,
etc.). La clé `settings('reliability.reference_balise_class')` évoquée
plus haut **disparaît** — remplacée par ce booléen.

### Modèle de données — nouvelle table `balise_consensus_compare`

```
balise_consensus_compare
├── id
├── balise_id              FK balises          cascade
├── target_at              datetime            -- heure de la prévision
├── horizon_bucket         enum (nowcast | same_day | j_plus_1 | j_plus_2)
├── variable               enum (wind_speed_avg | wind_speed_max | wind_direction)
├── consensus_a            decimal(6,2)        -- algo legacy
├── consensus_b            decimal(6,2)        -- amélioré sans fiabilité
├── consensus_c            decimal(6,2)        -- amélioré avec fiabilité
├── observation            decimal(6,2)  nullable  -- lu dans balise_readings_hourly
├── observation_count      smallint  nullable  -- nb de readings agrégés
├── models_count           smallint            -- nb de modèles ayant contribué
├── mad_value              decimal(6,2) nullable -- MAD calculée du créneau (debug)
├── computed_at            datetime
├── timestamps()
└── UNIQUE (balise_id, target_at, horizon_bucket, variable)
```

Indexes : `(balise_id, variable, target_at)` pour l'écran admin,
`(target_at)` pour la purge.

Rétention alignée sur celle de `forecast_archive_balises` : 14 jours
glissants (purge intégrée à `PurgeOldForecastsJob`).

### Raffinement nécessaire de `model_reliability` (phase 2)

La table `model_reliability` telle que définie plus haut stocke un
`err_combined` agrégé sur les 4 variables. La phase 2.5 a besoin d'un
**`weight_factor` par variable** (un même modèle peut être bon en
direction mais médiocre en rafale). Deux options :

- **Retenue** : ajouter une colonne `variable` à la clé unique et avoir
  une ligne par (model × balise × bucket × variable). Plus fin,
  exploitable directement par la phase 2.5 et la phase 4 (poids
  variable par variable comme évoqué dans *Pour aller plus loin*).
- Écartée : garder `err_combined` et accepter que C utilise le même
  poids pour les 3 variables. Plus simple mais moins instructif pour
  la comparaison.

Du coup la table devient :

```
model_reliability
├── id
├── weather_model_id        FK weather_models    cascade
├── balise_id               FK balises           cascade
├── horizon_bucket          enum
├── variable                enum (wind_speed_avg | wind_speed_max | wind_direction)
├── mae                     decimal(6,2)         -- erreur absolue moyenne
├── rmse                    decimal(6,2)         -- erreur quadratique moyenne
├── bias_signed             decimal(6,2)         -- biais (pred − obs) moyen
├── weight_factor           decimal(4,2)         -- multiplicateur clampé
├── samples_n               int
├── computed_at             datetime
├── timestamps()
└── UNIQUE (weather_model_id, balise_id, horizon_bucket, variable)
```

Le calcul du `weight_factor` reste inchangé (`median_E / err_modèle`,
clampé `[factor_min, factor_max]`, neutre à 1.0 si `samples_n < min_samples`)
— juste appliqué par variable.

### Algorithmes détaillés

#### Consensus A — legacy linéaire

```
Pour chaque variable v ∈ {wind_speed_avg, wind_speed_max} :
    poids_i = 1 / (écart_i² + 0.001)         -- EPSILON = 0.001
    consensus_A = Σ(v_i × poids_i) / Σ(poids_i)

Pour wind_direction :
    Moyenne circulaire arithmétique non pondérée
    (atan2(Σ sin θ_i, Σ cos θ_i))
```

(Reproduction exacte de `ScoringService::computeConsensus*()` actuel —
on extrait la logique pour pouvoir l'appeler isolément.)

#### Consensus B — amélioré sans fiabilité

```
EPSILON_NEW          = settings('reliability.epsilon_new', 1.0)
MAD_FLOOR            = settings('reliability.mad_floor', 0.5)
Z_OUTLIER_THRESHOLD  = settings('reliability.z_outlier_threshold', 3.0)

Pour chaque variable v ∈ {wind_speed_avg, wind_speed_max} :
    1. med = médiane(v_i)
    2. MAD = max(médiane(|v_i − med|) × 1.4826, MAD_FLOOR)
    3. Filtrer les modèles tels que |v_i − med| / MAD > Z_OUTLIER_THRESHOLD
    4. Sur le reste, poids_i = 1 / (écart_i² + EPSILON_NEW)
    5. consensus_B = médiane_pondérée(v_i, poids_i)

Pour wind_direction :
    1. med = médiane circulaire (cf. atan2 sur les sin/cos, puis correction
       par recherche locale du θ minimisant Σ angular_dist(θ, θ_i))
    2. MAD circulaire = médiane des angular_dist(θ_i, med), exprimée en deg
    3. Filtrer les modèles tels que angular_dist(θ_i, med) > k × MAD_circ
    4. Sur le reste, médiane circulaire pondérée par poids inverse-carré
       sur la distance angulaire (formule : on convertit chaque θ_i en
       vecteur unitaire, on somme avec poids, on prend l'angle de la
       résultante — équivalent pondéré du atan2)
```

La **médiane circulaire pondérée** est non triviale mathématiquement.
L'implémentation passera par la résultante vectorielle pondérée
(approximation suffisante pour des données quasi-unimodales, ce qui
est le cas pour des prévisions multi-modèles dans une fenêtre de 6 h).
Si on observe en pratique des bimodalités fortes (rare — vent qui
tourne en milieu de créneau), on basculera sur une méthode itérative
de minimisation de la dispersion circulaire pondérée.

#### Consensus C — amélioré avec fiabilité

Identique à B, mais avec une étape supplémentaire avant la pondération
inverse-carré :

```
Pour chaque modèle i contribuant au créneau :
    w_rel_i = model_reliability.weight_factor pour (modèle_i, balise,
                                                     bucket, variable)
              (1.0 par défaut si la table est vide ou samples_n < min_samples)

Étape 4 modifiée :
    poids_i = w_rel_i × (1 / (écart_i² + EPSILON_NEW))
    consensus_C = médiane_pondérée(v_i, poids_i)
```

### Paramètres globaux — nouvelles clés `settings`

| Clé | Défaut | Rôle |
|---|---|---|
| `reliability.shadow_enabled` | `true` | Kill switch global du job 2.5 |
| `reliability.epsilon_new` | `1.0` | EPSILON pour B et C (km/h²) |
| `reliability.mad_floor` | `0.5` | Plancher MAD pour éviter division (km/h) |
| `reliability.z_outlier_threshold` | `3.0` | Seuil de filtrage outliers (en MAD) |
| `reliability.dir_mad_z_threshold` | `3.0` | Idem pour la direction (en MAD circulaire) |
| `reliability.use_weighted_median` | `true` | Switch médiane pondérée vs moyenne pondérée |
| `reliability.use_mad_filtering` | `true` | Switch normalisation/filtrage MAD |

(Les clés relatives au calcul du `weight_factor` de phase 2 —
`weight_dir_pct`, `min_samples`, `factor_min`, `factor_max`,
`window_days` — restent celles définies plus haut.)

### Jobs

**`ComputeBaliseConsensusCompareJob`** — horaire, dispatché à `:10`
après `FetchBaliseForecastsJob` (`:00`) et `AggregateBaliseReadingsHourlyJob`
(`:05`).

Algorithme :
1. Liste les balises **actives** avec `in_consensus_compare_panel = true`.
2. Pour chaque balise et chaque créneau dans la fenêtre `[now−72h, now+72h]` :
   - Pour chaque variable (avg, max, dir) :
     - Lit les valeurs des modèles depuis `forecast_archive_balises`.
     - Calcule A, B, C via `ConsensusCalculator` (service partagé).
     - Lit l'observation depuis `balise_readings_hourly` si `target_at` ≤ now.
     - Upsert dans `balise_consensus_compare`.

Idempotent. Coût : `3 balises × 144 créneaux × 3 variables × 4 buckets`
= ~5 000 enregistrements par tick au maximum (en pratique moins,
beaucoup de buckets vides sur les horizons éloignés). Quelques secondes.

### Écrans admin

**Écran 1 — Comparaison consensus (`/admin/reliability/compare`)**

- Filtres en haut : balise (dropdown), variable (avg/max/dir).
- 3 sections empilées : **J / J+1 / J+2** (un panneau par horizon).
- Tableau heure par heure dans chaque section :
  ```
  target_at | A | B | C | observation | Δ_A | Δ_B | Δ_C
  ```
  Couleur sur Δ : vert si |Δ| ≤ 5 % de l'observation, orange si ≤ 15 %,
  rouge au-delà. Pour la direction : vert ≤ 10°, orange ≤ 30°, rouge au-delà.
- Bandeau récap au sommet de chaque panneau : MAE de A, B, C sur la
  période visible → le chiffre qui tranche. Bonus visuel : un mini
  badge "🏆 B" ou "🏆 C" sur le gagnant.
- Bouton "exporter CSV" (les pilotes peuvent rejouer en R/Python).

**Écran 2 — Fiabilité par modèle (`/admin/reliability/models`)**

Tableau pivot modèle × bucket :
- Une cellule par variable : MAE / RMSE / biais / `weight_factor`.
- Filtres : balise, modèle actif, samples_n ≥ X.
- Bandeau : timestamp du dernier `ComputeModelReliabilityJob`, bouton
  "recalculer maintenant".

**Écran 3 — Édition settings (`/admin/settings`)**

Réorganisation en onglets (cf. spec phase 2 ci-dessus). Nouvel onglet
*Fiabilité* qui regroupe les clés `reliability.*` (phase 2 + 2.5).

### Tests unitaires (obligatoires sur cette phase)

`ConsensusCalculator` doit être testé sur :
1. **Cas du sujet** : entrées 24/25/26/5/70 (4 modèles cohérents + 1
   outlier) → A doit être attiré vers 28, B et C doivent rejeter 70
   et tomber proches de 25.
2. **Cas dégénéré** : tous modèles identiques → A = B = C = la valeur.
3. **Direction qui chevauche Nord** : modèles 350/355/5/10 → consensus
   ≈ 0°, pas ≈ 180°.
4. **Cold start fiabilité** : `model_reliability` vide → C ≡ B (les
   poids relatifs sont tous à 1.0).
5. **MAD plancher** : 5 modèles donnant exactement 25 + 1 outlier à
   25.1 → MAD raw ≈ 0, MAD floor garantit que le filtrage ne dégénère pas.

### Découpage en commits (4 commits ciblés)

1. **Schéma + settings + toggle admin** : migrations `model_reliability`
   (ajout variable), `balise_consensus_compare`, ajout du booléen
   `balises.in_consensus_compare_panel`, nouvelles clés settings, et
   toggle dédié sur la fiche `/admin/balises/{id}` (méthode
   `toggleComparePanel` sur `Admin\BaliseController`, route POST,
   bouton à côté du toggle « actif »).
2. **Services + tests** : `ConsensusCalculator` (3 variantes linéaires +
   3 variantes circulaires), `ReliabilityCalculator` (MAE/RMSE/bias/weight_factor),
   `BaliseConsensusCompareService` (orchestrateur), tests unitaires.
3. **Jobs + scheduling** : `ComputeBaliseConsensusCompareJob`,
   `ComputeModelReliabilityJob`, schedule dans `routes/console.php`.
4. **Admin** : écrans `compare` + `models`, onglets settings,
   réorganisation sidebar.

### Estimation

| Lot | Estimation |
|-----|-----------|
| Schéma + settings + seeder | 0,4 j |
| `ConsensusCalculator` (3 variantes linéaires + 3 circulaires) | 1,2 j |
| `ReliabilityCalculator` (par variable) | 0,6 j |
| `BaliseConsensusCompareService` + jobs | 0,6 j |
| Tests unitaires (5 scénarios) | 0,6 j |
| Écran `compare` (J / J+1 / J+2) | 1,0 j |
| Écran `models` + onglets settings | 0,8 j |
| Sous-total **phase 2.5** | **~5,2 j** |

### Critère de réussite (avant phase 4)

Après 2 à 3 semaines d'observation :
- MAE de **B** doit être ≤ MAE de **A** sur au moins **2 variables sur
  3** et au moins **2 buckets sur 4**, **pour au moins 2 balises sur 3**.
- MAE de **C** doit être strictement ≤ MAE de **B** sur ≥ 1 variable.
- Aucun cas où **C** explose (MAE > 1.5 × MAE de A) — indicateur d'un
  bug ou d'une instabilité de fiabilité à corriger avant phase 4.

Si la phase 2.5 ne valide pas ces critères : on ré-analyse (paramètres
mal réglés, données insuffisantes, méthode inadaptée à certains
régimes) avant tout branchement en prod.

---

## Alternatives écartées

### Remplacement total des poids statiques par les poids dynamiques
**Écarté** : perd la connaissance métier accumulée dans
`weather_models.weight_short/medium` (ECMWF prior > BOM en Europe).
Risque qu'un modèle "chanceux" sur 7 jours monopolise le consensus.

### Pondération additive (`poids = statique + ajustement`)
**Écarté** : moins intuitif à régler, sensible aux échelles. Le
multiplicatif borné [0.25, 2.0] est plus naturel.

### Stocker la fiabilité dans `weather_models` directement
**Écarté** : `weather_models` représente la connaissance statique du
modèle (nom, provider, prior). Mélanger avec une métrique calculée à
chaque run rend la lecture des deux concepts confuse et empêche
l'évolution indépendante (ex. plusieurs jeux de fiabilité par
région ?). Table dédiée = séparation propre.

### Calcul de fiabilité au niveau régional plutôt que par balise
**Écarté** pour phase 2 : on commence par le niveau de granularité le
plus fin (par balise) et on agrège vers le site en phase 4 (IDW).
Permet de garder l'information de "qualité locale" qu'on perdrait en
moyennant directement à l'échelle régionale.

### Fréquence de recalcul : horaire vs 3 h vs quotidien
**Retenu : 3 h.** Justification : la fenêtre glissante 7 j a beaucoup
d'inertie (168 observations par bucket), donc recalculer plus souvent
ne change pas matériellement les classements. Mais 3 h reste assez
réactif pour détecter un modèle qui dérive (mise à jour ratée chez un
fournisseur) en quelques heures.

### Garder l'archivage actuel sans changement
**Retenu en V1.** Le biais "bord proche du bucket" est documenté
(section *Pré-existant*). On verra à l'usage si la fiabilité
discrimine correctement les modèles. Si non, on rebascule sur l'une
des pistes (upsert inverse, horizons discrets, historique complet).

---

## Bornes / garde-fous

| Borne | Valeur | Justification |
|-------|--------|---------------|
| `factor_min` | `0.25` | Empêche un modèle d'être complètement réduit au silence. Préserve la diversité de l'ensemble. |
| `factor_max` | `2.0` | Empêche un modèle "lucky" de dominer. Le consensus reste pluriel. |
| `min_samples` | `50` | Seuil de confiance. En-dessous, on ne juge pas — retour aux poids statiques. 50 obs sur 7 j = ~1 obs/3 h en moyenne, raisonnable pour un bucket donné. |
| Fenêtre 7 j | constante | Cohérent avec la rétention de `balise_readings_hourly`. Plus long = inertie excessive, plus court = bruit. |
| Cold start | tous les `weight_factor = 1.0` | Si TOUS les modèles ont samples_n < min_samples sur un bucket donné, on neutralise le système pour ce bucket. La voting logic fonctionne sur les poids statiques. |
| Recalcul d'urgence | bouton admin | "Recalculer maintenant" depuis `/admin/model-reliability`, dispatch sync, pour ne pas attendre 3 h après un changement de coefficient α. |

---

## Estimation de coût

| Lot | Estimation |
|-----|-----------|
| **Phase 2 — calcul + admin** | |
| Migration `model_reliability` + modèle Eloquent | 0,3 j |
| Settings clés `reliability.*` + seeder | 0,2 j |
| Job `ComputeModelReliabilityJob` (jointures, agrégations, median, clamp) | 1,0 j |
| Réorganisation `/admin/settings` en onglets + onglet *Fiabilité* | 0,5 j |
| Écran `/admin/model-reliability` (tableau pivot + bouton recalcul) | 1,0 j |
| Tests unitaires (formules, garde-fous, cold start) | 0,5 j |
| Sous-total phase 2 | **~3,5 j** |
| **Phase 3 — UI utilisateur (onglet balise)** | |
| API `/api/balises/{id}/comparison` | 0,5 j |
| Refactor `right-panel.blade.php` pour supporter 2 onglets | 0,3 j |
| 3 graphes SVG (dir / vit. moy / rafale) + légende cliquable | 1,5 j |
| Badge "fiabilité du modèle X à cet horizon" (optionnel) | 0,3 j |
| Sous-total phase 3 | **~2,5 j** |
| **Phase 4 — intégration voting logic** | |
| IDW balise→site + paramètres rayon/Δalt | 0,5 j |
| Modification `ScoringService` (poids dynamiques par horizon) | 1,0 j |
| Tests non-régression scoring + recalcul backfill | 0,5 j |
| Sous-total phase 4 | **~2,0 j** |
| **Total feature complète** | **~8 j** |

---

## Ordre de découpage suggéré (en 5 PR)

1. ✅ **Phase 1 — Infra collecte** *(livrée)* : agrégation horaire des
   lectures balises. Pré-requis pour tout le reste.
2. **Phase 2 — Calcul de fiabilité + admin** : table
   `model_reliability` **(version par variable)**, job toutes les 3 h,
   settings + onglet admin, écran de surveillance. **Visible côté
   admin uniquement**, aucun impact sur le consensus utilisateur.
   Permet de vérifier la pertinence des chiffres et de calibrer les
   coefficients α.
3. **Phase 2.5 — Triple-consensus shadow** *(en cours)* : table
   `balise_consensus_compare`, `ConsensusCalculator` à 3 variantes,
   écran admin de comparaison J/J+1/J+2 vs balise. **Aucun impact sur
   la prod** — sert à valider chiffres en main que B et C font mieux
   que A. Conditionne la phase 4.
4. **Phase 3 — UI utilisateur** : l'onglet "Comparaison modèles" dans
   le panneau droit balise (3 graphes). Indépendant de la phase 2 sur
   le plan technique (peut s'appuyer sur les données brutes), mais
   gagne en richesse si la phase 2 est déjà là (affichage des
   `weight_factor` à côté de chaque modèle dans la légende).
5. **Phase 4 — Intégration consensus** : mapping IDW balise→site,
   modification de `ScoringService` pour utiliser les `weight_factor`.
   À ne déclencher que **quand les critères de réussite de la phase 2.5
   sont remplis** sur 2–3 semaines d'observation — c'est l'étape qui
   modifie les résultats vus par les utilisateurs.

Entre phase 2.5 et phase 4 : laisser tourner **au moins 2–3 semaines**
pour accumuler de la donnée représentative et observer l'écran de
comparaison. Pas d'intérêt à brancher la voting logic avec des
chiffres encore instables.

---

## Risques résiduels

1. **Biais "bord proche du bucket"** *(déjà documenté)* — le bucket
   `j_plus_2` stocke une prédiction faite ~49 h avant la cible, pas
   ~72 h. Si la discrimination par horizon est faible (tous les buckets
   donnent des classements similaires), c'est probablement la cause. À
   corriger via l'une des pistes listées.
2. **Faible diversité des balises** — si on n'a que 5 balises dont 3
   en plaine et 2 en relief moyen, la fiabilité ne reflètera pas la
   complexité montagne. La phase 4 (IDW) sera limitée par cette
   distribution géographique. Mitigation : suivre l'intégration de
   nouvelles balises FFVL / piouPiou en parallèle.
3. **Cycle de feedback dégénéré** — si la phase 4 amplifie un modèle
   qui était bien à J-2 sur les balises mais terrible aux sites
   réels, les utilisateurs verront des consensus dégradés. Mitigation :
   ne jamais faire taire un modèle (`factor_min = 0.25`), garder un
   panneau de surveillance lisible, prévoir un kill switch (clé
   `reliability.enabled = true/false`) qui retourne aux poids
   statiques instantanément.
4. **Coefficients α mal réglés** — si les défauts (0.20 / 0.35 / 0.35
   / 0.10) ne reflètent pas l'importance opérationnelle (rafale
   probablement plus critique que vitesse moyenne pour le statut
   "volable"), les classements seront biaisés. Mitigation : les α sont
   admin-éditables, l'écran de surveillance permet de tester des
   réglages et l'option "recalculer maintenant" évite d'attendre 3 h.
5. **Pannes de balises non détectées** — si une balise tombe en panne
   pendant 3 jours, `samples_n` baisse sur cette balise et le système
   se rabat sur le statique (garde-fou cold start). C'est OK, le
   système se désactive de lui-même quand la donnée manque. À
   surveiller : pas d'effet de bord sur les autres balises.
6. **Charge SQL du job** — la jointure tourne sur ~7 × 24 × N_balises ×
   N_modèles lignes. À 50 balises × 10 modèles = 84 000 lignes par
   bucket, 336 000 lignes au total. Indexable sur `target_at` et
   `(balise_id, hour_at)`. À surveiller au moment de l'implémentation
   — si la jointure dépasse 30 s, paginer par balise.
7. **Représentativité du panel phase 2.5** — 3 balises (Samoëns,
   Puncho d'Agast, Grand Est) c'est variétal mais étroit. Un consensus
   B qui gagne sur ces 3 ne gagnera pas forcément ailleurs (régimes
   littoraux, hauts plateaux). Mitigation : la phase 2.5 valide
   l'approche **statistique** (médiane pondérée + MAD + EPSILON), pas
   la généralisation géographique — ça reste à confirmer lors du
   rollout phase 4 site par site. Possibilité d'élargir le panel
   `reliability_class = 'A'` à 5–6 balises si on observe une variance
   forte entre les 3 initiales.
8. **Médiane circulaire pondérée — approximation par résultante
   vectorielle** — convertit chaque θ_i en (sin/cos) pondéré et prend
   l'angle de la résultante. C'est exact pour des données unimodales
   serrées (cas dominant) mais peut sous-estimer la dispersion en cas
   de bimodalité forte (rare : vent qui bascule en milieu de créneau,
   typiquement non capturé par les modèles globaux). Mitigation :
   logger les cas où la **résultante a une norme < 0.5** (signal de
   forte dispersion / bimodalité) et basculer manuellement sur une
   méthode itérative si nécessaire. À surveiller dans l'écran de
   comparaison via la colonne `mad_value`.

---

## Pistes d'optimisation (si scaling du panel)

Les temps mesurés en prod au déploiement (2026-05-18, 3 balises) :

| Job | Durée par balise | Tuples upsertés |
|-----|------------------|-----------------|
| `reliability:compute-compare` (fenêtre 72 h) | ~5-15 s | ~1100-1300 |
| `reliability:compute-factors` (fenêtre 7 j) | ~10-30 s | ~170 |

À 3 balises, c'est confortable. Si le **panel de validation** devait
être étendu un jour (50, 100 balises pour un mapping régional fin
avant phase 4), la charge croîtrait **linéairement** et dépasserait
le timeout du job horaire à ~100 balises.

Le panel n'a **a priori pas vocation à dépasser 5-10 balises** — c'est
un panel statistique, pas opérationnel. Mais si jamais le besoin
émerge, voici les 5 optimisations possibles par ordre d'impact, à
attaquer dans l'ordre :

1. **Bulk `upsert()` au lieu de `updateOrCreate()` en boucle** —
   gain estimé **×10 à ×50**. Aujourd'hui chaque ligne fait 2 requêtes
   SQL (SELECT + UPDATE/INSERT) ; un `DB::table()->upsert()` Laravel
   sur tableau de 1500 lignes en fait une seule. Modif : ~1 h de
   refactoring sur `BaliseConsensusCompareService::computeForBalise()`
   et `ReliabilityCalculator::recomputeForBalise()`. **Suffit dans
   90 % des cas**.
2. **Job parallèle via `Bus::batch`** — gain proportionnel au nombre
   de workers Redis queue. Aujourd'hui `ComputeBaliseConsensusCompareJob`
   traite les balises en séquence ; refactor en sous-jobs (1 par balise)
   dispatchés en batch absorbé par N workers. À 4 workers → ÷4 du temps
   d'horloge. Pattern déjà utilisé par `FetchForecastsJob` en prod.
3. **Index DB ciblé** sur `forecast_archive_balises (balise_id, target_at,
   horizon_bucket)` et sur `balise_readings_hourly (balise_id, hour_at)` —
   gain ×2 à ×3 sur les SELECT, surtout avec des fenêtres élargies.
4. **SQL agrégé pour les vitesses** dans
   `ReliabilityCalculator::recomputeForBalise()` — gain ×3 à ×5. Au lieu
   de charger toutes les paires en PHP, calculer
   `AVG(ABS(pred - obs))` directement en SQL pour les vitesses (la
   direction reste en PHP à cause de la circularité). MariaDB gère ça
   très bien.
5. **Calcul différentiel** (job horaire uniquement) — gain massif (×30+).
   Recalculer seulement les créneaux nouveaux/modifiés depuis le dernier
   run au lieu de re-traiter la fenêtre ±72 h complète à chaque tick. Le
   job quotidien `compute-factors` reste full-scan (acceptable car
   1 fois/jour à 03:30).

Note : la **phase 4** (intégration `weight_factor` dans `ScoringService`)
ne change rien à la charge du shadow. Elle ajoute juste un lookup
en mémoire dans le scoring (déjà géré par
`ReliabilityCalculator::weightFactorsBatch()`) — coût négligeable, ne
scale pas avec le nombre de balises mais avec le nombre de sites.

---

## Pour aller plus loin (hors scope V1)

- **Stratification par condition météo** : un modèle peut être bon en
  régime anticyclonique mais mauvais en front. Calculer la fiabilité
  séparément par type de masse d'air / vitesse moyenne / saison.
  Volume de données nécessaire bien plus important.
- **Fiabilité par paramètre, pas seulement combinée** : appliquer un
  `weight_factor` différent par variable (ex. modèle X très bon en
  direction, moyen en rafale → on l'utilise pour la direction dans le
  consensus mais on baisse son poids pour la rafale). Plus précis,
  plus complexe à câbler dans `ScoringService`.
- **Détection de panne modèle** : alerter automatiquement quand un
  `err_combined` saute brutalement (×3 d'une semaine à l'autre) — c'est
  probablement le signe d'une mise à jour ratée chez le fournisseur.
- **Export des séries d'erreur** : permettre à un admin de télécharger
  les paires (prévu, observé) en CSV pour analyses externes (R / Python).
- **Backfill de calcul** : commande artisan pour recalculer la
  fiabilité sur une période passée (utile après un changement de
  formule, sans attendre 3 h de cycle naturel).
- **Tableau de bord public** : page publique "fiabilité de nos
  modèles" — argument transparence vis-à-vis de la communauté
  parapente, montre qu'on mesure ce qu'on annonce.
