# Changelog — Qui Vole ?

Ce fichier retrace les évolutions **majeures** du code (nouvelles fonctionnalités,
changements d'architecture, modifications de base de données, ruptures de
compatibilité). Il n'a pas vocation à lister chaque commit.

Conventions :
- Une entrée par évolution majeure, la plus récente en haut.
- Format de date : `AAAA-MM-JJ` (approximative si besoin).
- Catégories suggérées : `Ajouté`, `Modifié`, `Corrigé`, `Supprimé`, `Base de données`, `Déploiement`.

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
