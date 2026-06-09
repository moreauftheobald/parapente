# FF — Scoring perso déporté au sidecar (`/v1/scoring/custom`)

> **Statut : cadré, validé des deux côtés (Laravel + sidecar), pas encore
> implémenté.** À coder une fois l'endpoint `POST /v1/scoring/custom`
> disponible côté sidecar `consensus-grid-v2`.
>
> Complète `FF_personnal_scoring.md` (scoring perso utilisateur, modèle de
> données `user_site_conditions`, rotation LRU, cache). Ici on traite
> **uniquement** le déport du *calcul* vers le sidecar.

---

## 1. Concept & motivation

Aujourd'hui le scoring perso d'un utilisateur est recalculé **en PHP** par
`App\Services\Weather\ScoringRules` : on rejoue les règles éliminatoires
sur les valeurs consensus déjà stockées (`site_scores_{1,2}`) avec les
conditions custom de l'utilisateur. Problème : c'est une **seconde
implémentation** de la logique de scoring, qui doit rester cohérente avec
`scoring.py` du sidecar (cf. le ⚠️ dans `ScoringRules` et `CLAUDE.md`).
Dette de maintenance + risque de dérive (notamment quand `scoring.py`
évolue : quality profiles, nouvelles règles…).

**Objectif** : une **seule** logique de scoring (`scoring.py`). Laravel
n'évalue plus aucune règle ; il délègue le scoring perso au sidecar via un
endpoint, et cache le résultat jusqu'à invalidation.

---

## 2. Contrat d'endpoint (validé)

`POST /v1/scoring/custom`

### Requête

Laravel envoie les sites perso actifs de l'utilisateur (coords + conditions
custom) + les seuils globaux. Le sidecar **lit son propre forecast en
interne** (pas d'aller-retour de données) et score.

```json
{
  "thresholds": {
    "precip_orange_mmh": 0.0,
    "precip_red_mmh": 0.1,
    "gust_orange_kmh": 30,
    "gust_red_kmh": 40
  },
  "sites": [
    {
      "ref": 42,                         // = site_id, échoué tel quel dans la réponse
      "latitude": 45.5,
      "longitude": 6.1,
      "altitude_m": 1200,                // cf. §6 : usage à clarifier
      "conditions": {
        "wind_dir_min": 180, "wind_dir_max": 300,
        "wind_speed_min": 5, "wind_speed_max": 30,
        "wind_gust_orange_kmh": 30, "wind_gust_red_kmh": 40,
        "cloud_base_min_m": 1500,
        "cloud_cover_low_max": 60        // parité règle 7 scoring.py (ne pas l'oublier)
      }
    }
  ]
}
```

> ⚠️ `cloud_cover_low_max` était absent de l'esquisse initiale — il fait
> partie des règles éliminatoires de `scoring.py` (règle 7 : `cloud_cover_low
> > max → orange`). À inclure pour la parité complète avec le scoring prod.

### Réponse

Groupée par site (échoue `ref`), un objet par créneau horaire avec **statut
ET couleurs par paramètre** (le front lit `detail.<param>.color`, le statut
seul ne suffit pas) :

```json
{
  "run_init_unix": 1781002523,
  "sites": [
    {
      "ref": 42,
      "slots": [
        {
          "forecast_at": "2026-06-09 13:00:00",
          "status": "green",
          "colors": {
            "wind_dir": "green", "wind_speed": "green",
            "wind_gust": "orange", "precip": "green",
            "cloud_base": "unknown"
          }
        }
      ]
    }
  ]
}
```

---

## 3. Invariant CRITIQUE — extraction identique à la prod

Le endpoint custom **doit** extraire les valeurs consensus au point
`(lat,lng)` avec **exactement** la même méthode que le run prod
`score_all_sites` (même cellule, même interpolation, même dérivation du
plafond), et appeler **la même** fonction `score_slot(consensus,
conditions, thresholds)`.

Sinon : un utilisateur dont les conditions custom == les conditions par
défaut du site obtiendrait un statut **différent** de la couleur officielle
du marqueur → incohérence visible et minante. Pas de seconde implémentation
d'extraction côté sidecar.

---

## 4. Cache & invalidation (côté Laravel)

Clé : `user:{id}:scoring_custom` (entrée unique par user, contenant tous
ses sites perso actifs → 1 seul appel batch pour l'overlay carte).

Le résultat dépend de **trois** entrées → **trois** déclencheurs
d'invalidation (NE PAS n'en gérer qu'un) :

| # | Entrée qui change | Déclencheur |
|---|---|---|
| 1 | Le consensus (nouveau run sidecar) | flip de `scoring_table` → `WatchScoringTableJob` |
| 2 | Les conditions perso de l'utilisateur | édition du scoring → `UserScoringService::invalidate()` |
| 3 | Les seuils globaux (`thresholds`) | `/admin/settings` → `Settings::flush()` → `invalidateAll()` |

**Décision de design — deux options équivalentes pour le n°1 :**

- **(a, recommandée)** Clé **sans** composante run. On réutilise la
  plomberie existante : `WatchScoringTableJob` détecte déjà le flip et
  appelle `UserScoringService::invalidateAll()`. Une pièce mobile en moins.
- **(b)** Clé incluant `run_init_unix` (monotone) → auto-orphelinage au
  nouveau run, plus besoin du n°1 explicite. Mais Laravel doit connaître le
  `run_init_unix` courant à moindre coût au lookup → le faire stocker par
  `WatchScoringTableJob` dans Redis au flip.

> ⚠️ **Ne jamais keyer par nom de buffer** (`site_scores_1/2`) : il cycle
> entre 2 valeurs, donc 2 runs plus loin la clé se réutilise → service de
> données périmées. Seul un identifiant **monotone** (`run_init_unix`)
> convient si on choisit l'option (b).

En régime nominal le sidecar n'est **pas** sollicité (cache hit). Appel
uniquement sur miss : au flip (~1×/h), à l'édition d'un scoring, au
changement de seuil.

---

## 5. Mode dégradé (sidecar injoignable)

`/api/sites/{id}/scores` et l'overlay carte (`/api/me/scoring-overrides`)
retombent sur le **scoring global** (pas d'overlay perso, pas de 500). La
dégradation est gracieuse : l'utilisateur voit les statuts officiels.

---

## 6. Points à clarifier / garde-fous

- **`altitude_m`** : à quoi sert-il dans le scoring éliminatoire ? Vent /
  dir / rafale / précip n'en ont pas besoin, `cloud_base_min_m` se compare
  en AMSL. Si inutilisé (hors future qualité) → documenter comme optionnel.
- **Snapshot** : pendant qu'un run écrit, l'endpoint sert le **dernier run
  complété** (cohérent avec le buffer `site_scores` actif), jamais un état
  mi-écrit.
- **Burst au flip** : juste après un run, les caches perso de tous les
  users s'invalident ; le 1er accès de chacun déclenche un appel. Volume
  faible (endpoint ~10 ms) mais à garder en tête côté capacité sidecar.
- **Fenêtre d'incohérence ~1 min** : entre le flip réel et la détection par
  `WatchScoringTableJob` (cron minute), l'overlay perso (lu live) et la base
  globale (cache) peuvent venir de runs différents. Mineur (valeurs quasi
  identiques d'un run à l'autre), accepté.

---

## 7. Travaux côté Laravel (à l'implémentation)

- **`UserScoringService`** : devient un **client HTTP batché** du sidecar
  (`POST /v1/scoring/custom`) + cache + fallback global. `rescoreForUserSite`
  → ajouter un `rescoreForUserSites($userId, $siteIds[])` (1 appel pour tous
  les sites perso actifs, utilisé par l'overlay carte).
- **Supprimer `App\Services\Weather\ScoringRules`** (+ son test unitaire
  `UserScoringServiceTest` à adapter : on mocke désormais la réponse HTTP du
  sidecar, plus de calcul PHP).
- **Conserver `DayQualityCalculator`** (PHP) : la viabilité du jour est une
  *agrégation* des statuts, pas une règle ; recalculée depuis les statuts
  renvoyés. Inchangé.
- **Inchangés** : `SiteScoresPayloadBuilder::applyUserRescore` et
  `MeScoringController` consomment la même structure (`map forecast_at =>
  {status, colors}`) — il suffit que le client la reconstruise depuis la
  réponse sidecar.
- **Config** : réutilise `services.consensus_grid.base_url`
  (`consensus-grid-v2-api:8082`) ; ajouter un timeout dédié si besoin.

---

## 8. Travaux côté sidecar (pour l'équipe sidecar)

- Refactor `scoring.py` : extraire `score_slot(consensus, conditions,
  thresholds) → (status, colors)` **pur**, partagé entre le run prod
  (`score_all_sites`) et le nouvel endpoint. **Même** `extract_values_at
  (lat,lng)` que la prod (cf. §3).
- Endpoint `POST /v1/scoring/custom` : lit le forecast interne pour les N
  sites, applique `score_slot` avec les conditions custom, renvoie groupé
  par `ref` avec `status` + `colors` + `forecast_at` par créneau, + le
  `run_init_unix` de la réponse.
- Servir depuis le **dernier run complété** (snapshot cohérent).

---

## 9. Estimation grossière

- **Sidecar** : extraction de `score_slot` pur + endpoint + tests → modéré
  (gros du travail = garantir l'extraction partagée, §3).
- **Laravel** : réécriture `UserScoringService` (client HTTP + cache +
  fallback), suppression `ScoringRules`, adaptation tests → modéré.
- Pas de migration DB (le modèle `user_site_conditions` ne bouge pas).

---

## 10. Découpage suggéré

1. Sidecar : `score_slot` pur + endpoint + tests (côté sidecar).
2. Laravel : client HTTP `UserScoringService::rescoreForUserSites` derrière
   le cache existant, **en parallèle** de `ScoringRules` (feature compare :
   vérifier que sidecar == PHP sur un échantillon).
3. Bascule : `UserScoringService` n'utilise plus que le sidecar ; fallback
   global sur erreur.
4. Suppression de `ScoringRules` + adaptation des tests.
5. Doc : `CLAUDE.md` (section scoring perso) + `CHANGELOG.md`.

---

## 11. Risques identifiés

- **Dérive d'extraction** (§3) : le risque majeur. Mitigé par l'exigence de
  fonction partagée + un test de parité (étape 2 du découpage) comparant
  sidecar vs PHP avant de supprimer `ScoringRules`.
- **Invalidation incomplète** : oublier le déclencheur n°2 ou n°3 (§4)
  donnerait un overlay perso périmé après édition. Couvrir les 3.
- **Dépendance réseau sur chemin semi-chaud** : neutralisée par le cache
  (appel sur miss only) + fallback global.
