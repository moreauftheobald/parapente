# FF — Windy.com comme source de balises météo

> **Statut** : cadrage / discussion. **Pas implémenté.**
> **Date de rédaction** : 2026-05-15 (révisée après prise en compte de
> la nouvelle API Windy publiée en janvier 2026)
> **Verdict d'ouverture** : viable. La **Windy Stations API v2** (mise
> en service janv. 2026) expose un endpoint **`opendata`** qui donne
> accès, avec une seule clé API, au catalogue + aux observations live
> des stations dont le propriétaire a opté pour une licence ouverte.
> C'est exactement ce qu'il nous faut.

---

## Concept

Aujourd'hui Qui Vole ? affiche deux réseaux de balises temps réel :
**PiouPiou** (via FFVL) et **METAR**. L'objectif est d'étoffer la
couverture en intégrant les stations Windy en **licence ouverte** —
qui agrègent de facto une partie des stations Holfuy / Davis / Netatmo
/ PWS amateurs dont les propriétaires ont coché « Open license ».

---

## API Windy Stations v2 (janvier 2026)

Host : `https://stations.windy.com/`

### Endpoints qui nous intéressent

| Endpoint | Auth | Rôle pour Qui Vole |
|---|---|---|
| `GET /api/v2/opendata/station` | clé API | **Catalogue** des stations en licence ouverte (lat/lng/altitude/nom/id) → alimente `BalisesDiscovery` |
| `GET /api/v2/opendata/station/{id}/observation` | clé API | **Observations live** d'une station ouverte → alimente `balise_readings` |

### Endpoints non utiles pour nous (cadrage)

| Endpoint | Pourquoi non |
|---|---|
| `GET /api/v2/observation` + `update` | Authentifié par **password de station** → réservé aux stations dont on est propriétaire (upload + lecture). On n'opère pas de stations physiques. |
| `GET/POST/PUT/DELETE /api/v2/pws` | CRUD sur **nos** stations enregistrées. Sans objet. |

### Authentification

- Une **clé API** unique à créer dans *API Keys* sur le compte Windy.
- Transmission : `?key=...` ou en-tête `windy-api-key: ...`.
- Stockage côté Qui Vole : `WINDY_API_KEY` dans `.env`, lecture via
  `config('balises.windy.api_key')`. Jamais en base.

### Migration depuis l'ancienne API

- Windy mentionne explicitement que l'ancienne API est dépréciée et
  sera coupée fin 2026. Comme on part de zéro, on attaque
  directement la v2 — pas de dette de migration.

---

## Pourquoi Windy plutôt que Holfuy / Netatmo / Davis séparément

Mon analyse initiale recommandait d'intégrer chaque réseau amont
individuellement (Holfuy, Netatmo, Davis, Awekas…). Avec l'API
opendata Windy, on couvre **en un seul provider** un sous-ensemble
de tous ces réseaux — celui des stations dont le propriétaire a coché
« partage en licence ouverte ».

| Critère | Windy opendata | Réseaux amont séparés |
|---|---|---|
| Authentification | **1 clé API** | 1 password/station (Holfuy) ou 1 OAuth (Netatmo) ou 1 token (Davis) |
| Découverte géographique | endpoint dédié, **liste complète** | propre à chaque API, parfois absent |
| Format de donnée | **uniforme** (Windy normalise) | hétérogène, un mapping par provider |
| Effort d'intégration | **1 provider** | N providers |
| Couverture | sous-ensemble *opt-in* de tous les réseaux | **chaque réseau complet** |
| Latence | dépend du re-publish Windy | direct station → app |

**Verdict** : Windy opendata en **phase 1**. Si on constate après
quelques semaines que la couverture FR est trop faible (peu de
propriétaires ont opté pour la licence ouverte), on complète avec
**Holfuy** en phase 2 — qui reste, à titre individuel, la source la
plus utile pour le parapente FR.

---

## Architecture d'intégration

L'architecture **provider** existante (`PiouPiouProvider`,
`MetarProvider`, déjà branchés sur `GeoDeploymentService` et
`BaliseController`) est faite pour ça. On ajoute :

```
App\Services\Balises\Providers\
├── PiouPiouProvider.php   (existant)
├── MetarProvider.php       (existant)
└── WindyOpenDataProvider.php  (à créer)
```

Contrat (déjà implicite dans les deux providers actuels) :

| Méthode | Endpoint Windy |
|---|---|
| `discoverInRadius(lat, lng, km)` | `GET /api/v2/opendata/station` filtré côté app (bbox + Haversine) |
| `fetchLiveReading(Balise $b)` | `GET /api/v2/opendata/station/{external_id}/observation` |
| `sourceKey(): string` | `'windy'` |

Pas de migration sur `balises` (table déjà polymorphe par
`source` + `external_id`).

### Évolutions schéma souhaitables (toujours valables)

- **`balises.reliability_class`** ENUM (`pro`, `amateur`).
  Windy opendata mélange des Holfuy bien installées et des PWS de
  jardin — la mémo des doublons et le filtrage qualité gagneront à
  pouvoir trier. Mapping initial : on regarde le `model` / `vendor`
  renvoyé par l'API Windy (à confirmer dans la doc détail des
  réponses) et on tagge avec une heuristique.
- **`balises.height_agl_m`** quand l'info est exposée par Windy
  (anémomètre à 2 m vs 10 m change tout).
- **`balises.windy_station_id`** = `external_id` (déjà supporté par
  le champ `external_id` existant, type `string`).

### Découverte géographique

`GeoDeploymentService` n'a qu'à boucler sur les providers — donc
l'ajout de `WindyOpenDataProvider` se fera **sans rien changer** au
controller `DataSyncController::deploy()`. Le commit d'intégration
restera trivial côté admin.

### Rate limit & quota

Doc Windy v2 à dépiauter pour confirmer les chiffres, mais ordre de
grandeur attendu d'une API gratuite : quelques centaines à quelques
milliers d'appels/jour par clé. Le scheduler actuel boucle toutes les
minutes pour les pioupious ; si on a 50 stations Windy → 50 appels/min
= 72 000 appels/jour. **À budgétiser** :

- Soit Windy expose un endpoint **batch** (à vérifier — souvent oui
  pour les API conçues pour les apps tierces).
- Soit on espace : Windy → 1 fetch toutes les 5 min suffit (la
  plupart des PWS publient à cette cadence de toute façon).
- En cas de quota dépassé : log côté `weather_fetch_log`, désactivation
  temporaire du provider (le worker doit déjà isoler les erreurs par
  provider — à vérifier dans `FetchSiteForecastsJob` et son équivalent
  balises).

### Authentification & secrets

```
WINDY_API_KEY=...    # créée sur https://api.windy.com → API Keys
```

Lecture via `config('balises.providers.windy.api_key')` (nouveau
fichier `config/balises.php` à créer — actuellement les providers
piochent leurs secrets directement dans `env()`, à industrialiser).

---

## Bornes / garde-fous

- **Fraîcheur de donnée** : conserver dans `balise_readings` la
  timestamp d'**émission station** (`captured_at`) renvoyée par
  Windy, pas la timestamp de fetch.
- **Détection de doublon** : l'écran `/admin/data-quality` livré
  aujourd'hui visualise les Windy installées à 50 m d'un pioupiou
  existant. Cas typique attendu : un pioupiou FFVL et la **même
  station** re-publiée sur Windy par son propriétaire → doublon
  parfait. L'admin choisit lequel garder (en règle générale, garder
  la source la plus en amont = pioupiou).
- **Licence Open mais usage commercial ?** : à vérifier dans les ToS
  Windy v2. La plupart des « Open license » dans le monde des PWS
  autorisent l'usage tiers à condition de **mentionner la source**.
  Action : ajouter un badge « source : Windy » sur la popup balise
  côté carte, par sécurité.
- **Conformité ToS** : l'API opendata est explicitement faite pour
  ça → pas de risque juridique, contrairement au scraping du site
  windy.com.

---

## Estimation grossière

| Phase | Périmètre | Effort |
|-------|-----------|--------|
| **1** | `WindyOpenDataProvider` (discover + fetch) + intégration `GeoDeploymentService` + secret `WINDY_API_KEY` + tests sur 5 stations FR | **1 j** |
| **2** | UI fiche balise (badge source Windy + lien profil) + cadence configurable par provider + isolation erreurs provider dans le worker | 0.5 j |
| **3** | Migration `reliability_class` + `height_agl_m` + heuristique de tag + filtrage scoring | 0.5 j |
| **4** | (option) `HolfuyProvider` si couverture Windy insuffisante | 1 j |

**Pré-requis externe** : créer un compte Windy + générer une clé API
(gratuit, ~5 min).

---

## Ordre de découpage suggéré

1. **POC API** : créer la clé Windy, appeler `/opendata/station` à la
   main (curl) sur la bbox France métropolitaine, **mesurer combien
   de stations FR sont effectivement publiées en Open license**. Ce
   nombre conditionne la priorité de la phase 4 (Holfuy direct).
2. **`WindyOpenDataProvider`** + intégration dans le déploiement
   géographique existant.
3. **Migration `reliability_class` / `height_agl_m`** (peut tourner
   en parallèle de 2 — la migration est triviale).
4. **Tag heuristique** + filtrage scoring : ne consulter que les
   balises *pro* dans la voting logic, garder les *amateur* visibles
   sur la carte avec un badge « indicatif ».
5. **Couverture FR évaluée à 4 semaines** → décision go/no-go sur
   `HolfuyProvider`.

---

## Risques identifiés

- **Risque couverture** : l'opendata Windy n'agrège que les stations
  *opt-in*. Inconnu au moment du cadrage. À mesurer en phase 1.
- **Risque qualité** : empiler des PWS amateur sans
  `reliability_class` va dégrader la voting logic. **Mitigation**
  bloquante : la migration `reliability_class` doit accompagner la
  livraison du provider, pas la suivre.
- **Risque rate limit** : si le quota gratuit est inférieur à
  N_stations × (24h / cadence), on est obligés de soit cadencer plus
  lentement, soit batcher. **Mitigation** : cadence configurable par
  provider dans `config/balises.php`.
- **Risque API jeune** : v2 mise en service janv. 2026 → bugs/
  changements possibles dans les premiers mois. **Mitigation** :
  versionner la classe (`WindyOpenDataProviderV2`) pour préparer une
  v3 indolore.
- **Risque doublons** : déjà mitigé par l'écran *Qualité des données*
  livré ce jour — la première chose à faire après le premier import
  Windy, c'est y faire un tour.

---

## À explorer en complément (hors-sujet immédiat)

- **`PG-Spots`** / **OpenWindMap** : agrégateurs communautaires de
  stations parapente. Peut combler les trous de la couverture Windy.
- **Sondes ICAO publiques** : déjà via METAR, pas de gain.

---

## Changements par rapport à la rédaction initiale

> Ce FF a été révisé après que l'API Windy Stations v2 a été
> identifiée. Initialement le doc concluait que Windy n'exposait pas
> d'observations live — c'était vrai pour la legacy API, faux depuis
> janvier 2026. La feature passe donc de **« pas faisable côté Windy
> direct »** à **« faisable et même recommandée en première
> intention »**.
