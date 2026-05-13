# Changelog — Qui Vole ?

Ce fichier retrace les évolutions **majeures** du code (nouvelles fonctionnalités,
changements d'architecture, modifications de base de données, ruptures de
compatibilité). Il n'a pas vocation à lister chaque commit.

Conventions :
- Une entrée par évolution majeure, la plus récente en haut.
- Format de date : `AAAA-MM-JJ` (approximative si besoin).
- Catégories suggérées : `Ajouté`, `Modifié`, `Corrigé`, `Supprimé`, `Base de données`, `Déploiement`.

---

## 2026-05-13 — Comparaison modèles ↔ balises, phase 1 (infra collecte)

Premier jalon de la comparaison entre les prévisions des modèles météo
et les observations réelles des balises. Phase 1 : infrastructure de
collecte uniquement, prépare le calcul de fiabilité dynamique des
modèles (cf. `FF_model_reliability.md` pour le cadrage complet).

Pas d'impact utilisateur final à ce stade : on accumule de la donnée
pendant ≥ 7 jours avant d'attaquer la phase 2 (calcul de fiabilité par
modèle / horizon, écran admin de surveillance).

### Ajouté
- **`FF_model_reliability.md`** à la racine : cadrage de la feature
  complète (4 phases, ~8 j d'effort). Capture le concept (pondération
  dynamique du consensus multi-modèles via auto-apprentissage léger
  sur fenêtre 7 j glissants), le modèle de données proposé (table
  `model_reliability`), les métriques d'erreur (% direction circulaire,
  vitesse avec plancher dynamique `max(obs, 5 km/h)`), la formule de
  `weight_factor` (ratio médian clampé `[0.25, 2.0]`), les écrans admin
  (settings en onglets + tableau pivot de surveillance), le mapping
  balise→site par IDW (phase 4), les alternatives écartées et les
  risques.
- **`AggregateBaliseReadingsHourlyJob`** : agrège `balise_readings` en
  buckets horaires alignés sur l'heure pile, pour permettre une
  comparaison directe avec `forecast_archive_balises`. Direction en
  moyenne circulaire via `SUM(SIN)` / `SUM(COS)` en SQL puis `atan2`
  en PHP. Fenêtre glissante 3 h pour capter les lectures tardives,
  idempotent via upsert. Scheduler : `hourlyAt(5)`, décalé pour passer
  après les polls PiouPiou / METAR à `:00`.
- **Modèle Eloquent `BaliseReadingHourly`** + relation `balise()`.

### Base de données
- **Nouvelle table `balise_readings_hourly`** : agrégat horaire des
  lectures balises. Colonnes `balise_id` (FK cascade), `hour_at`
  (datetime, heure pile), `wind_direction` (smallint nullable),
  `wind_speed_avg` / `wind_speed_max` / `temperature` (decimal
  nullable), `readings_count` (smallint). `UNIQUE(balise_id, hour_at)`
  + index `hour_at` pour la purge. Rétention 7 jours, alignée sur la
  fenêtre J-6 → J de la comparaison à venir.

### Modifié
- **`PurgeOldForecastsJob`** : nouvelle constante
  `HOURLY_RETENTION_DAYS = 7`, purge les lignes
  `balise_readings_hourly` plus anciennes. Laisse intacts les 30 j de
  `forecast_archive_balises` (utilisés par la voting logic existante).
- **`routes/console.php`** : entrée scheduler
  `aggregate-balise-readings-hourly`, cron à `:05` toutes les heures,
  `withoutOverlapping()`.

---


Implémentation complète du scoring perso en trois lots (auth front, backend
+ cache + UI gestion, intégration carte). Cf. `FF_personnal_scoring.md`
pour le cadrage. Bloquant : authentification front livrée dans la foulée.

### Ajouté
- **Authentification front** (compte « user ») : pages publiques
  `/inscription` (`register`), `/connexion` (`login`), déconnexion
  `/deconnexion`. Form requests dédiés (8 caractères min, lettres +
  chiffres, throttle 5/min). Profil utilisateur `/profil` avec sections
  Identité (nom, pseudo unique, email, bio), changement de mot de passe
  (error bag `updatePassword`), suppression de compte (error bag
  `deleteAccount`). Approche maison sans Laravel Breeze, cohérente avec
  l'admin existant.
- **Écran complet `/profil/scorings`** (`user.scorings`) : gestion des
  scorings perso sous forme de grille de cartes responsive (1/2/3
  colonnes), une carte par scoring avec rose des vents SVG (arc favorable
  colorisé), jauge de plage de vent avec marker idéal, détails rafales /
  plafond / couverture nuages, badge actif/inactif visible, horodatage
  d'activation. Filtres dans le volet gauche (statut, recherche par nom
  de site, tri récent / nom / ancien). Compteur X/10 actifs · Y/50 stockés.
  Modal de création/édition avec validation cohérence min ≤ idéal ≤ max
  et orange < red.
- **Section résumée scorings perso** sur `/profil` (2 tuiles compteur +
  bouton « Gérer mes scorings »).
- **API REST `/api/users/me/scorings/*`** (auth:web via cookie de
  session) : `GET` (liste avec site joint), `POST` (création, contraint
  aux sites `active = true`, cap soft à 50 stockés), `PATCH`, `DELETE`,
  `POST {id}/activate` (rotation LRU dans une transaction si ≥10 actifs),
  `POST {id}/deactivate`.
- **Carte météo user-aware** :
  - Badge sur le marker (bleu plein = scoring perso actif, contour gris =
    enregistré mais inactif).
  - Filtre « Mes sites uniquement » dans le volet gauche (auth seulement),
    bandeau « Scoring perso · {pseudo} » dans le volet droit sur un site
    couvert par un scoring perso actif.
  - Onglet « Détail scoring » : cellules **split diagonales** (triangle
    haut-gauche = standard, bas-droite = perso) sur les 5 paramètres ET
    la ligne « Statut global ». Diagonale blanche élargie à 8 % pour
    rester visible même quand les deux moitiés concluent à la même
    couleur — signal qu'un scoring perso est en jeu. Tooltip JS dédié
    avec les deux libellés.
  - Couleur du halo (statut du jour) recalculée avec les conditions
    perso (réutilise les consensus déjà persistés sur `site_scores`,
    pas de fetch supplémentaire).

### Modifié
- **`/api/sites`** devient user-aware : nouveau champ `user_scoring`
  (`'active' | 'inactive' | null`) par site.
- **`/api/sites/{id}/scores`** : nouveau champ `scoring_source`
  (`'user' | 'global'`) ; quand l'user a un scoring actif sur le site,
  `status` et `detail.*.color` reflètent le scoring perso ; on conserve
  systématiquement `status_global` + `detail.*.color_global` à côté pour
  alimenter la comparaison côté carte.
- **`ScoringService`** : extraction d'une interface
  `App\Contracts\FlyingConditions` partagée par `SiteCondition` et
  `UserSiteCondition` via un trait `Concerns\HasFlyingConditions` (zéro
  duplication). Nouvelle méthode publique `rescore(FlyingConditions,
  SiteScore)` qui rejoue les règles éliminatoires + couleurs sans
  recalculer le consensus.
- **`FetchSiteForecastsJob`** : à la fin de chaque salve de fetch + scoring,
  invalide les caches `user_scoring:*` du site
  (`UserScoringService::invalidateSite`).
- **`Settings::flush()`** : invalide en plus tous les caches
  `user_scoring:*` (résolution paresseuse via `app()` pour éviter la
  dépendance circulaire).
- **`bootstrap/app.php`** : ajout de `EncryptCookies`, `StartSession` et
  `PreventRequestForgery` au groupe middleware `api` pour permettre l'auth
  via cookie de session côté API (pas de Sanctum). Redirection des
  invités passée de `admin.login` à `login`.
- **Navbar** : entrée « Mon profil » + « Mes scorings perso » dans le menu
  utilisateur (dropdown en haut à droite). Le formulaire de connexion en
  popover poste désormais vers `login` (front), plus vers `admin.login`.

### Base de données
- **Migration `users`** : ajout de `pseudo` (string unique nullable) et
  `bio` (text nullable).
- **Nouvelle table `user_site_conditions`** : miroir de `site_conditions`
  (`wind_dir_min/max`, `wind_speed_min/max/ideal`, override rafales
  orange/red, `cloud_base_min_m`, `cloud_cover_low_max`, `notes`) +
  `user_id` / `site_id` (cascadeOnDelete) + `is_active` (bool) +
  `activated_at` (datetime nullable). `UNIQUE(user_id, site_id)`, index
  composite `(user_id, is_active, activated_at)` pour la requête LRU.
- **Migrations patchées pour SQLite** : `change_balises_source_to_string`
  et les deux `make_*_nullable_in_sites` deviennent no-op sous SQLite (le
  `ALTER TABLE ... MODIFY` n'y est pas supporté). Permet aux tests
  features de tourner.

### Architecture
- **Nouveau service `App\Services\Weather\UserScoringService`** : calcule
  les scores perso à la volée à partir des consensus déjà persistés sur
  `site_scores` (pas de duplication en base), cache Redis 1 h avec clé
  `user_scoring:{user_id}:site:{site_id}`. Activation transactionnelle
  avec rotation LRU des 10 actifs max (constante
  `UserSiteCondition::MAX_ACTIVE`). Cap soft à 50 stockés
  (`MAX_STORED`).

### Tests
- 26 nouveaux tests features et unitaires : authentification (register,
  login, profil), API user scorings (CRUD, autorisations, cohérences,
  LRU), service user scoring (rescore, cache, invalidations), API sites
  user-aware (`user_scoring`, `scoring_source`, `color_global`,
  `status_global`), page `/profil/scorings`. Suite complète à 54/54.
- **`tests/TestCase`** : `withoutVite()` global (les vues Blade
  rendraient une 500 sans `public/build/manifest.json`).

### Déploiement
- `php artisan migrate` pour appliquer les deux nouvelles migrations.
- `php artisan optimize:clear` après déploiement (les vues map / profil
  sont recompilées).

---

## 2026-05-13 — Écran admin « Paramètres généraux » + seuils paramétrables

### Ajouté
- **Page admin `/admin/settings`** (lien « Paramètres » dans la sidebar
  admin, icône ⚙) qui regroupe les seuils globaux du scoring : trois
  sections (Précipitations / Rafales / Viabilité d'une journée) avec une
  description par champ. Enregistrement en un clic via `PATCH
  /admin/settings`.
- **Table `settings`** (clé unique + valeur JSON + label / description),
  alimentée par `SettingsSeeder` à partir du catalogue `Settings::DEFAULTS`.
- **Service `App\Services\Settings`** : accès cache (Redis, 1 h, invalidé
  à chaque écriture) avec catalogue des défauts comme fallback. API :
  `get($key, $default)`, `all()`, `set($key, $value)`, `setMany([...])`,
  `flush()`. Lié en singleton dans `AppServiceProvider`.
- **Override des rafales par site** : nouveaux champs `wind_gust_orange_kmh`
  et `wind_gust_red_kmh` (nullable) sur `site_conditions`, exposés dans la
  fiche d'édition du site (admin). Laisser vide pour utiliser la valeur
  globale ; remplir pour surcharger.

### Modifié
- **Précipitations · règle de scoring** : la voting logic n'utilise plus
  qu'un critère sur le **consensus** (vs. l'ancienne règle « ≥1 modèle
  prévoit de la pluie »). Deux seuils globaux paramétrables : `scoring
  .precip_orange_mmh` (au-delà → orange) et `scoring.precip_red_mmh`
  (au-delà → rouge). Défauts : `0.0` et `0.1` mm/h.
- **Rafales · règle de scoring** : `scoring.gust_orange_kmh` et
  `scoring.gust_red_kmh` deviennent éditables (défauts 25 / 35 km/h),
  surchargeables par site (cf. ci-dessus).
- **Viabilité du jour** : les paramètres `viability.peak_hour`,
  `viability.sigma`, `viability.val_green`, `viability.val_orange`,
  `viability.run_base`, `viability.run_step`, `viability.green_threshold`,
  `viability.orange_threshold` deviennent éditables (anciennement des
  constantes dans `SiteController`).
- **ScoringService** : nouvelle dépendance `Settings` (injectée par
  DI). Constantes `GUST_ORANGE_KMH` / `GUST_RED_KMH` retirées.

### Supprimé
- **`site_conditions.precip_max`** (colonne + UI + helper
  `isPrecipitationAcceptable`) : remplacée par les seuils globaux. La
  migration de retour (down) recrée la colonne avec la valeur par défaut
  `0.0` pour permettre un éventuel rollback.

### Base de données
- **`+ settings`** (`key` unique, `value` JSON, `label`, `description`).
- **`site_conditions`** : `- precip_max`, `+ wind_gust_orange_kmh`,
  `+ wind_gust_red_kmh` (nullable, decimal 5,1).

### Déploiement
- Après `php artisan migrate --force`, exécuter
  `php artisan db:seed --class=SettingsSeeder --force` pour poser les
  valeurs par défaut. Idempotent.

---

## 2026-05-13 — Onglet « Détail du scoring » (voting logic)

### Ajouté
- **Nouvel onglet « Détail scoring · 5 jours »** dans le volet droit des
  sites, intercalé entre « Synthèse » et « Modèles météo · {jour} ». Affiche
  cinq tableaux empilés (J → J+4), un par jour. En lignes : les cinq
  paramètres de la voting logic — *Direction*, *Vitesse*, *Rafales*,
  *Précipitations*, *Plafond* — plus une ligne *Statut global* en bas. En
  colonnes : les heures de la fenêtre solaire. Chaque cellule est une
  pastille colorée (vert *OK* / orange *Prudence* / rouge *Éliminatoire* /
  gris *N/A*) avec tooltip natif (consensus + convergence).
- **`ScoringService::computeParamColors()`** (méthode publique) : isole la
  coloration par paramètre, alignée sur `applyEliminatoryRules()`. Direction
  et vitesse moyenne en binaire vert/rouge (dans la plage / hors plage),
  rafales selon les seuils 25/35 km/h, précipitations vert/orange/rouge
  selon consensus et présence d'au moins un modèle annonçant de la pluie,
  plafond *informatif* à partir de `site_conditions.cloud_base_min_m` (rouge
  en-dessous, orange dans une marge de 100 m, vert au-dessus).
- **Commande artisan `scores:recompute-detail-colors`** : backfill des
  `site_scores` déjà en base (lit les consensus et `precip.values` persistés
  dans `detail`, applique `computeParamColors`, met à jour `detail.*.color`).
  Options `--site=` et `--chunk=`, idempotente, sans refetch météo.

### Modifié
- **`site_scores.detail`** : chaque sous-bloc (`wind_dir`, `wind_speed`,
  `wind_gust`, `precip`, `cloud_base`) reçoit un champ `color` ∈ {green,
  orange, red, unknown}.
- **`GET /api/sites/{id}/scores`** expose désormais un champ `detail`
  allégé par créneau (consensus + convergence + color, sans les `values`
  brutes pour limiter le payload), ainsi que `wind_gust` et `cloud_base`.

---

## 2026-05-12 — Refonte de l'écran carte météo

### Ajouté
- **Volet gauche en onglets** « Paramètres » / « Légende », repliable en barre
  étroite (☰). *Paramètres* : calque balises, affichage des sites par statut
  (favorables / incertains / défavorables), sélecteur de fond de carte
  (dropdown). *Légende* : pictogrammes des sites (halo de statut) et des
  balises (couleur = force du vent, fond = fraîcheur du relevé).
- **Volet droit « site »** : titre sur une ligne (nom · niveau · orientation
  favorable · ☀ lever → coucher) + 3 onglets — « Synthèse · {jour} » (graphe
  nuages + vent min/moy/max + flèches de direction, certitude de la prévision,
  bargraph du plafond de vol min/consensus/max), « Modèles météo · {jour} » et
  « Modèles · 5 jours » (6 graphes multi-modèles + consensus).
- **Volet droit « balise »** : titre sur une ligne (nom · réseau · maj il y a …)
  + onglet « Relevés météo » — dernier relevé synthétique (direction, vitesse,
  rafales/min, temp/hum), rose des vents heure par heure, graphe vitesse du jour.
- **Estimation du plafond de vol** (base des cumulus), heure par heure, en
  altitude absolue (règle d'Espy : `elevation_modèle + 125 × (T₂ₘ_max_jour −
  Td₂ₘ)`), avec consensus multi-modèles (même voting logic) — affiché en
  bargraph (onglet Synthèse) et en courbes par modèle (onglets Modèles).
- Sélecteur de jour flottant dans le coin haut-droite de la carte ; barre de
  menu globale (`partials.app-shell-navbar`) sur l'écran carte ; clic sur un
  marqueur → ouverture du volet droit (qui occupe la moitié de l'écran).

### Modifié
- **Scoring horaire** : prise en compte des **rafales** (consensus de
  `wind_speed_max`) — > 35 km/h → rouge (éliminatoire), 25-35 → orange. Avant,
  seules la direction et la vitesse moyenne entraient dans le statut.
- **Couleur des marqueurs / sélecteur de jour** : basée sur une **qualité de
  journée** (viabilité = continuité des créneaux volables × poids des créneaux
  de milieu de journée, calculée à la lecture) au lieu de « une heure verte →
  marqueur vert ».
- **Plafond** : passage de la formule de Henning (point de rosée dérivé de
  l'humidité, valeur AGL) à Espy avec `dew_point_2m` du modèle,
  `temperature_2m_max` comme température de déclenchement, et l'élévation du
  point de grille comme référence → valeur en altitude absolue (ASL).
- Suppression de l'ancienne barre d'outils de la carte (le sélecteur de jour
  passe en flottant) ; partials obsolètes supprimés (`map/_partials/html/
  {popup-chart,balise-popup,panel}.blade.php`, `styles/popup`, `html/
  {toolbar,legend}`).

### Corrigé
- **Convention de cap de vent des balises** : OpenWindMap (PiouPiou) renvoie
  `wind_heading` en convention TO (direction *vers laquelle* souffle le vent) ;
  `PiouPiouProvider` la normalise désormais en convention FROM (météo standard,
  contrat de `BaliseProviderInterface`), et `baliseIconUrl` transmet la valeur
  telle quelle à SpotAir. Corrige l'incohérence rose des vents ↔ icône carte.
- Unité du plafond dans les tooltips des graphes (« m » au lieu de « km/h »).

### Base de données
- `site_scores.cloud_base_consensus` (plafond de vol consensus, m ASL,
  nullable).

### API / Météo
- `OpenMeteoApi` requête en plus `dew_point_2m` (hourly) et `temperature_2m_max`
  (daily).
- `/api/sites/{id}/scores` expose `day_quality` (viabilité + statut par jour) ;
  `/api/sites/{id}/chart` expose `cloud_base`, `cloud_base_min/max` par heure ;
  `/api/sites/{id}/multimodel` expose `cloud_base` par modèle + dans le consensus.

### Déploiement
- `php artisan migrate --force` (colonne `cloud_base_consensus`).
- Re-fetch + re-scoring des sites actifs (formule plafond, normalisation cap
  balises, seuil rafales) : `Site::active()->get()->each(fn($s) =>
  FetchSiteForecastsJob::dispatchSync($s->id))`.
- `php artisan optimize:clear` (vues Blade).
- Le scheduler relance le fetch horaire automatiquement ensuite.

---

## 2026-05-12 — Interface globale, articles & modules

### Ajouté
- **Shell d'interface global** (`<x-app-shell>`) commun à tous les écrans :
  barre de menu supérieure (liens des modules selon les droits + formulaire de
  connexion en menu déroulant), panneau latéral gauche « détail », panneau
  latéral droit « aide / légende / actions ». Navbar en partial
  `resources/views/partials/app-shell-navbar.blade.php`.
- **Page d'accueil** (`/`) : affiche les articles publiés empilés
  verticalement, du plus récent au plus ancien.
- **Module Articles / Changelog** dans l'admin (`/admin/articles`) : éditeur
  WYSIWYG TinyMCE (chargé via CDN), upload d'images sur le disque `public`
  (route `POST /admin/articles/upload-image`), publication/dépublication.
- **Gestion des modules** dans l'admin (`/admin/modules`) : par module —
  actif (oui/non), niveau de droit (`guest` / `user` / `admin`), compte
  utilisateur obligatoire (oui/non). L'affichage dans la barre de menu en
  découle (`Module::isVisibleFor()`).

### Modifié
- Le BackOffice (`layouts/admin.blade.php`) repose désormais sur `<x-app-shell>` ;
  la navigation des sections admin est passée dans le panneau latéral gauche.
- Route par défaut `/` → page d'accueil (était la carte). La carte est
  désormais sur `/carte` (route nommée `map` inchangée).
- `App\Support\Navigation` lit la liste des modules depuis la base de données
  (fallback : menu vide si la table n'est pas encore migrée).

### Base de données
- Nouvelle table `modules` (clé, label, icône, route, `is_active`,
  `access_level`, `requires_registration`, `sort_order`) + `ModuleSeeder`.
- Nouvelle table `articles` (titre, corps HTML, `author_id`, `is_published`,
  `published_at`).

### Supprimé
- `config/modules.php` (remplacé par la table `modules`).

### Déploiement
- Mis en production le 2026-05-12 (branche `V2`).
- Penser à `php artisan migrate`, `php artisan db:seed --class=ModuleSeeder`
  et `php artisan storage:link` (images des articles).
- Procédure de déploiement de `CLAUDE.md` complétée : recréer un conteneur PHP
  impose de **redémarrer Nginx** (IP figée → 502), re-`chown` `storage`/`bootstrap/cache`,
  `optimize:clear` + `optimize`, puis `restart parapente-php` & `restart parapente-nginx`.

---

## 2026-05 — Météo auto-hébergée (Open-Meteo)

### Modifié
- Bascule vers un **serveur Open-Meteo dédié** (image `open-meteo/open-meteo`)
  agrégeant ~13 modèles publics. Suppression des fetch directs sur Météo-France
  DPS, DWD OpenData, ECMWF Open Data, MET Norway.
- URL configurable via `OPEN_METEO_BASE_URL` ; appel batch multi-coordonnées
  pour les balises (40 points / chunk), timeout 60 s.

---

## 2026-05 — BackOffice

### Ajouté
- BackOffice `/admin` : authentification (rôle admin), gestion des sites,
  des balises, des modèles météo, des APIs météo, synchronisation des données,
  logs / monitoring, gestion des utilisateurs.

---

## 2026-05 — Module 1 : Carte météo

### Ajouté
- Vue carte (`/carte`) : marqueurs colorés par statut de vol, popup graphique
  (tuiles nuageuses, bargraphes vent, flèches direction), side panel timeline.
- API REST : `/api/sites`, `/api/sites/{id}/scores`, `/api/sites/{id}/chart`.
- Services météo : `OpenMeteoApi`, `ForecastFetcher`, `ScoringService`
  (voting logic, moyenne circulaire des directions, règles éliminatoires).
- Jobs : `FetchForecastsJob`, `FetchSiteForecastsJob`.
- Modèle de données : `sites`, `site_conditions`, `weather_models`,
  `forecasts`, `site_scores`, `balises`, `balise_readings`.
