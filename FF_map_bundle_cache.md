# FF — Cache pré-calculé des données carte (« map bundle »)

> **Statut** : cadrage, pas encore implémenté.
> **Branche prévue** : `claude/feature-map-bundle-cache` (partie de V2.x ou V3.x).
> **Évoqué le** : 2026-05-16, après diagnostic des performances mobile.

---

## Concept

Aujourd'hui, le boot de la vue carte (`/carte`) déclenche **N+1 requêtes
HTTP** : 1 pour `GET /api/sites` (liste des sites), puis N en parallèle
pour `GET /api/sites/{id}/scores` (un appel par site). Avec 14 sites
c'est tolérable, avec 100+ (vision nationale) ça devient catastrophique,
surtout sur mobile lent.

Côté serveur, chaque `/scores` fait 1 query SQL + recalcul PHP du
`day_quality` + sun windows + détail allégé. Or **ces données ne
changent qu'une fois par heure**, à la fin du scoring de chaque site.

**Idée** : pré-calculer un JSON consolidé en cache Redis, régénéré
automatiquement à la fin de chaque scoring. Le boot de la carte ne
fait plus qu'**un seul appel** qui sert un JSON déjà prêt.

Le scoring perso reste user-spécifique et n'est pas dans le bundle —
servi par un endpoint séparé léger.

---

## Périmètre & priorités

| Phase | Objet | Gain mobile | Effort |
|---|---|---|---|
| 1 | **Map bundle** (markers carte) | ⭐⭐⭐ très gros | 1 j |
| 2 | **Site detail bundle** (volet droit) | ⭐⭐ moyen | 0,5 j |
| 3 | **Balises bundle** (positions + readings) | ⭐ faible | 0,5 j |
| 4 | **User overrides** (scoring perso) | (nécessaire pour la phase 1) | 0,3 j |

L'ordre conseillé : 1 → 4 → 2 → 3. Les phases 2 et 3 peuvent être
faites séparément ou ensemble. La phase 4 est nécessaire pour
pouvoir mettre en prod la phase 1 sans casser le scoring perso.

---

## Phase 1 — Map bundle

### Endpoint nouveau

```
GET /api/map-bundle
```

### Forme de la réponse

```json
{
  "generated_at": "2026-05-16T14:00:00+02:00",
  "next_refresh_at": "2026-05-16T15:00:00+02:00",
  "version": 1,
  "sites": [
    {
      "id": 1,
      "name": "Volmerange EST",
      "lat": 49.4468,
      "lng": 6.0999,
      "altitude_m": 420,
      "level": 4,
      "region": "Grand-Est",
      "wind_dir_min": 75,
      "wind_dir_max": 105,
      "days": [
        {
          "raw": "16/05",
          "label": "Aujourd'hui",
          "best_status": "green",
          "green_slots": 4,
          "day_quality": 68
        },
        {
          "raw": "17/05",
          "label": "Demain",
          "best_status": "orange",
          "green_slots": 1,
          "day_quality": 22
        }
      ],
      "sun_windows": {
        "16/05": { "sunrise": "06:12", "sunset": "21:34", "start_hour": 6, "end_hour": 22 }
      }
    }
  ]
}
```

**Tailles estimées** :
- 1 site ≈ 500 octets JSON
- 100 sites ≈ 50 KB JSON, ~10 KB gzip
- 1000 sites ≈ 500 KB JSON, ~100 KB gzip — encore acceptable

### Données NON incluses dans le bundle

- `user_scoring` (badge actif/inactif sur le marqueur) — user-spécifique
- Scoring perso recalculé (status horaires modifiés) — user-spécifique
- Scores horaires détaillés — laissés à `/api/sites/{id}/scores` (consulté que sur clic)
- Balises — phase 3

### Architecture

```
app/Services/Map/MapBundleBuilder.php         ← construit le bundle
app/Jobs/RebuildMapBundleJob.php              ← regénère et stocke en cache
app/Http/Controllers/Api/MapBundleController.php  ← endpoint, lit le cache
routes/api.php                                ← + Route::get('map-bundle', ...)
```

`MapBundleBuilder::build(): array` — fait :
1. Récupère tous les sites actifs avec leurs conditions
2. Pour chaque site, récupère les `site_scores upcoming()`
3. Agrège en `days[]` (best_status, green_slots, day_quality) — réutilise la logique actuelle de `SiteController::scores`
4. Calcule les sun_windows pour les 5 jours
5. Renvoie le tableau structuré

### Cache

- **Clé Redis** : `map.bundle.v{N}` avec `N = MapBundleBuilder::VERSION` (cf. point 11 CLAUDE.md sur l'invalidation par bump de version quand le schéma change).
- **TTL** : 90 minutes (un peu plus que l'intervalle de scoring de 60 min, pour ne pas expirer pile entre deux regénérations).
- **Sérialisation** : `json_encode` puis stocké en string. **Surtout pas d'objets Eloquent** (cf. point 11 CLAUDE.md). Lecture = `json_decode(..., true)`.

### Invalidation — les deux stratégies combinées

1. **Push** : à la fin du `FetchForecastsJob` (quand toutes les chaînes ont fini), on dispatch `RebuildMapBundleJob` qui regénère et écrit en cache.
   - Problème : `FetchForecastsJob` actuel dispatch des chaînes async qui se terminent à des moments différents. Il faut une stratégie pour détecter la fin globale (e.g. compteur Redis décrémenté à chaque `ScoreSiteJob::handle`, dispatch quand atteint 0).
2. **Lazy fallback** : si la clé Redis est absente quand l'endpoint est appelé, on régénère à la volée (avec un lock Redis pour éviter le thundering herd).
   - Le 1er user après expiry paie le coût (~200 ms estimé pour 100 sites).
3. **Override manuel** : commande artisan `map:rebuild-bundle` pour forcer la régénération depuis la CLI (utile en dev).

### Endpoint MapBundleController

```php
public function show(MapBundleBuilder $builder): JsonResponse
{
    $key = MapBundleBuilder::cacheKey();
    $bundle = Cache::get($key);

    if ($bundle === null) {
        // Lazy fallback : 1er user après expiry
        $bundle = $builder->build();
        Cache::put($key, $bundle, now()->addMinutes(90));
    }

    return response()->json($bundle);
}
```

---

## Phase 4 — User overrides

### Endpoint nouveau

```
GET /api/me/scoring-overrides
```

(protégé par `auth`)

### Forme de la réponse

```json
{
  "user_id": 42,
  "overrides": {
    "1":  { "user_scoring": "active",   "days": [ ... même structure que map bundle ... ] },
    "12": { "user_scoring": "active",   "days": [ ... ] },
    "7":  { "user_scoring": "inactive", "days": null }
  }
}
```

Seuls les sites où l'utilisateur a un scoring perso (actif ou inactif)
apparaissent dans `overrides`. La plupart des users en auront 1-3.

### Côté client

```js
// avant
const sites = await fetch('/api/sites');
const scoresByUser = await Promise.all(sites.map(s => fetch(`/api/sites/${s.id}/scores`)));
// 15 requêtes

// après
const [bundle, overrides] = await Promise.all([
    fetch('/api/map-bundle'),
    authUser ? fetch('/api/me/scoring-overrides') : null,
]);
// 1-2 requêtes, applique overrides sur bundle.sites en mémoire
```

---

## Phase 2 — Site detail bundle (volet droit)

Quand l'utilisateur clique un marqueur, le volet droit charge plusieurs
onglets :
- **Synthèse** (graphes confiance + plafond du jour)
- **Détail scoring · 5 jours** (tableau voting × paramètres × heures)
- **Modèles du jour** (multi-modèle)
- **Modèles · 5 jours** (multi-modèle agrégé)

Aujourd'hui chacun déclenche 1 appel API à la demande. Les données
sous-jacentes (forecasts + site_scores) ne changent qu'à chaque scoring.

### Stratégie

Un bundle par site, mis en cache à clé `map.site_detail.{id}.v{N}` :

```json
{
  "site_id": 1,
  "generated_at": "...",
  "voting": { ... 5 jours × params × heures × {color, value} ... },
  "synthese": { ... données horaires pour les charts ... },
  "multimodel": { "16/05": { ... }, "17/05": { ... } },
  "multimodel_5d": { ... agrégé ... }
}
```

**Taille** estimée : ~30 KB par site. Avec 100 sites = 3 MB total en
Redis. Acceptable.

### Endpoints

Les endpoints existants (`/api/sites/{id}/scores`, `/chart`, `/multimodel`)
deviennent des **wrappers** sur le cache : si la clé existe, on extrait
la section demandée du bundle ; sinon on régénère le bundle complet
puis on extrait.

Alternative : 1 nouvel endpoint `/api/sites/{id}/detail` qui renvoie
tout d'un coup. Plus simple côté client (1 appel au clic au lieu de
3-4), mais alourdit le payload (30 KB d'un coup).

### Invalidation

Plus fine que la phase 1 : on peut invalider **par site** dès que son
`ScoreSiteJob` se termine, sans attendre la fin globale.

### Scoring perso

Idem phase 1 : le bundle site est purement global. Le scoring perso
fait l'objet d'une surcouche (recalculer la `voting` table à la volée
à partir des site_scores cachés + user_site_conditions de l'user).
Coût acceptable car appelé 1×/clic, pas en boot.

---

## Phase 3 — Balises bundle

Les balises (PiouPiou / METAR / Windy) ont :
- Des **positions statiques** (lat, lng, name, altitude, source)
- Des **readings temps réel** (rafraîchis toutes les 5-15 min selon le job)

### Endpoint

```
GET /api/balises-bundle
```

```json
{
  "generated_at": "...",
  "balises": [
    {
      "id": 123, "name": "...", "source": "pioupiou", "lat": ..., "lng": ..., "altitude_m": ...,
      "latest": { "wind_direction": 90, "wind_speed_avg": 12, "wind_speed_max": 18, "timestamp": "...", "freshness_class": "ok" },
      "readings_today_count": 47
    }
  ]
}
```

**TTL plus court** que le map bundle (5 min, car les readings bougent).

### Invalidation

À la fin des jobs `FetchPiouPiouReadingsJob`, `FetchMetarReadingsJob`,
`FetchWindyReadingsJob` — chacun forget la clé.

### Détail balise (rose des vents, graphe vitesse)

Reste un endpoint à la demande, mais peut bénéficier d'un cache court
(2 min) côté serveur pour absorber les clics rapprochés.

---

## Risques & points d'attention

1. **Sérialisation Eloquent** : ne **jamais** stocker d'objets Eloquent
   en Redis (cf. point 11 CLAUDE.md). Tout serialiser en arrays/scalars
   AVANT le `Cache::put`.
2. **CACHE_VERSION** : bumper la version dans la clé à chaque changement
   de schéma du bundle, sinon vieilles entrées Redis cassent les vues.
3. **Thundering herd** : si 100 users rechargent en même temps après
   expiry, 100 régénérations en parallèle. Utiliser `Cache::lock()` autour
   de la régénération lazy.
4. **Cohérence client / serveur** : si le client a un bundle cache
   navigateur de 30 min et que le serveur régénère après scoring, le
   client peut avoir des données stale. Ajouter `Cache-Control:
   max-age=60, must-revalidate` + `ETag` basé sur `generated_at` pour
   un cache HTTP correct.
5. **Détection de fin du `FetchForecastsJob`** : la chaîne actuelle est
   async, il faut un mécanisme de coordination (compteur Redis,
   listener Bus::after, etc.) pour détecter quand tous les sites ont
   fini de scorer.
6. **Mémoire Redis** : phase 1 ~10 KB, phase 2 ~3 MB pour 100 sites,
   phase 3 ~50 KB. Total négligeable face à la capacité Redis prod.

---

## Estimation totale

| Phase | Effort |
|---|---|
| 1 — Map bundle | 1 j |
| 4 — User overrides | 0,3 j |
| 2 — Site detail bundle | 0,5 j |
| 3 — Balises bundle | 0,5 j |
| Tests + bench charge | 0,5 j |
| **Total** | **~3 j** |

---

## Critères de succès

- **Boot de `/carte`** : 1 appel HTTP au lieu de 15+ (mesurable via DevTools Network).
- **Latence boot** sur 4G mobile : passer de ~1-2 s à <300 ms.
- **Charge DB** : passer de N queries à 1 read Redis par boot user.
- **Pas de régression visuelle** : les marqueurs, halos, popups, volets affichent les mêmes infos qu'avant.
- **Scoring perso** : continue de fonctionner (badge actif, halo personnalisé, détail scoring split global/perso).
- **Latence prochain scoring** : le bundle est dispo dans les ~1-5 s après la fin du `FetchForecastsJob` (mesurable via logs).

---

## Étapes d'implémentation (phase 1 + 4)

1. Créer `MapBundleBuilder` qui construit le bundle (réutilise la logique de `SiteController::scores`).
2. Refactorer `SiteController::scores` pour extraire la construction en service partagé (DRY).
3. Créer `RebuildMapBundleJob` qui appelle `MapBundleBuilder::build()` et `Cache::put`.
4. Créer `MapBundleController::show` (avec fallback lazy + lock).
5. Ajouter route `/api/map-bundle` dans `routes/api.php`.
6. Brancher le dispatch de `RebuildMapBundleJob` à la fin de `FetchForecastsJob`. Utiliser un compteur Redis ou `Bus::batch()` pour détecter la fin.
7. Créer `MeScoringController::overrides` (auth required) et route `/api/me/scoring-overrides`.
8. Côté JS de la carte : remplacer `loadSites + Promise.all(loadSiteScores)` par `loadMapBundle + (authUser ? loadOverrides : null)`. Fusionner overrides en mémoire.
9. Tester en dev avec `php artisan map:rebuild-bundle` (commande optionnelle).
10. Bench avant/après sur 4G simulé + sur tel réel.

---

## Évolutions possibles plus tard

- **Server-Sent Events** pour notifier le client quand le bundle a été
  régénéré (le client recharge automatiquement, sans polling).
- **HTTP/2 push** ou Service Worker pour pré-charger le bundle.
- **CDN edge** : si on déploie sur un CDN, le bundle peut être servi
  depuis l'edge le plus proche (cache-control public).
- **Compression Brotli** au lieu de gzip pour le bundle (gain ~15% sur
  JSON).
