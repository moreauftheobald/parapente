# CLAUDE.md — Instructions pour Claude Code (projet parapente-consensus-grid)

## Présentation du projet

**parapente-consensus-grid** est le **sidecar Python** du projet
principal [moreauftheobald/parapente](https://github.com/moreauftheobald/parapente)
(la plateforme web [qui-vole.fr](https://qui-vole.fr), construite en
Laravel). Ce sidecar a deux rôles :

1. **Calculer le consensus multi-modèles météo** sur une grille
   couvrant la France métropolitaine + bordures (~150 000 cellules
   à 2.5 km de résolution), à partir des fichiers GRIB téléchargés
   par le serveur Open-Meteo auto-hébergé.
2. **Exposer une API HTTP compatible Open-Meteo** que Laravel
   interroge comme s'il s'agissait d'un modèle météo ordinaire
   (`qui_vole_consensus`). Côté Laravel, aucun code spécifique :
   le consensus est traité comme un 14ème modèle dans
   `weather_models`.

Le sidecar tourne en **conteneur Docker dédié** orchestré dans le
`docker-compose` du projet parapente. Il partage les volumes du
serveur Open-Meteo (lecture GRIB) et accède à la base MariaDB de
parapente (lecture seule) pour les settings de consensus.

---

## Cadrage canonique

Le **document de cadrage de référence** est `FF_grid_consensus.md`
à la racine de ce repo (copié depuis le projet parapente). Il
contient :
- Le concept et la valeur ajoutée (carte météo + levier de scaling
  du moteur de scoring).
- Les bornes : bbox carrée FR (lat 41-52 / lng -5 à 10), grille
  2.5 km, horizon 24 h (puis 120 h).
- Le scope des 12 variables produites.
- La logique de paramétrage par variable (méthodes A / B / C).
- Le découpage en phases (1a → 5).
- L'organisation Docker et les choix d'intégration côté Laravel.

**Toute évolution du cadrage doit y être consignée** (modifier
`FF_grid_consensus.md` puis répercuter ici si pertinent).

---

## Changelog (IMPORTANT)

Un fichier **`CHANGELOG.md`** est maintenu à la racine. Il retrace
les évolutions majeures (nouvelles méthodes de consensus, nouvelles
variables exposées, ruptures de format API, changements de schéma
NetCDF) — **en français**, la plus récente en haut.

- Après tout changement majeur, proposer à l'utilisateur de mettre
  à jour `CHANGELOG.md` (lui demander avant de l'éditer).
- Format des dates : `AAAA-MM-JJ`.
- Pas un journal de commit : seulement ce qui mérite d'être retenu.

---

## Convention de branches Git

- **`main`** : branche stable, déployable en prod. Protection
  branche activée (PR review + tests verts requis).
- **`feature/<nom>`** : branches de travail temporaires, mergées
  via PR puis supprimées.
- **`fix/<nom>`**, **`claude/*`** : même logique.
- Pas de branches versionnées (`V1`, `V2`) ici — le projet est
  petit et ne porte qu'une version active.

---

## Stack technique

| Composant            | Technologie                            |
|----------------------|----------------------------------------|
| Langage              | Python 3.12                            |
| Lecture GRIB         | `xarray` + `cfgrib` (backend eccodes)  |
| Interpolation        | `scipy.interpolate.RegularGridInterpolator` |
| Calcul vectorisé     | `numpy` (BLAS multi-thread)            |
| Stockage             | NetCDF4 compressé (`netCDF4-python`)   |
| API HTTP             | `FastAPI` + `uvicorn`                  |
| Process management   | `supervisord` (worker + uvicorn dans le même conteneur) |
| Accès DB             | `pymysql` (lecture seule sur table `settings`) |
| Cron interne         | `schedule`                             |
| Logs                 | `loguru`                               |
| Tests                | `pytest` + `pytest-asyncio`            |
| Lint / format        | `ruff` (lint + format unifié)          |
| Type checking        | `mypy` (mode strict)                   |
| Gestion deps         | `pip` + `requirements.txt` (KISS, pas de poetry/uv pour ce projet) |
| Conteneur            | `python:3.12-slim` + libeccodes + GDAL |

---

## Architecture du projet

```
parapente-consensus-grid/
├── .github/
│   └── workflows/
│       └── tests.yml              ← CI : pytest + ruff + mypy sur PR
├── docker/
│   ├── Dockerfile                 ← image Python + libeccodes
│   ├── entrypoint.sh              ← lance supervisord
│   └── supervisord.conf           ← worker (cron) + uvicorn (FastAPI)
├── src/
│   ├── __init__.py
│   ├── config.py                  ← lecture settings DB + env vars
│   ├── grib_reader.py             ← lecture GRIB Open-Meteo par modèle
│   ├── interpolation.py           ← bilinéaire vers grille 2.5 km
│   ├── netcdf_writer.py           ← écriture NetCDF compressé
│   ├── api.py                     ← FastAPI Open-Meteo-compatible
│   ├── worker.py                  ← orchestrateur cron horaire
│   └── consensus/
│       ├── __init__.py
│       ├── methods.py             ← méthode A (inverse-carré), B (MAE)
│       └── helpers.py             ← médiane pondérée, MAD, circular
├── tests/
│   ├── __init__.py
│   ├── conftest.py
│   ├── fixtures/                  ← JSON identiques à ConsensusCalculatorTest.php (parité)
│   ├── test_consensus_parity.py   ← parité Python ↔ PHP
│   ├── test_api_format.py         ← format réponse Open-Meteo
│   ├── test_interpolation.py
│   └── test_netcdf_writer.py
├── scripts/
│   ├── inspect_grib.py            ← outil dev : explorer un GRIB Open-Meteo
│   └── dry_run.py                 ← exécution one-shot du pipeline complet
├── .dockerignore
├── .gitignore
├── CHANGELOG.md
├── CLAUDE.md                      ← ce fichier
├── FF_grid_consensus.md           ← cadrage canonique (référence)
├── LICENSE                        ← AGPL-3.0
├── pyproject.toml                 ← config ruff + mypy + pytest
├── README.md
├── requirements.txt               ← deps runtime
└── requirements-dev.txt           ← deps dev (pytest, ruff, mypy)
```

---

## Variables produites

Le sidecar produit **12 champs** par cellule × heure dans le NetCDF
et les expose via l'API FastAPI (format Open-Meteo). Cf.
`FF_grid_consensus.md` § 2.7 pour la table complète :

| Champ Open-Meteo       | Variable Qui-Vole       | Type       |
|------------------------|-------------------------|------------|
| `wind_speed_10m`       | Vent moyen              | linéaire   |
| `wind_gusts_10m`       | Rafales                 | linéaire   |
| `wind_direction_10m`   | Direction du vent       | circulaire |
| `precipitation`        | Précipitations          | linéaire   |
| `relative_humidity_2m` | Humidité relative       | linéaire   |
| `temperature_2m`       | Température             | linéaire   |
| `cloud_cover_low`      | Nébulosité basse        | linéaire   |
| `cloud_cover_mid`      | Nébulosité moyenne      | linéaire   |
| `cloud_cover_high`     | Nébulosité haute        | linéaire   |
| `qui_vole_cloud_base`  | Plafond de vol estimé   | linéaire   |
| `qui_vole_models_count`| Nombre modèles contribut.| int       |
| `qui_vole_models_converging` | Nombre modèles convergents | int |

---

## Méthodes de consensus

Le sidecar implémente deux méthodes en phase 1a (cf.
`FF_grid_consensus.md` § 2.6) :

- **Méthode A — Inverse-carré pondéré** : portage 1-pour-1 de
  `ConsensusCalculator::legacyLinear/Circular` du projet parapente.
  EPSILON 0.001, moyenne pondérée 1/(distance à la médiane)².
- **Méthode B — MAE amélioré** : portage de
  `ConsensusCalculator::improvedLinear/Circular`. Filtrage outliers
  par MAD (Median Absolute Deviation), médiane pondérée.

La méthode C (MAE + fiabilité) est **prévue en phase 1b** mais
dépend de la livraison de la phase 4 du `FF_model_reliability.md`
côté parapente (extrapolation spatiale des `weight_factor`).

**Sélection par variable** : la méthode utilisée pour chaque
variable est lue depuis la table `settings` de parapente
(clés `consensus.method.*`). Le sidecar relit ces settings au
début de chaque run.

### Parité Python ↔ PHP (CRITIQUE)

Les fixtures de test PHP (`tests/Unit/Weather/Reliability/ConsensusCalculatorTest.php`
côté parapente) doivent être **exportées en JSON** et chargées par
les tests Python. Toute divergence numérique sur un cas connu
**casse la CI** — la cohérence des chiffres entre les deux
implémentations est une garantie qu'on ne sacrifie pas.

Concrètement : `tests/fixtures/*.json` contient les couples
(input, expected_output) attendus, et `test_consensus_parity.py`
vérifie que `methods.method_a(input) == expected_output` à
`np.isclose(..., rtol=1e-9)`.

---

## Intégration avec Open-Meteo et le projet parapente

### Source des données

- Le sidecar **ne télécharge pas** les GRIB lui-même. Il lit le
  volume Docker partagé avec le serveur `open-meteo-api` (du
  projet parapente) qui télécharge déjà les 13 modèles publics.
- Le chemin exact des GRIB dans le volume dépend de la version
  d'Open-Meteo — à inspecter au démarrage avec
  `scripts/inspect_grib.py` ou `docker exec open-meteo-api ls /app/data/`.

### Settings depuis MariaDB

Le sidecar lit la table `settings` du projet parapente (clés
`consensus.*`) en **lecture seule**. Un user MariaDB dédié est
recommandé :

```sql
CREATE USER 'consensus_ro'@'%' IDENTIFIED BY 'xxx';
GRANT SELECT ON parapente.settings TO 'consensus_ro'@'%';
FLUSH PRIVILEGES;
```

Credentials passés via les variables d'environnement Docker
`DB_USERNAME` / `DB_PASSWORD`.

### API consommée par Laravel

Endpoint principal :

```
GET /v1/forecast
  ?latitude=<float>
  &longitude=<float>
  &hourly=wind_speed_10m,wind_direction_10m,wind_gusts_10m,...
  &models=qui_vole_consensus
  &start_date=<YYYY-MM-DD>
  &end_date=<YYYY-MM-DD>
```

Réponse : JSON strictement compatible avec celui d'Open-Meteo
(mêmes clés, mêmes unités, même structure `hourly_units` /
`hourly`). Implémentation : lookup bilinéaire dans le NetCDF
courant à la position (lat, lng) demandée.

Endpoint de santé :

```
GET /health
→ {"status": "ok", "last_run_at": "2026-05-20T14:20:00Z",
   "grid_resolution_km": 2.5, "variables": [...]}
```

---

## Conventions de code

### Python
- **Python 3.12+** strict.
- **PEP 8** via `ruff format` (équivalent black).
- **Type hints partout**, mypy en mode strict (`disallow_untyped_defs`).
- **Modules courts**, fonctions pures quand possible (facilite les
  tests et la vectorisation).
- **Pas de classes inutiles** — préférer des fonctions pures qui
  prennent des `np.ndarray` et renvoient des `np.ndarray`. La
  classe métier est rare ici.
- **Logging via `loguru`**, pas `print`. Niveaux : DEBUG (verbose
  dev), INFO (déroulement runs), WARNING (anomalies non-bloquantes),
  ERROR (échecs).
- **Docstrings concises** sur les fonctions publiques (paramètres,
  retour, raise). Pas de roman.
- **Commentaires explicatifs uniquement quand le WHY n'est pas
  évident** (cf. règles du projet parent).

### Numpy / xarray
- **Toujours vectorisé**, jamais de boucle Python sur les cellules
  de grille.
- **`np.nan` est la valeur d'absence**, pas zéro ni sentinel.
- **Privilégier `xarray.Dataset`** quand on manipule plusieurs
  variables ensemble (gestion automatique des coordonnées) ;
  passer en `np.ndarray` pur pour les calculs critiques en perf.

### Tests
- **pytest** uniquement. Pas de unittest.
- **Fixtures** dans `tests/fixtures/`, fichiers JSON ou NetCDF.
- **Pas de tests qui font de l'I/O réseau** — tout est mocké
  ou lu depuis des fixtures.
- **Parité PHP ↔ Python** : tests dédiés dans
  `test_consensus_parity.py`, lecture des fixtures JSON exportées
  depuis le projet parapente.

---

## Commandes utiles

### Dev local (sans Docker)

```bash
# Setup environnement virtuel
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt -r requirements-dev.txt

# Tests
pytest                                  # tous les tests
pytest tests/test_consensus_parity.py   # juste la parité
pytest -k "test_method_a" -vv           # un test précis

# Lint + format
ruff check src/ tests/                  # lint
ruff format src/ tests/                 # format

# Type checking
mypy src/

# Pipeline complet en dry-run (sans Docker)
python scripts/dry_run.py --bbox 41,-5,52,10 --resolution 2.5

# Inspecter un GRIB Open-Meteo (utile pour comprendre la structure)
python scripts/inspect_grib.py /path/to/file.grib2
```

### Docker

```bash
# Build l'image
docker build -t parapente-consensus-grid:dev -f docker/Dockerfile .

# Run en local (mode dev, volumes locaux)
docker run -it --rm \
  -v $(pwd)/data/grib:/data/grib:ro \
  -v $(pwd)/data/output:/data/output \
  -p 8082:8082 \
  parapente-consensus-grid:dev

# Healthcheck
curl http://localhost:8082/health
```

### Intégration avec le projet parapente (en prod)

Le service est défini dans le `docker-compose.prod.yml` du repo
parapente (cf. `FF_grid_consensus.md` § 3.6). Côté ce repo, on
ne se préoccupe que de produire une image Docker fonctionnelle.

---

## Points d'attention

1. **Lecture GRIB read-only** — le volume des GRIB est monté en
   `:ro`. Si on tente d'écrire dedans, échec immédiat. Voulu : on
   ne doit jamais polluer le volume du serveur Open-Meteo.

2. **Synchronisation avec le serveur Open-Meteo** — Open-Meteo
   peut être en train d'écrire un GRIB quand le sidecar le lit.
   Tolérer un fichier partiel (try/except sur l'ouverture
   `xarray`), passer au modèle suivant si lecture échoue, et
   logger en WARNING.

3. **Convention direction vent** — toujours stocker en **FROM
   direction** (météo standard, conformément au projet parent).
   La conversion en direction d'affichage (`+ 180°`) se fait côté
   Laravel, pas ici.

4. **NaN propagation** — `np.nan` doit propager naturellement
   dans les calculs. Utiliser `np.nanmedian`, `np.nanmean`, etc.
   Une cellule sans aucun modèle disponible doit renvoyer NaN,
   pas 0 ni une valeur par défaut.

5. **Versioning du NetCDF** — le nom du fichier de sortie inclut
   une `CACHE_VERSION` (`run_v1_20260520_1420.nc`). Toute
   modification du schéma (ajout/retrait de variable, changement
   de type, changement de résolution) **DOIT** s'accompagner d'un
   bump de cette version pour ne pas mélanger anciens et nouveaux
   runs. L'API FastAPI lit toujours le run le plus récent par
   version.

6. **Settings cache** — le sidecar lit les settings une fois par
   run (au début du worker), pas à chaque cellule. Si tu changes
   un setting via `/admin/settings` côté Laravel, l'effet est
   visible au run suivant (au pire 1 h plus tard). Documenter
   cette latence dans l'admin parapente.

7. **Limite mémoire** — chargement de 13 modèles × 24 h ×
   12 variables peut pointer à 2-3 GB en pic. **Itérer modèle
   par modèle**, libérer les ndarrays après usage
   (`del`, `gc.collect()`). Ne PAS charger tous les modèles
   simultanément.

8. **Méthode C non disponible en phase 1a** — l'admin parapente
   peut sélectionner C dans le dropdown, mais le sidecar
   dégénère en B avec un log WARNING. Documenter clairement
   cette limite (badge « bientôt disponible » côté UI Laravel).

9. **Endpoint FastAPI : compatibilité Open-Meteo stricte** — le
   format de réponse doit être **bit-identique** à ce que renvoie
   Open-Meteo natif pour les mêmes paramètres (mêmes clés,
   mêmes unités, mêmes types). Tests d'intégration via
   `test_api_format.py` comparent à une réponse Open-Meteo de
   référence. Toute divergence casse le scoring côté Laravel.

10. **Robustesse au démarrage** — au boot du conteneur, si aucun
    run n'a encore été produit, l'API doit renvoyer
    `503 Service Unavailable` avec un message clair, pas un
    crash ni une réponse vide. Le worker doit lancer un run
    immédiatement à la première startup (ne pas attendre le
    prochain `:20`).

---

## Lien avec le projet parent (parapente)

- **Repo** : https://github.com/moreauftheobald/parapente
- **Site en prod** : https://qui-vole.fr
- **Branche active côté parapente** : `V2`
- **Documents de cadrage liés** :
  - `FF_grid_consensus.md` (ici et côté parapente) — cadrage canonique.
  - `FF_model_reliability.md` (côté parapente uniquement) — phases
    1, 2.5 livrées ; phase 4 = pré-requis pour activer la méthode
    C dans ce sidecar.
  - `CLAUDE.md` (côté parapente) — vue d'ensemble de l'app
    consommatrice.

Quand on évolue le sidecar (nouvelle variable, nouveau format API,
nouvelle méthode), **toujours vérifier l'impact côté parapente** :
- `WeatherModelSeeder` (entrée `qui_vole_consensus`).
- `config/weather.php` (mapping endpoints).
- `OpenMeteoApi.php` (parsing de la réponse).
- `ScoringService` (consommation des nouvelles variables).
