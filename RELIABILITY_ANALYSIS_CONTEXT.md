# Contexte d'analyse — Fiabilité dynamique des modèles météo (phase 2.5)

> **À uploader avec un export JSON de `/admin/reliability/export.json`
> pour qu'un Claude analyste puisse fournir une lecture pertinente sans
> contexte préalable.**
>
> Version du schéma : **1**.
> Dernière revue : **2026-05-18**.

---

## 1. Le projet en 30 secondes

**Qui Vole ?** est une plateforme web nationale pour pilotes de parapente
(stack Laravel 13 / MariaDB / Redis). Elle calcule pour chaque site de vol,
heure par heure, un **statut de volabilité** (vert / orange / rouge) basé
sur le consensus multi-modèles d'une vingtaine de modèles météo NWP.

L'algorithme de **consensus** (voting logic) agrège ces N modèles en une
valeur unique par variable (vent moyen, rafales, direction, …) à chaque
créneau horaire de la fenêtre 5 jours à venir. C'est cette valeur qui
déclenche le statut.

Le code de prod actuel utilise un algorithme **legacy** datant du V1 du
projet. La **phase 2.5** vise à le challenger en shadow mode (sans
toucher à la prod) avec deux algorithmes améliorés, et à mesurer
objectivement leur gain contre la vérité-terrain de quelques balises.

Documentation complète du cadrage : [`FF_model_reliability.md`](FF_model_reliability.md).

---

## 2. Phase 2.5 — Le shadow mode triple-consensus

### Principe

Pour 3 balises de référence (« panel ») marquées manuellement dans
l'admin (drapeau `in_consensus_compare_panel`), un job horaire calcule
**trois consensus en parallèle** pour chaque créneau et chaque variable.

| Algo | Description | But |
|------|-------------|-----|
| **A — legacy** | Inverse-carré linéaire, EPSILON = 0.001 (vitesses) ; moyenne circulaire pondérée (direction). **Reproduit fidèlement le scoring de prod**. | Baseline de référence |
| **B — amélioré sans fiabilité** | EPSILON = 1.0 (paramétrable), **médiane pondérée** (paramétrable), **filtrage MAD** intra-créneau (paramétrable). Tous les modèles à poids initial 1.0. | Mesure le gain pur des améliorations statistiques |
| **C — amélioré avec fiabilité** | Identique à B, plus la pondération initiale par `weight_factor` issu de la table `model_reliability`. | Mesure le gain additionnel de la pondération dynamique |

Les 3 sont confrontés à la **vérité terrain** : la lecture de la balise
agrégée à l'heure pile, depuis `balise_readings_hourly`.

### Variables

3 variables sont mesurées :

- `wind_speed_avg` : vent moyen en km/h (linéaire)
- `wind_speed_max` : rafale en km/h (linéaire)
- `wind_direction` : direction du vent en degrés [0, 360[ (**circulaire**)

### Horizons (buckets)

Les modèles fournissent une prévision pour chaque heure à venir jusqu'à
J+5. On découpe en 4 buckets selon l'avance de la prévision sur la cible :

| Bucket | Délai prévu → cible |
|---|---|
| `nowcast`  | 0 à 6 h  |
| `same_day` | 6 à 24 h |
| `j_plus_1` | 24 à 48 h |
| `j_plus_2` | 48 à 72 h |

> **Biais d'archivage important** : la table source est ré-écrite à chaque
> heure pendant que le bucket est ouvert, donc la valeur stockée
> correspond à la prévision faite **au bord proche** du bucket. Ex.
> `j_plus_2` capture en pratique la prédiction faite ~49 h avant la cible,
> pas 72 h. Acceptable en V1 pour distinguer 4 horizons, mais à garder en
> tête. Cf. *Limites* § 6.

### Pondération dynamique (`weight_factor`)

Chaque modèle se voit attribuer un multiplicateur par
`(balise × bucket × variable)`, calculé quotidiennement (job à 03h30) sur
une fenêtre glissante de 7 jours :

```
mae_modèle  = AVG(erreur absolue prévu vs observé)  sur les ~120 dernières paires
mae_médiane = médiane des MAE des modèles éligibles (samples_n ≥ 50)
weight_factor = clamp(mae_médiane / mae_modèle, 0.25, 2.0)
```

Un modèle 2× meilleur que la médiane → facteur 2.0 (plafond).
Un modèle 4× pire → facteur 0.25 (plancher).

**Garde-fou cold start** : si `samples_n < 50` (réglable), `weight_factor = 1.0`
(neutre, on n'a pas assez de données pour juger).

---

## 3. Schéma de l'export JSON

```jsonc
{
  "exported_at": "2026-05-18T15:30:00+00:00",   // ISO 8601, UTC
  "schema_version": 2,                          // v2 ajoute la section horizon_mae

  // ── Paramètres en vigueur lors de l'export ──────────────────
  "parameters": {
    "reliability_settings": {
      "reliability.shadow_enabled":         true,
      "reliability.epsilon_new":            1.0,
      "reliability.mad_floor":              0.5,
      "reliability.z_outlier_threshold":    3.0,
      "reliability.dir_mad_z_threshold":    3.0,
      "reliability.use_weighted_median":    true,
      "reliability.use_mad_filtering":      true,
      "reliability.min_samples":            50,
      "reliability.factor_min":             0.25,
      "reliability.factor_max":             2.0,
      "reliability.window_days":            7
    },
    "app_timezone":                  "UTC",
    "last_reliability_computed_at":  "2026-05-18 03:30:14",
    "last_compare_computed_at":      "2026-05-18 15:10:22"
  },

  // ── Panel de balises actives dans la comparaison ────────────
  "panel": [
    {"id":12,"name":"Jouy sous les côtes","source":"pioupiou",
     "reliability_class":"pro","latitude":48.85,"longitude":5.45,"altitude_m":350}
  ],

  // ── Modèles météo actifs (référence pour weather_model_id) ──
  "models": [
    {"id":1,"code":"ukmo_global","name":"UKMO Global","provider":"open-meteo"}
  ],

  // ── Stats agrégées par variable × bucket ────────────────────
  // ATTENTION : "n" et MAE peuvent être calculés sur des ensembles
  // d'observations DIFFÉRENTS entre buckets (un même target_at n'est
  // pas forcément présent dans les 4 buckets). Pas apple-to-apple.
  // Pour une comparaison stricte entre buckets, voir horizon_mae.
  "stats": {
    "wind_speed_avg": {
      "nowcast":  {"n":216,"mae_a":2.88,"mae_b":2.93,"mae_c":2.93},
      "same_day": {"n":216,"mae_a":2.83,"mae_b":2.86,"mae_c":2.86},
      "j_plus_1": {"n":216,"mae_a":2.95,"mae_b":2.96,"mae_c":2.96},
      "j_plus_2": {"n":204,"mae_a":2.84,"mae_b":2.85,"mae_c":2.85}
    },
    "wind_speed_max": {...},
    "wind_direction": {...}     // MAE circulaire en degrés
  },

  // ── MAE par horizon, par balise × variable (schema v2) ──────
  // Pour chaque (balise × variable), 2 jeux de MAE :
  //   - "common" : strictement les target_at présents dans les 4
  //     buckets avec observation → comparaison apple-to-apple
  //   - "full" : MAE sur l'ensemble complet du bucket (utile en
  //     référence, idem que la section `stats` ci-dessus)
  // Voir aussi écran /admin/reliability/horizon.
  "horizon_mae": {
    "12": {                                       // balise_id (Jouy)
      "wind_speed_avg": {
        "common_targets_count": 47,               // nb de target_at dans les 4 buckets
        "common": {
          "nowcast":  {"n":47,"mae_a":2.51,"mae_b":2.49,"mae_c":2.51},
          "same_day": {"n":47,"mae_a":2.83,"mae_b":2.85,"mae_c":2.84},
          "j_plus_1": {"n":47,"mae_a":3.10,"mae_b":3.12,"mae_c":3.11},
          "j_plus_2": {"n":47,"mae_a":3.36,"mae_b":3.39,"mae_c":3.40}
        },
        "full": {
          "nowcast":  {"n":216,"mae_a":2.88,"mae_b":2.93,"mae_c":2.93,"target_count":216},
          // ... idem stats[][] mais avec target_count en plus
        }
      },
      "wind_speed_max": {...},
      "wind_direction": {...}
    },
    "102": {...},                                 // autres balises du panel
    "216": {...}
  },

  // ── Dataset 1 : tuples consensus vs observation ─────────────
  "consensus_compare": [
    {
      "balise_id": 12,
      "target_at": "2026-05-18 13:00:00",   // heure pile cible (UTC)
      "horizon_bucket": "nowcast",
      "variable": "wind_speed_avg",
      "consensus_a": 9.7,         // km/h (linéaire) ou degrés (direction)
      "consensus_b": 9.7,
      "consensus_c": 9.7,
      "observation": 10.1,        // null si créneau futur ou balise muette
      "observation_count": 12,    // nb de readings agrégés à l'heure
      "models_count": 15,         // nb de modèles ayant contribué
      "mad_value": 1.4,           // MAD intra-créneau (en km/h ou degrés)
      "computed_at": "2026-05-18 15:10:22"
    },
    // ...
  ],

  // ── Dataset 2 : fiabilité par (modèle × balise × bucket × var) ─
  "model_reliability": [
    {
      "weather_model_id": 1,
      "balise_id": 12,
      "horizon_bucket": "nowcast",
      "variable": "wind_speed_avg",
      "mae": 3.05,                // erreur absolue moyenne
      "rmse": 3.90,               // erreur quadratique moyenne (pénalise les gros écarts)
      "bias_signed": 1.07,        // biais (prévu - observé), positif = sur-estimation
      "weight_factor": 1.32,      // multiplicateur (clamp 0.25-2.0)
      "samples_n": 86,            // nb de paires sur la fenêtre 7j
      "computed_at": "2026-05-18 03:30:14"
    },
    // ...
  ]
}
```

### Types et conventions

- **Toutes les datetime sont en UTC** (config Laravel `timezone = UTC`).
  Conversions vers heure locale Europe/Paris à la lecture si besoin.
- **Direction** : `[0, 360[` degrés, **convention météo « FROM »**
  (90° = vent venant de l'Est, etc.).
- **Vitesses** : km/h. Décimales à 0.1.
- **Distance circulaire** (utilisée pour la MAE direction) :
  `min(|a-b|, 360-|a-b|)`, dans `[0, 180]`.

---

## 4. Glossaire

| Terme | Définition |
|---|---|
| **Consensus** | Valeur unique agrégée à partir des N modèles pour un créneau et une variable. |
| **MAE** | *Mean Absolute Error*. Moyenne des écarts absolus prévu-observé. Plus bas = mieux. Unités : km/h (vitesse) ou degrés (direction). |
| **RMSE** | *Root Mean Square Error*. Idem MAE mais pénalise les gros écarts (carré). Toujours ≥ MAE. |
| **Bias signé** | Moyenne des écarts SIGNÉS prévu-observé. Positif = modèle sur-estime, négatif = sous-estime. |
| **Weight_factor** | Multiplicateur de pondération d'un modèle. 1.0 = neutre, > 1 = on l'écoute plus, < 1 = on l'écoute moins. Clampé `[0.25, 2.0]`. |
| **Samples_n** | Nombre de paires (prévu, observé) ayant servi à calculer la MAE et le weight_factor. |
| **MAD** | *Median Absolute Deviation*. Médiane des écarts absolus à la médiane d'un échantillon. Mesure robuste de dispersion. |
| **EPSILON** | Constante ajoutée au carré dans la pondération inverse-carré, pour éviter la division par zéro. Plus grande = effet de l'outlier plus lissé. |
| **Bucket / horizon** | Intervalle de délai entre la prévision et le créneau cible. 4 valeurs : nowcast, same_day, j_plus_1, j_plus_2. |
| **Cold start** | Période initiale où il n'y a pas assez de données (`samples_n < min_samples`) pour juger un modèle. Pendant cette période, son `weight_factor` reste à 1.0. |
| **Panel** | Liste de balises de référence sélectionnées pour la comparaison. Drapeau `in_consensus_compare_panel` sur la table `balises`. |

---

## 5. Comment interpréter — ordres de grandeur attendus

### Ordres de grandeur typiques

Sur le panel actuel (3 balises FR/zones variées), 1-2 semaines de données :

| Variable | Bucket | MAE typique | Note |
|---|---|---|---|
| `wind_speed_avg` | nowcast    | 2-3 km/h     | Bonne capture |
| `wind_speed_avg` | j_plus_2   | 3-4 km/h     | Dégradation horizon |
| `wind_speed_max` | nowcast    | 4-6 km/h     | Plus dur (rafales = pics) |
| `wind_speed_max` | j_plus_2   | 5-7 km/h     | |
| `wind_direction` | nowcast    | 25-35°       | Convention circulaire ! |
| `wind_direction` | j_plus_2   | 30-45°       | |

> Si tu vois des MAE direction > 60°, vérifie que tu utilises bien la
> distance circulaire `min(|a-b|, 360-|a-b|)` et pas un `abs(a-b)` brut.

### Signaux à chercher

- **MAE par variable et bucket** : c'est LA stat de base. Permet de
  comparer A / B / C en moyenne.
- **MAE par balise** : un modèle peut bien marcher en plaine et mal en
  montagne. Comparer Jouy (plaine Grand Est) vs Brunas (Causses) vs
  Windbird (Alpes).
- **Bias signé** : un modèle qui sur-estime systématiquement la rafale
  est dangereux (faux orange). Un modèle qui sous-estime systématiquement
  est risqué (faux vert).
- **Variance des `weight_factor`** : si tous sont à 1.0, c'est qu'on est
  en cold start ou que tous les modèles se valent. Si le spread est
  large (0.5-1.5), la pondération dynamique a un effet réel.
- **MAE comme fonction du nombre d'observations** : sur 50 obs, la MAE
  a un écart-type de ~0.5 km/h. Sur 500 obs, ~0.15 km/h. Donc un écart
  A vs B de 0.05 km/h sur 100 obs n'est pas significatif statistiquement.

---

## 6. Limites et pièges connus

### 1. Asymétrie de la rafale

`wind_speed_max` est par nature asymétrique : la rafale n'est pas la
moyenne, c'est un pic. Une distribution typique pour un créneau :
12 modèles voient 15-18 km/h, 1 modèle voit 35 km/h. Le **filtrage MAD**
de B/C écarte ce modèle comme outlier. Or il a peut-être raison.

→ Si tu vois B systématiquement sous-estimer `wind_speed_max` vs A, **c'est probablement ça**. Solutions :
- Désactiver `use_mad_filtering` (test)
- Augmenter `z_outlier_threshold` (3 → 4 ou 5)
- Filtrer asymétrique (à coder — pas en place)

### 2. Médiane circulaire approximée

Pour la direction, la « médiane pondérée circulaire » est mathématiquement
non triviale. L'implémentation utilise la **résultante vectorielle pondérée**
(somme des sin/cos pondérés, atan2). C'est en réalité une **moyenne**
circulaire pondérée, pas une vraie médiane.

→ Robuste pour des distributions unimodales (cas dominant). Peut sous-estimer
la dispersion en cas de bimodalité forte (vent qui bascule en milieu de
créneau). À surveiller via `mad_value` : si grand (> 30°), la résultante
peut être trompeuse.

### 3. Biais d'archivage horizon

La table source `forecast_archive_balises` est ré-écrite à chaque heure
pendant que la prévision est dans son bucket d'horizon. Conséquence : la
valeur stockée capture la **dernière** prévision (= au bord proche du
bucket), pas la première.

| Bucket | Délai prévu→cible nominal | Délai effectif en base |
|---|---|---|
| `nowcast`  | 0-6 h | ~0 h |
| `same_day` | 6-24 h | ~6 h |
| `j_plus_1` | 24-48 h | ~24 h |
| `j_plus_2` | 48-72 h | ~48 h |

→ Quand on dit « fiabilité à J+2 », c'est en réalité **fiabilité à ~J+2
moins 24h**. Différence acceptable en V1 pour distinguer 4 horizons, à
réécrire si on a besoin de granularité fine.

### 4. Panel restreint

3 balises (Jouy / Brunas / Windbird) couvrent : plaine Grand Est,
Causses, Alpes. C'est variétal mais étroit. Un consensus B qui gagne
sur ces 3 ne gagnera pas forcément ailleurs (littoral, hauts plateaux).

→ La phase 2.5 valide l'approche **statistique** (médiane + MAD + EPSILON),
pas la généralisation géographique. Si on étend le panel à 5-6 balises,
on peut juger plus large.

### 5. Cold start de `model_reliability`

Tant que `samples_n < reliability.min_samples` (50 par défaut), le
`weight_factor` reste à 1.0 → **C ≡ B** sur ce tuple. C'est normal et
attendu pendant les premiers ~3-7 jours après mise en place.

→ Vérifie systématiquement la **distribution de `samples_n`** dans
`model_reliability`. Si la majorité est sous 50, on est en cold start.

---

## 7. Paramètres ajustables — impact

Tous éditables dans `/admin/settings` (section « Fiabilité des modèles »),
prennent effet à l'écriture (cache Redis invalidé).

| Paramètre | Défaut | Impact si on monte | Impact si on baisse |
|---|---|---|---|
| `reliability.shadow_enabled` | true | — | **Kill switch** : le job ne calcule plus rien |
| `reliability.epsilon_new` | 1.0 | B et C lissent plus, l'effet de la médiane domine | B et C deviennent plus sensibles aux valeurs proches de la médiane (vers A) |
| `reliability.mad_floor` | 0.5 km/h | Moins d'outliers écartés (plancher plus haut → MAD plus large → z plus petit) | Plus d'outliers écartés |
| `reliability.z_outlier_threshold` | 3.0 | Moins de filtrage MAD → B se rapproche de l'algo sans filtrage | Plus de filtrage agressif → B peut sous-estimer (cf. piège rafale) |
| `reliability.dir_mad_z_threshold` | 3.0 | Idem direction | Idem direction |
| `reliability.use_weighted_median` | true | — | Bascule en moyenne pondérée (effet du weight_factor plus visible mais sensible aux outliers résiduels) |
| `reliability.use_mad_filtering` | true | — | Aucun filtrage MAD. Outliers gardés. |
| `reliability.min_samples` | 50 | Plus de modèles en cold start | Moins de modèles neutres, weight_factor effectif plus tôt |
| `reliability.factor_min` | 0.25 | Plancher remonte → modèles « mauvais » moins muselés | Plancher descend → modèles mauvais davantage écrasés |
| `reliability.factor_max` | 2.0 | Plafond remonte → modèles « bons » sur-pondérés | Plafond descend → consensus plus diplomate |
| `reliability.window_days` | 7 | Fenêtre plus large, plus stable, moins réactive | Fenêtre plus courte, plus réactive aux dérives |

---

## 8. Critères de validation pour passer phase 4

Avant de brancher les `weight_factor` dans le `ScoringService` de prod
(= modifier ce que voient les utilisateurs), on attend que les critères
suivants soient remplis sur **≥ 2 semaines** de données :

| Critère | Seuil |
|---|---|
| **MAE B ≤ MAE A** sur 2 var sur 3, 2 buckets sur 4, 2 balises sur 3 | Gain statistique généralisé |
| **MAE C ≤ MAE B** sur au moins 1 variable | Bénéfice additionnel de la fiabilité |
| **Aucun cas C > 1.5 × MAE A** | Pas d'instabilité catastrophique |
| **Variance des `weight_factor`** > 0.3 sur au moins 2 buckets | Le système discrimine réellement |
| Pas de modèle systématiquement au plancher 0.25 sur tous les buckets | Ce serait un signal de virer ce modèle plutôt que de le museler |

Si **un de ces critères** n'est pas rempli, on **n'enchaîne pas** sur la
phase 4. On ajuste les paramètres et on collecte 1-2 semaines de plus.

---

## 9. Questions-types à poser à Claude analyste

Quelques pistes d'analyse utiles à demander :

1. **Stat de base** : « MAE globale par algo, variable et bucket. Quel
   algo gagne où ? Quel est l'écart significatif (> 0.2 km/h vs bruit
   statistique) ? »
2. **Par balise** : « Refais la stat par balise. Une balise tire-t-elle
   la moyenne ? Un algo gagne-t-il partout, ou seulement à certains
   endroits ? »
3. **Bias dérive** : « Liste les 5 modèles avec le plus gros bias signé
   absolu, par variable et bucket. Qui sur/sous-estime systématiquement ? »
4. **Cold start** : « Distribution de samples_n. Combien de tuples sont
   en cold start (`weight_factor = 1.0`) ? Si > 50 %, on doit attendre. »
5. **Effet weight_factor** : « Pour les tuples où C ≠ B, montre la
   distribution de l'amélioration. C tire-t-il vers l'observation
   (erreur réduite) ou s'en éloigne-t-il (erreur amplifiée) ? »
6. **Piège rafale** : « Sur `wind_speed_max`, compare la distribution
   des erreurs A et B. B sous-estime-t-il systématiquement les vraies
   rafales fortes (obs > 25 km/h) ? »
7. **Recommandation paramètres** : « Si on devait régler 2 paramètres
   pour améliorer le résultat sur la rafale, lesquels ? »
8. **Test go / no-go phase 4** : « Sur les critères du § 8, où en
   est-on ? Quels critères ne sont pas remplis ? »
9. **Comparaison de snapshots** : « (avec un export précédent en
   référence) Quels modèles ont vu leur weight_factor bouger
   significativement entre les 2 dates ? Y a-t-il une dérive
   d'un modèle (mise à jour ratée chez le fournisseur) ? »
10. **Détection de zone forte/faible** : « Quel modèle est le meilleur
    sur Jouy (plaine) ? Et sur Brunas (Causses) ? Le rang change-t-il
    significativement entre zones ? Cela suggère-t-il qu'un mapping
    régional aurait du sens ? »
11. **Dégradation par horizon (apple-to-apple)** : « Regarde
    `horizon_mae[balise][variable].common` pour chaque balise. La MAE
    croît-elle régulièrement nowcast → J+2 comme attendu ? Si non,
    quel bucket ne se comporte pas comme prévu, et quelle pourrait être
    la cause (biais d'archivage, micro-climat) ? »
12. **Effet du filtre common vs full** : « Compare
    `horizon_mae[balise][variable].common.<bucket>.mae_a` vs
    `horizon_mae[balise][variable].full.<bucket>.mae_a` pour chaque
    bucket. Le filtre strict change-t-il le verdict sur quel algo gagne ? »

---

## 10. Pour aller plus loin

- **Cadrage complet** : [`FF_model_reliability.md`](FF_model_reliability.md)
  à la racine du repo. Contient l'historique du design, les alternatives
  écartées, et le plan des phases 3 et 4.
- **Code** : services dans `src/app/Services/Weather/Reliability/`
  (ConsensusCalculator, ReliabilityCalculator, BaliseConsensusCompareService,
  ReliabilityExportService).
- **Tests unitaires** : `src/tests/Unit/Weather/Reliability/`. 13 tests
  couvrent les cas standards et limites (chevauchement Nord, MAD plancher,
  cold start, …).
- **Page admin** : `/admin/reliability/compare` et `/admin/reliability/models`.

---

*Fin du contexte. Si certaines questions restent sans réponse à la
lecture d'un export, signale-le pour qu'on enrichisse ce document.*
