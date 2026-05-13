# FF — Fiabilité dynamique des modèles météo

> **Statut** : phase 1 livrée (collecte) — phases 2 à 4 à planifier.
> **Date de rédaction** : 2026-05-13
> **Pré-requis bloquant phase 2** : ≥ 7 jours de données accumulées dans
> `forecast_archive_balises` et `balise_readings_hourly`.

Ce document consigne la discussion de cadrage. Il sert de point de
reprise quand on décidera d'implémenter les phases suivantes.

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

## Ordre de découpage suggéré (en 4 PR)

1. ✅ **Phase 1 — Infra collecte** *(livrée)* : agrégation horaire des
   lectures balises. Pré-requis pour tout le reste.
2. **Phase 2 — Calcul de fiabilité + admin** : table
   `model_reliability`, job toutes les 3 h, settings + onglet admin,
   écran de surveillance. **Visible côté admin uniquement**, aucun
   impact sur le consensus utilisateur. Permet de vérifier la
   pertinence des chiffres et de calibrer les coefficients α.
3. **Phase 3 — UI utilisateur** : l'onglet "Comparaison modèles" dans
   le panneau droit balise (3 graphes). Indépendant de la phase 2 sur
   le plan technique (peut s'appuyer sur les données brutes), mais
   gagne en richesse si la phase 2 est déjà là (affichage des
   `weight_factor` à côté de chaque modèle dans la légende).
4. **Phase 4 — Intégration consensus** : mapping IDW balise→site,
   modification de `ScoringService` pour utiliser les `weight_factor`.
   À ne déclencher que **quand les chiffres de la phase 2 sont validés**
   — c'est l'étape qui modifie les résultats vus par les utilisateurs.

Entre phase 2 et phase 4 : laisser tourner **au moins 2–3 semaines**
pour accumuler de la donnée représentative et observer l'écran de
surveillance. Pas d'intérêt à brancher la voting logic avec des
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
