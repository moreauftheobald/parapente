# FF — Scoring personnel par utilisateur

> **Statut** : à planifier (note de design)
> **Date de rédaction** : 2026-05-13
> **Pré-requis bloquant** : authentification front (Laravel Breeze) non encore livrée.

Ce document consigne la discussion de cadrage. Il sert de point de reprise
quand on décidera d'implémenter la feature.

---

## Concept

Permettre aux utilisateurs enregistrés (compte front) de définir leurs
**propres conditions de scoring** (direction, vitesse min/max, rafales,
plafond, …) pour un **certain nombre de sites**.

Quand un utilisateur est connecté et qu'il consulte la carte ou la fiche
d'un site sur lequel il a un scoring perso **actif**, c'est ce scoring qui
est utilisé pour colorier le marqueur et alimenter le volet droit. Sinon,
le scoring global continue de s'appliquer (statu quo).

Précisions retenues :

- L'utilisateur peut enregistrer **un nombre illimité** de scorings perso
  en stock (cap soft à 50 environ pour éviter les abus), mais **10 actifs
  maximum** à un instant T.
- Activer un 11ᵉ scoring **désactive automatiquement le plus ancien**
  (LRU sur la date d'activation `activated_at`). Cela permet par exemple
  de « déplacer » son jeu de scorings d'une région à l'autre pendant des
  vacances sans tout reconfigurer.
- L'icône d'un site porte deux badges possibles :
  - **Actif** : badge plein, couleur vive.
  - **Inactif** (scoring enregistré mais pas activé) : badge contour seul,
    couleur grise.
- La carte propose un filtre **« Mes sites »** qui affiche les sites avec
  scoring perso (actifs + inactifs — les badges les distinguent).
- Le volet droit affiche une **mention « Scoring perso · {pseudo} »**
  quand le scoring perso est en cours d'utilisation.
- Un **onglet de comparaison** dans le volet droit permet de voir côte à
  côte le scoring global et le scoring personnel. Le rendu retenu : dans
  l'onglet « Détail scoring », chaque cellule est **divisée en diagonale**
  (un triangle pour le scoring global, l'autre pour le scoring perso).

Restrictions :

- Les scorings perso ne peuvent être créés que sur les sites **actifs**
  (`sites.active = true`). La régulation se fait donc côté admin en
  activant / désactivant les sites.
- Si un admin désactive un site sur lequel des users ont un scoring perso,
  les `user_site_conditions` restent en base (**option dormante**) : le
  site disparaît de la carte tant qu'il est inactif, mais le réglage est
  préservé pour le jour où il sera réactivé. Pas de cascade, pas de purge.

---

## Architecture retenue

### Modèle de données

Une nouvelle table `user_site_conditions`, miroir de `site_conditions`
avec PK composite et 2 colonnes pour la rotation :

```
user_site_conditions
├── id (PK)
├── user_id (FK users, cascadeOnDelete)
├── site_id (FK sites, cascadeOnDelete)
├── is_active (bool, default false)
├── activated_at (datetime, nullable)    ← horodatage pour LRU
├── wind_dir_min, wind_dir_max
├── wind_speed_min, wind_speed_max, wind_speed_ideal
├── wind_gust_orange_kmh (nullable)      ← override des seuils globaux
├── wind_gust_red_kmh    (nullable)
├── cloud_base_min_m
├── cloud_cover_low_max
├── notes
├── timestamps
├── UNIQUE (user_id, site_id)
└── INDEX (user_id, is_active, activated_at)   ← pour la requête LRU
```

L'index composite permet de trouver le « plus ancien actif » d'un user en
une requête : `WHERE user_id=? AND is_active=1 ORDER BY activated_at ASC
LIMIT 1`.

Les colonnes reprennent celles de `site_conditions` : on peut donc
réutiliser tels quels les helpers `isWindDirectionFavorable()`,
`isWindSpeedFavorable()`, ainsi que les méthodes
`ScoringService::computeParamColors()` /
`applyEliminatoryRules()` — il suffit de leur passer une instance de
`UserSiteCondition` au lieu de `SiteCondition`.

### Calcul des scores : à la volée + cache Redis

On **ne persiste pas** une copie complète des scores par user-site dans
MariaDB. Le scoring perso est calculé à la volée à la première demande,
puis caché dans Redis.

| Approche | Coût compute | Coût stockage | Latence |
|----------|--------------|---------------|---------|
| Persisté en DB | Linéaire en U × sites_perso × créneaux. 1 000 users × 10 sites × 120 créneaux = 1,2 M lignes/h, ~6 GB. | Lourd. | Instantané. |
| **À la volée + cache Redis** | Quasi nul à l'écriture, payé au 1ᵉʳ accès (~50-200 ms pour 120 créneaux × 13 modèles déjà en base). | ~50 MB Redis pour 1 000 users × 10 actifs × 5 KB. Indolore. | 50-200 ms au 1ᵉʳ affichage, puis instantané. |

**Retenue : approche cache.**

Mécanique du cache :
- Clé : `user:{user_id}:site:{site_id}:scores`
- TTL : aligné sur le fetch horaire (~1 h)
- **Calculé uniquement pour les scorings actifs** (les inactifs ne tournent
  jamais le `ScoringService` — ils ne consomment ni compute ni cache).
- Invalidations :
  - (a) Fin de `FetchSiteForecastsJob` pour le site → purger toutes les
    clés `user:*:site:{site_id}:scores`.
  - (b) Édition du scoring perso → purger sa clé.
  - (c) Désactivation (LRU auto ou manuelle) → purger sa clé.
  - (d) Modification des `settings` globaux par l'admin → purge globale
    `user:*` (TTL 1 h limite déjà le dégât naturellement).

### Logique d'activation (LRU)

Un endpoint `POST /api/users/me/scorings/{id}/activate` qui dans **une
seule transaction** :

1. Compte les scorings actifs de l'user.
2. Si ≥ 10 : trouve le plus ancien (`MIN(activated_at)`), le passe à
   `is_active=false`, purge sa clé Redis.
3. Active le scoring demandé, met `activated_at = now()`.
4. Renvoie au front la liste des actifs + un message du type « le scoring
   sur *Markstein* a été désactivé ».

Le verrou de transaction règle la race condition si l'user clique deux
fois rapidement sur activer.

### API : user-aware, pas de nouvel endpoint

Pas besoin d'endpoint dédié pour la lecture. `/api/sites` et
`/api/sites/{id}/scores` deviennent **user-aware** :

- Si la requête est authentifiée et que l'user a un scoring perso
  **actif** sur le site → le payload renvoie le scoring perso pour
  `status`, `confidence`, `detail.*.color`.
- Un champ `scoring_source: 'user' | 'global'` est ajouté pour que le
  front sache afficher le bandeau / les badges.
- Pour l'onglet de comparaison, on enrichit chaque entrée `detail.<param>`
  avec un `color_global` à côté du `color` (équivalent perso). En un seul
  appel le front a tout pour rendre la cellule diagonale.

Pour les badges sur l'icône, `/api/sites` ajoute :
`user_scoring: 'active' | 'inactive' | null`.

Le cache HTTP (s'il existait) devrait varier par user (`Vary: Cookie` ou
clé user-keyed). Aujourd'hui il n'y en a pas, donc neutre.

---

## Frontend

### Vue carte
- 1 toggle « Mes sites » dans le volet gauche params, qui filtre sur
  `user_scoring !== null`.
- Badge sur `siteIconHtml` : un petit path SVG supplémentaire selon
  `user_scoring` (rien / contour seul / plein).
- Pas d'autre changement pour la sélection du statut journalier.

### Volet droit (site)
- Bandeau en haut du volet « Scoring perso · {pseudo} » quand
  `scoring_source === 'user'`. Petite icône utilisateur.
- Onglet « Détail scoring · 5 jours » : si un scoring perso s'applique,
  chaque cellule devient **un carré divisé en diagonale**, partie
  haute-gauche = global, partie basse-droite = perso (ou l'inverse, à
  arbitrer visuellement). Couleurs : mêmes pastilles green / orange /
  red / na que pour le scoring normal.
- Tooltip natif HTML insuffisant (deux valeurs sur une cellule) → besoin
  d'un mini-tooltip JS (Alpine, ~20 lignes) pour afficher « Global : OK
  (88%) — Perso : Prudence (62%) » au survol.

### Profil user (UI nouvelle)
La pièce la plus riche à développer :
- Liste des sites actifs (filtrable, recherche).
- Pour chaque site : indicateur d'état (aucun scoring / inactif / actif),
  bouton « éditer / activer / désactiver / supprimer ».
- Formulaire de création / édition d'un scoring perso (mêmes champs que la
  fiche site admin section « Conditions de vol favorables » : direction
  min/max, vitesse min/max/idéale, rafales orange/rouge override, plafond
  min, couverture nuages, notes).
- Compteur visible « *X / 10 actifs* » + modal d'avertissement quand
  l'activation va déclencher un LRU sur un autre scoring.
- Validation cohérence (`wind_speed_min < max`, `wind_gust_orange <
  red`) côté front et côté back.

---

## Bornes / garde-fous

| Borne | Valeur | Justification |
|-------|--------|---------------|
| Max **actifs** par user | 10 (LRU auto) | Demande explicite. Borne le compute et le cache. |
| Max **stockés** (actifs + inactifs) par user | 50 *(à valider)* | Évite qu'un user malveillant ne crée 100 000 lignes. Validation à la création. |
| Min interval entre 2 activations | aucun | LRU absorbe les changements rapides. |

---

## Estimation de coût (en cas de feu vert)

| Lot | Estimation |
|-----|-----------|
| Auth front Breeze (login + registration + UI profil basique) | 1 j |
| Table `user_site_conditions` + modèle + helpers user-aware | 0,5 j |
| Refactor `ScoringService` / API user-aware + cache Redis + invalidations | 1–1,5 j |
| Endpoint activation + logique LRU (transaction, désactivation auto) | 0,3 j |
| UI profil user (liste sites, CRUD scorings perso, toggle activate, compteur X/10) | 2 j |
| Vue carte : 2 badges + filtre + bandeau panneau droit | 0,5 j |
| Onglet comparaison · cellule split diagonale + tooltip différencié | 1 j |
| Recettage | 0,5 j |
| **Total** | **~7 j** |

## Ordre de découpage suggéré (en 3 PR)

1. **Breeze auth + registration + UI minimaliste de profil**
   *(livrable autonome, utile même sans scoring perso)*
2. **Backend scoring perso + cache Redis**
   *(POC : visible côté API mais pas encore exploité par la carte)*
3. **UI carte** : badges, filtre, bandeau panneau droit, onglet comparaison

Ce découpage permet de valider chaque couche indépendamment.

---

## Risques résiduels

1. **Auth front** : tout dépend de Breeze. Pas un risque technique, mais
   un investissement préalable à acter.
2. **Onglet comparaison · split diagonale** : faisable en CSS
   (`linear-gradient(135deg, …)`) ou en 2 triangles SVG, mais le tooltip
   doit gérer deux valeurs par cellule → mini-tooltip JS custom (Alpine).
3. **Audit trail** : on stocke `activated_at` (timestamp courant), pas
   l'historique. Si l'on veut plus tard un journal « j'ai activé le
   scoring X le 15/05 puis Y le 17/05 », il faudra une table à part.
   Hors scope V1.
4. **Migration users existants** : nulle, tout est opt-in
   (`is_active = false` par défaut). Pas de backfill nécessaire.
5. **Désactivation d'un site par l'admin** : option dormante (réglages
   préservés, site simplement filtré de la carte). À documenter dans
   l'admin pour éviter la surprise.
