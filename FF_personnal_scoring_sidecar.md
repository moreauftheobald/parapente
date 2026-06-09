# FF — Scoring perso déporté au sidecar (`POST /v1/scoring/custom`)

> **Statut : IMPLÉMENTÉ (2026-06-09).** Endpoint sidecar
> `POST /v1/scoring/custom` en service ; côté Laravel : `CustomScoringClient`
> + `UserScoringService` (batch par user, cache, fallback global),
> `ScoringRules` supprimé. Document conservé pour l'historique de cadrage et
> les points de vérification (§7) à valider en intégration.
>
> Ce document est la version Laravel, alignée sur le document de travail
> sidecar (« FF — Scoring personnalisé via le sidecar », 2026-06-09). Il
> complète `FF_personnal_scoring.md` (modèle `user_site_conditions`,
> rotation LRU, cache) — ici on traite **uniquement** le déport du *calcul*.

---

## 1. Concept & motivation

Le scoring perso d'un utilisateur (jusqu'à 10 scorings actifs, cf.
`UserSiteCondition::MAX_ACTIVE`) est aujourd'hui recalculé **en PHP** par
`App\Services\Weather\ScoringRules` : on rejoue les règles éliminatoires sur
les valeurs consensus de `site_scores_{1,2}` avec les conditions custom de
l'utilisateur. C'est une **seconde implémentation** de la logique de scoring,
qui doit rester cohérente avec `scoring.py` du sidecar — dette de maintenance
+ risque de dérive (surtout quand `scoring.py` évolue : quality profiles…).

**Objectif** : une **seule** logique de scoring (`scoring.py`). Laravel
n'évalue plus aucune règle ; il appelle un endpoint sidecar à la demande,
cache le résultat, et l'invalide sur 3 déclencheurs.

Pré-calcul écarté : scorer N users × 10 sites × 121 steps chaque heure pour
des utilisateurs potentiellement inactifs serait du gaspillage. Le scoring
perso reste **à la demande** (déclenché à la consultation de la carte).

---

## 2. Exigence CRITIQUE — extraction identique à la prod

L'endpoint custom **DOIT** réutiliser les fonctions internes du run prod
`score_all_sites()` (`src/scoring.py`) :

- **Extraction** `_extract_site_values()` — même nearest-cell, même lecture
  NetCDF, mêmes variables.
- **Scoring** `score_site_step()` + `_apply_eliminatory_rules()` +
  `_param_color_*()` — mêmes règles, même conversion m/s → km/h, même
  confidence.

**Garantie attendue** : un utilisateur dont les conditions custom == les
conditions par défaut du site obtient **exactement** le statut de la couleur
officielle du marqueur. Pas de seconde implémentation d'extraction.

---

## 3. Contrat d'endpoint (final)

### 3.1 Requête — `POST /v1/scoring/custom`

```json
{
  "thresholds": {
    "precip_orange_mmh": 0.0,
    "precip_red_mmh": 0.1,
    "gust_orange_kmh": 25.0,
    "gust_red_kmh": 35.0
  },
  "sites": [
    {
      "ref": "usc_42",
      "latitude": 45.5,
      "longitude": 6.1,
      "altitude_m": 1200,
      "conditions": {
        "wind_dir_min": 180, "wind_dir_max": 300,
        "wind_speed_min": 5, "wind_speed_max": 30,
        "wind_speed_ideal": 18,
        "wind_gust_orange_kmh": 30.0, "wind_gust_red_kmh": 40.0,
        "cloud_base_min_m": 1500,
        "cloud_cover_low_max": null
      }
    }
  ]
}
```

- `thresholds` *optionnel* (défauts sidecar = défauts `Settings` :
  precip 0.0/0.1, gust 25/35). Laravel les enverra explicitement pour rester
  maître des seuils globaux.
- `sites` : 1 à 10. `ref` = identifiant **opaque** recopié dans la réponse
  → côté Laravel ce sera l'`id` du `user_site_condition` (un scoring perso =
  un couple user×site), pour remapper sans dépendre de l'ordre.
- `conditions` : miroir de `user_site_conditions` / `site_conditions`.
  `wind_speed_ideal` non utilisé par l'éliminatoire (réservé qualité future).
  `wind_gust_*_kmh` null → retombe sur `thresholds`. `cloud_base_min_m` /
  `cloud_cover_low_max` null → règle ignorée.
- `altitude_m` : utilisé **uniquement** par les quality profiles
  (gradient thermique, plafond relatif). **Ignoré par l'éliminatoire.**

### 3.2 Réponse

```json
{
  "run_init_unix": 1781035200,
  "run_init_iso": "2026-06-09T12:00:00+00:00",
  "computed_at": "2026-06-09T14:35:00+00:00",
  "sites": [
    {
      "ref": "usc_42",
      "slots": [
        {
          "forecast_at": "2026-06-09T06:00",
          "status": "green",
          "confidence_pct": 87,
          "models_count": 15,
          "models_converging": 13,
          "colors": {
            "wind_dir":   { "consensus": 245.0, "color": "green" },
            "wind_speed": { "consensus": 18.5,  "color": "green" },
            "wind_gust":  { "consensus": 28.0,  "color": "orange" },
            "precip":     { "consensus": 0.0,   "color": "green" },
            "cloud_base": { "consensus": 2100,  "color": "green" },
            "storm_risk": { "consensus": 0,     "color": "green" }
          }
        }
      ]
    }
  ]
}
```

- `colors.<param>` est un **objet `{consensus, color}`** (plus riche que
  l'ancien map plat `param→"green"` du PHP) → le client Laravel lira
  `.color` pour l'overlay, `.consensus` dispo si besoin.
- 6 paramètres : `wind_dir`, `wind_speed`, `wind_gust`, `precip`,
  `cloud_base`, **`storm_risk`** (nouveau vs les 5 du PHP ; lu du consensus,
  pas une condition user). Doit matcher le jeu de params de
  `site_scores.detail` (global) — cf. §7 point C.
- `forecast_at` en **ISO 8601 UTC** ; couvre 0→120h du dernier run (~121
  steps). Vent en **km/h**.
- `confidence_pct` / `models_*` : dépendent du consensus, pas des conditions
  → identiques au global. Le client peut conserver la confiance globale.

### 3.3 Codes d'erreur

| HTTP | Cas |
|---|---|
| 400 | `sites` vide ou > 10, `conditions` incomplet, coords invalides |
| 404 | Point hors domaine `[41°,52°] × [-5°,10°]` |
| 503 | Aucune donnée consensus (avant le 1er run) |

> Côté Laravel : toute erreur (4xx/5xx, timeout, réseau) ⇒ **fallback
> scoring global**, pas de 500 utilisateur (cf. §6). Demande à l'équipe
> sidecar (§7 point E) : préférer une dégradation *par site* (slot
> `unknown`) plutôt qu'un 404 global qui ferait échouer tout le batch.

### 3.4 Snapshot cohérent

L'endpoint sert **toujours le dernier run complété** (cache HDF5 keyé sur
`(path, mtime_ns)` ; le `os.replace()` atomique du worker bascule la lecture
au nouveau fichier). Jamais d'état mi-écrit.

---

## 4. Cache & invalidation (côté Laravel)

Clé : **`scoring_custom:user:{user_id}`** — une entrée par user, contenant
tous ses sites perso actifs (1 seul POST batch pour l'overlay carte).

Le résultat dépend de **trois** entrées → **trois** déclencheurs (ne pas
n'en gérer qu'un) :

| # | Entrée | Déclencheur Laravel |
|---|---|---|
| 1 | Consensus (nouveau run) | flip `scoring_table` → `WatchScoringTableJob` (existe déjà) |
| 2 | Conditions perso éditées | contrôleur d'édition du scoring → invalidation de la clé du user |
| 3 | Seuils globaux modifiés | `/admin/settings` → `Settings::flush()` → invalidation de tous les users |

Pas de `run_init_unix` dans la clé : on réutilise la plomberie de flip
existante (`WatchScoringTableJob`), une pièce mobile en moins.

> ⚠️ Détail d'implémentation Laravel : `Cache::forget("...*")` **n'existe
> pas** (pas de wildcard). On invalide par **itération** des users actifs —
> `UserScoringService::invalidateAll()` passe d'une clé par `(user,site)` à
> une clé par `user`. Idem pour le déclencheur 2 (forget de la clé du user
> concerné).

En régime nominal le sidecar n'est **pas** sollicité (cache hit). Appel sur
miss seulement : au flip (~1×/h), à l'édition d'un scoring, au changement de
seuil.

---

## 5. Performance (estimation sidecar)

| Étape | Temps | Notes |
|---|---|---|
| Extraction NetCDF (14 vars × N sites) | ~10-30 ms | cache HDF5 chaud |
| Scoring (N × 121 steps) | ~1-5 ms | CPU pur |
| Sérialisation JSON | ~1-2 ms | |
| **Total** | **~15-40 ms** | 1 à 10 sites |

Lock HDF5 global partagé avec `/v1/forecast` ; trafic qui-vole.fr faible →
pas de contention attendue. Lève-bottleneck futur : preload RAM des
variables (bump conteneur API 4→14 GB).

---

## 6. Mode dégradé (sidecar injoignable)

`/api/sites/{id}/scores` et l'overlay carte (`/api/me/scoring-overrides`)
retombent sur le **scoring global** (`site_scores` actif) — pas de 500,
overlay perso simplement non affiché, log WARNING pour monitoring.

---

## 7. Points à confirmer avec l'équipe sidecar (avant code Laravel)

- **A. (important) Alignement `forecast_at`** : les instants renvoyés
  doivent coïncider avec `site_scores.forecast_at` (même run, même horizon),
  sinon l'overlay (appliqué *par-dessus* le payload global) ne retombe pas
  sur les bons créneaux. La réponse couvre 0→120h, le global est filtré
  `upcoming` (sous-ensemble) : les slots passés en trop sont ignorés. Côté
  Laravel : parsing ISO-UTC + matching à l'instant.
- **C. Parité du jeu de paramètres** : `colors` (custom) doit exposer
  exactement les mêmes params que `site_scores.detail` (global), `storm_risk`
  compris — confirmer que le global l'expose aussi.
- **D. Quality** : la réponse §3.2 ne contient **aucun** champ quality, alors
  que le doc sidecar (§6/§7) calcule les profils. Quality **hors scope** du
  perso v1 → soit ne pas la calculer (économie), soit l'ajouter à la réponse
  pour plus tard. À trancher, mais inutile de calculer pour jeter.
- **E. Dégradation par site** sur 404/503 (cf. §3.3) plutôt qu'échec batch.

(B et F traités côté Laravel : lecture `.color`, invalidation par itération.)

---

## 8. Travaux côté Laravel (à l'implémentation)

- **`UserScoringService`** → **client HTTP batché** de `POST /v1/scoring/custom`
  + cache (`scoring_custom:user:{id}`) + fallback global. Ajouter
  `rescoreForUserSites($userId, $siteIds[])` (1 appel pour tous les sites
  perso actifs, utilisé par l'overlay carte). Reconstruire la structure
  attendue par l'aval : `map forecast_at => {status, colors:{param=>color}}`
  (extraire `colors.<param>.color`).
- **Supprimer `App\Services\Weather\ScoringRules`** (+ adapter
  `UserScoringServiceTest` : mock de la réponse HTTP, plus de calcul PHP).
- **Conserver `DayQualityCalculator`** (PHP) : agrégation des statuts, pas une
  règle ; `day_quality` perso recalculé depuis les statuts renvoyés.
- **Inchangés** : `SiteScoresPayloadBuilder::applyUserRescore` et
  `MeScoringController` consomment la même structure → quasi inchangés.
- **Config** : réutilise `services.consensus_grid.base_url`
  (`consensus-grid-v2-api:8082`) ; timeout dédié si besoin.
- Pas de migration DB (`user_site_conditions` inchangé).

---

## 9. Découpage suggéré

1. **Sidecar** : `score_site_step` / extraction partagés + endpoint + tests.
2. **Laravel** : client `rescoreForUserSites` derrière le cache, **en
   parallèle** de `ScoringRules` → **test de parité** sidecar vs PHP sur un
   échantillon (l'invariant §2 doit tenir avant de supprimer le PHP).
3. **Bascule** : `UserScoringService` n'utilise plus que le sidecar ;
   fallback global sur erreur.
4. **Suppression** de `ScoringRules` + adaptation des tests.
5. **Doc** : `CLAUDE.md` (section scoring perso) + `CHANGELOG.md`.

---

## 10. Risques

- **Dérive d'extraction** (§2) : risque majeur. Mitigé par la fonction
  partagée + le test de parité (étape 2) avant suppression de `ScoringRules`.
- **Désalignement `forecast_at`** (§7-A) : overlay qui ne retombe pas sur les
  bons créneaux. À vérifier en étape 2.
- **Invalidation incomplète** (§4) : oublier le déclencheur 2 ou 3 ⇒ overlay
  perso périmé après édition. Couvrir les 3.
- **Dépendance réseau** sur chemin semi-chaud : neutralisée par le cache
  (appel sur miss only) + fallback global.
