# FF — Refonte du BackOffice (organisation, supervision, paramètres)

> Statut : **cadré, implémentation démarrée** (étape 1 — dashboard Supervision).
> Discussion du 2026-06-11.

## Constat (pourquoi c'est fouillis)

1. **Information de pilotage éclatée en ~6 endroits** : `/admin/logs` (tail +
   job monitors), onglets « data » de 4 pages de settings (couverture
   `DataCoverage`), `/admin/reliability/*` (4 écrans), `/admin/traffic`,
   quotas/erreurs sur les fiches APIs, bandeau de fraîcheur scoring visible
   uniquement sur la carte publique. Répondre à « est-ce que tout tourne ? »
   demande d'ouvrir 5 pages.
2. **Trois systèmes de paramètres concurrents** : `/admin/settings` (reliquat),
   `SectionSettingsController` (8 onglets météo + pages par section), écrans
   dédiés (modules, profils qualité, APIs). On ne sait pas où vit un réglage.
3. **Navigation plate mélangeant deux paradigmes** : pages « entité » (sites,
   balises, stations, users) et pages « fonction » (sync, qualité, fiabilité).
4. **Écrans legacy** (comparaison A/B/C) au même niveau que l'essentiel.

## Besoins exprimés (réponses utilisateur, 2026-06-11)

- Usage quotidien : **les 4 à la fois** — santé du pipeline, qualité des
  données, réglage du consensus, activité du site.
- Manques : vue d'ensemble santé · alertes/anomalies · tendances dans le
  temps · effet des réglages · et surtout : **une organisation des écrans qui
  permette de trouver vite l'info**, des **bulles d'aide** sur chaque
  paramètre (comprendre sur quoi il agit), **éliminer les paramètres morts**,
  **classer correctement chaque paramètre**.
- Démarrage choisi : **dashboard Supervision d'abord** (incrémental).

## Structure cible — navigation par intention (5 groupes)

```
1. SUPERVISION   ← page d'accueil admin (lecture seule, pointe vers les actions)
2. DONNÉES       sites · balises · stations · qualité (doublons) · sync/imports
3. MÉTÉO         modèles · APIs (météo + stations) · consensus (sidecar) · fiabilité
4. CONTENU & USERS  articles · wiki · modules · utilisateurs · trafic
5. SYSTÈME       settings transverses · audit · logs bruts
```

Principes :
- **Un réglage = un seul endroit.** Les settings d'une entité vivent sur la
  page de l'entité ; les transverses dans Système. Chaque clé affiche sa
  description en bulle d'aide (la colonne `description` de `settings` existe
  déjà — la rendre systématique et la réécrire si floue).
- **La supervision est en lecture seule** : elle agrège et lie, ne duplique pas.
- **Pas de big-bang** : on garde toutes les pages actuelles fonctionnelles
  pendant la transition.

## Étape 1 — Dashboard Supervision (EN COURS)

Remplace l'accueil `/admin` (compteurs simples) par un écran « santé du
système », construit par `App\Services\Admin\SupervisionService` :

| Bloc | Source | Règle d'état |
|---|---|---|
| **Sidecar scoring** | `ScoringFreshness::status()` | rouge si `stale`, âge vs seuil |
| **Pipeline jobs** | `job_monitors` (dernier run + dernier succès par classe) vs registre des cadences attendues | `failed` = dernier run en échec · `late` = dernier succès > 2× cadence + 5 min · `unknown` = aucune trace 7 j |
| **Couverture (résumé)** | requêtes légères : % sites scorés aujourd'hui (buffer actif), modèles fetchés < 2 h, balises émettrices < 2 h, stations émettrices < 2 h | lien vers les onglets « data » détaillés |
| **APIs** | `weather_apis` + `station_apis` | quota consommé, `last_error` |
| **Incidents 24 h** | `job_monitors` status=failed | liste avec messages |
| **Volumétrie** | compteurs des tables à rétention + sites/balises/stations/users | informatif |

Cache : blocs lourds (volumétrie, couverture) 5-10 min ; blocs santé en direct.
`WatchScoringTableJob` ne trace pas dans `job_monitors` (cadence 1 min) — sa
santé est portée par le bloc Sidecar.

## Étape 1bis — Logs par job/catégorie + hub de paramètres (FAIT, 2026-06-11)

- **`/admin/logs/jobs`** : explorateur des exécutions (`job_monitors`,
  rétention passée à **30 j**), filtres catégorie / job / statut, traces
  d'erreur dépliables, pagination. Les 4 jobs qui ne traçaient pas
  (`ComputeModelReliability`, `ComputeBaliseConsensusCompare`,
  `PurgeOldForecasts`, `PurgePageViews`) utilisent désormais
  `TracksExecution`, et **chaque run du sidecar** (flip détecté par
  `WatchScoringTableJob`) est journalisé sous le pseudo-job
  `sidecar:consensus-grid-v2` (groupe « sidecar »).
- **`/admin/settings` devient le hub des paramètres** : 7 onglets par
  catégorie (scoring & viabilité, balises, stations, fiabilité,
  sidecar/consensus, qualité données, trafic), toutes les clés simples de
  `Settings::DEFAULTS` éditables avec leur description en **bulle d'aide**
  (« ? »), sauvegarde par onglet, audit conservé. La config consensus par
  variable (JSON) reste dans Météo → Paramètres.

## Étapes suivantes (ordre suggéré)

2. **Réorganisation de la sidebar** en 5 groupes (pur Blade, zéro logique).
3. **Audit des ~90 clés `settings`** : tableau clé → écran propriétaire →
   consommateur réel (Laravel ou sidecar) → action (garder / déplacer /
   reformuler la description / **supprimer**). Candidats morts déjà repérés :
   `stations.retention_days` (les rétentions sont des constantes de
   `PurgeOldForecastsJob` — soit brancher, soit supprimer), groupe
   `reliability.*` du shadow mode (mourra avec lui). Reformuler les
   descriptions floues (elles sont désormais visibles partout via le hub).
4. **Consolidation des écrans de settings** : fusionner `/admin/settings`
   dans les pages par section ; rapprocher APIs météo et APIs stations
   (même pattern d'écran).
5. **Tendances** : sparklines 7-30 j sur la couverture et les erreurs
   (sources : `weather_fetch_log` 30 j, `job_monitors` 7 j — suffisant sans
   nouvelle table) ; à brancher sur le dashboard.
6. **Alertes** : d'abord visuelles (badge rouge dans la navbar admin si un
   bloc est rouge), notification e-mail ensuite si besoin.
7. **Effet des réglages** : s'appuie sur le preview consensus du sidecar
   (`consensus.global.preview_enabled`) — chantier couplé au sidecar, après
   la fiabilité stations.
8. **Suppression des écrans legacy** (compare A/B/C) avec la dépréciation du
   shadow mode (cf. `FF_grid_reliability.md` étape 5).

## Alternatives écartées

- **Big-bang de la navigation + settings d'un coup** : risque de casser les
  habitudes et les liens profonds sans gain immédiat ; l'incrémental livre la
  valeur (supervision) dès l'étape 1.
- **Page « alertes » dédiée avec moteur de règles** : sur-ingénierie au stade
  actuel ; les règles d'état du dashboard couvrent le besoin, la notification
  viendra après usage réel.
- **Historisation dédiée pour les tendances** : les tables existantes
  (`weather_fetch_log`, `job_monitors`, `page_views`) suffisent pour des
  tendances 7-30 j.

## Estimation grossière

Étape 1 : ~1 j. Étape 2 : ~0,5 j. Étape 3 (audit settings + tooltips) : ~1-1,5 j.
Étape 4 : ~1 j. Étapes 5-6 : ~1 j. Total ~5 j étalés, sans rupture.

## Risques

- **Dérive du registre des cadences** : un nouveau job schedulé doit être
  ajouté au registre du `SupervisionService` (point d'attention CLAUDE.md n°11
  étendu) — sinon il est invisible du dashboard.
- **Coût des COUNT(*)** sur les grosses tables (volumétrie) : cachés 10 min,
  à surveiller quand les tables stations grossiront.
- **Suppression de clés settings** : vérifier le consommateur **sidecar**
  avant de supprimer une clé `consensus.*` (il les lit en direct).
