# FF — Sites masqués par utilisateur (« black-list » carte)

> **Statut : IMPLÉMENTÉ** (V2). Ce document conserve le cadrage et les
> choix d'architecture pour mémoire (cf. convention `FF_*.md`).

## Concept

Un utilisateur **connecté** peut masquer de sa **carte de volabilité**
(`/carte`) les sites qui ne l'intéressent pas. La règle par défaut est
**« tout est affiché »** ; seuls les sites explicitement marqués
« ne pas afficher » disparaissent — et uniquement pour cet utilisateur,
uniquement quand il est connecté.

La gestion se fait sur une **page dédiée** accessible depuis le menu
utilisateur (`/profil/sites-masques`), sœur de la page « Mes scorings
perso ». On y recherche un site dans la liste des sites actifs et on
bascule un bouton **Masquer / Réafficher**.

## Modèle de données

Table **`user_hidden_sites`** — modèle d'**exclusion pure** :

| Colonne | Type | Rôle |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK `users` (cascade delete) | propriétaire |
| `site_id` | FK `sites` (cascade delete) | site masqué |
| `created_at` / `updated_at` | timestamps | |

- `unique(user_id, site_id)` — un site masqué une seule fois par user.
- `index(user_id)` — lecture courante « tous les masqués du user ».
- **La présence d'une ligne = masqué.** Absence = affiché. Donc pas de
  backfill, pas de ligne par (user × site), seulement les exclusions.

Modèle Eloquent : `App\Models\UserHiddenSite` (relations `user()`,
`site()`, scope `forUser()`).

### Alternatives écartées

- **Colonne `is_blacklisted` sur `user_site_conditions`** : rejetée. Un
  site masqué n'a aucune raison de porter des conditions de vol perso ;
  fusionner les deux concepts compliquerait les caps/LRU
  (`MAX_ACTIVE` / `MAX_STORED`) des scorings perso pour aucun gain.
- **Flag par défaut « affiché » matérialisé** (une ligne par site et par
  user) : rejeté — coûteux, nécessiterait un backfill à chaque nouveau
  site et à chaque inscription.

## Architecture

Réutilise **à l'identique** le pattern de sur-couche utilisateur déjà en
place pour le scoring perso (`/api/me/scoring-overrides`).

### Backend

- **CRUD** `App\Http\Controllers\Api\UserHiddenSiteController`
  (`auth:web`) :
  - `GET    /api/users/me/hidden-sites` → `{ hidden_site_ids[], sites[] }`
  - `PUT    /api/users/me/hidden-sites/{site}` → masquer (idempotent, `firstOrCreate`)
  - `DELETE /api/users/me/hidden-sites/{site}` → réafficher (idempotent)
  - Binding sur `Site` ; identité lue depuis `$request->user()` (jamais en payload).

- **Overlay carte** `App\Http\Controllers\Api\MeHiddenSitesController`
  (`auth:web`) : `GET /api/me/hidden-sites` → `{ hidden_site_ids[], days_summary|null }`.
  - `days_summary` = agrégat journalier (`best_status` + `green_slots`)
    **recalculé sans les sites masqués**, pour que le compteur
    « X h de vol possible » du sélecteur de jour reflète ce que
    l'utilisateur voit réellement (décision produit : **exclure**).
  - `null` si aucun site masqué → le client garde l'agrégat global
    (zéro coût pour le cas dominant).
  - Recalcul **en mémoire** à partir du bundle déjà en cache Redis
    (aucune requête DB supplémentaire).

- **`MapBundleBuilder`** :
  - `buildDaysSummary` extrait en **méthode statique pure
    `summarizeDays(array $sitesPayload, array $excludedSiteIds = [])`**,
    réutilisée par le build global ET par l'overlay par utilisateur.
  - Le bundle **caché** expose désormais `green_hours_set` par site
    (day ⇒ [heures green]) — nécessaire au recalcul de `green_slots`
    excluant un site. `CACHE_VERSION` bumpée **1 → 2**.
  - **`MapBundleController::show` retire `green_hours_set`** du payload
    envoyé au client (utile seulement côté serveur) → **pas de bloat**
    sur le boot mobile.

### Front (`map/_partials/scripts/app.blade.php`)

- État `hiddenSiteIds: []` (vide pour les invités).
- Au boot, après `loadScoringOverrides`, appel `loadHiddenSites()` si
  connecté → set `hiddenSiteIds` + remplace `bestStatus`/`greenSlots`
  de `this.days` par l'agrégat recalculé.
- `_siteVisible()` : un site masqué n'est **jamais** rendu
  (`authUser && hiddenSiteIds.includes(site.id)`). Pas de toggle sur la
  carte — la page de gestion EST le panneau de contrôle.

### Page de gestion

- Route web `GET /profil/sites-masques` → `App\Http\Controllers\User\HiddenSitePageController`
  → vue `user.hidden-sites` (`<x-app-shell>`).
- UI Alpine : recherche + filtre (tous / masqués / affichés), liste des
  sites actifs (`/api/sites`) avec bascule Masquer/Réafficher (optimiste).
- Liens : menu utilisateur (navbar) + section + nav latérale de `/profil`.

## Bornes / garde-fous

- **Pas de cap** de cardinalité (un user peut masquer autant de sites
  qu'il veut — c'est sa carte).
- Filtrage **uniquement** quand connecté ; aucun effet pour les invités.
- Cascade delete sur `user_id` et `site_id` (compte ou site supprimé →
  lignes nettoyées automatiquement).
- Idempotence des deux opérations (masquer / réafficher rejouables).

## Risques identifiés

- **Bloat du bundle à l'échelle nationale** : `green_hours_set` ajoute
  quelques entiers par site/jour au bundle **caché** (retiré côté
  client). Acceptable au regard des `days` / `sun_windows` déjà portés
  par site. À surveiller si la couverture passe à plusieurs milliers de
  sites — sinon envisager un agrégat plus compact côté serveur.
- **Cohérence du compteur** : `days_summary` recalculé côté overlay
  dépend du même bundle en cache que le global ; en cas de TTL expiré,
  `getOrBuild()` reconstruit (lock anti-thundering-herd déjà en place).

## Tests

- `tests/Unit/Map/SummarizeDaysTest` — agrégat pur + exclusions (sans DB).
- `tests/Feature/Api/UserHiddenSiteApiTest` — CRUD, auth, scoping user,
  idempotence, overlay.
- `tests/Feature/User/HiddenSitesPageTest` — page + liens profil.
