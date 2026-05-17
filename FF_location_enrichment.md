# FF — Enrichissement géolocalisation (pays / région / département)

**Statut :** En cours d'implémentation
**Priorité :** Filtres admin (sites, balises) + filtres scoring perso
**Dépendances externes :**
- `geo.api.gouv.fr` (Découpage administratif) — point-in-polygon sur les communes françaises ; couvre 100 % des coords FR même en pleine montagne, sans rate limit
- `nominatim.openstreetmap.org` (OSM) — fallback hors France, sans clé, 1 req/s max

> **Historique** : une première implémentation utilisait BAN
> (`api-adresse.data.gouv.fr`) en premier tier. Abandonné après
> constat que BAN renvoie `not-found` pour la majorité des sites de
> parapente (loin de toute adresse postale indexée). geo.api.gouv.fr
> couvre 100 % des coords FR en une seule API, sans rate limit,
> donc BAN devenait redondant.

---

## 1. Description fonctionnelle

Enrichir chaque site de vol et chaque balise avec leur découpage administratif
(pays, région, département) à partir de leurs coordonnées GPS, pour permettre :

1. **Filtres dans le BackOffice** sur les listes `/admin/sites`, `/admin/balises`
   (et plus tard `/profil/scorings`) : par pays, par région, par département.
2. **Enrichissement automatique** à la création d'un nouveau site/balise via
   un job async (observer Eloquent).
3. **Enrichissement en masse** des ~1 100 sites et ~270 balises existants via
   une commande artisan one-shot, idempotente, relançable.

Volumétrie actuelle : ~1 370 entrées à géocoder. Croissance prévue : ~quelques
dizaines de nouvelles entrées par mois (création sites par les admins,
découverte automatique des balises FFVL/METAR/Windy).

---

## 2. Stratégie hybride : BAN d'abord, Nominatim en fallback

### 2.1 Pourquoi hybride

| Provider | Forces | Limites |
|----------|--------|---------|
| **BAN** (`api-adresse.data.gouv.fr`) | Officiel, ultra-rapide, batch CSV illimité, qualité élevée sur la France | France métropolitaine + DOM uniquement |
| **Nominatim** (OSM public) | Couverture mondiale (BE, LU, DE, CH, IT…) | Rate-limité (1 req/s), Acceptable Use Policy stricte |

Les sites/balises sont majoritairement français (>80 % attendus) mais une part
non négligeable est en Belgique, Luxembourg, Allemagne, Suisse — d'où la
nécessité d'un fallback.

### 2.2 Algorithme

**Mode batch (one-shot artisan)** :

1. Récupère toutes les coordonnées à géocoder (`geocoded_at IS NULL` ou `--force`).
2. Envoie un POST CSV par chunks de N points à `https://api-adresse.data.gouv.fr/reverse/csv/`.
3. Parse la réponse : pour chaque ligne où `result_score >= 0.3` ET `result_context` non vide
   → c'est un point français, on enregistre depuis BAN.
4. Les lignes sans match (BAN ne retourne rien à l'étranger) sont passées une à une
   à Nominatim, avec respect du 1 req/s.
5. Pour chaque enregistrement OK : write `country_code` + `country` + `admin_region` +
   `department` + `geocoded_at = now()` + `geocoded_provider = 'ban'|'nominatim'`.
6. Les points qui échouent aux deux providers restent `geocoded_at = NULL`
   → relançables au prochain run.

**Mode unitaire (job async à la création)** :

1. Reverse BAN single-point (`GET /reverse/?lat=&lon=`).
2. Si pas de match ou score faible → Nominatim single-point.
3. Si les deux échouent → log + retry job avec backoff exponentiel (3 tentatives).

### 2.3 Heuristique « FR vs étranger »

**Pas de bbox a priori.** On laisse BAN décider : il renvoie quasi rien (score < 0.3,
ou pas de `result_context`) pour des coords hors France. C'est plus fiable qu'une
bbox géométrique qui raterait les cas frontaliers (Volmerange-les-Mines, Markstein,
sites alpins limitrophes).

Trade-off : on paye un appel BAN inutile pour les points étrangers (mais BAN est
gratuit, illimité, et rapide → coût négligeable).

---

## 3. Risques identifiés et mitigations

| Risque | Mitigation |
|--------|-----------|
| **Hétérogénéité des libellés BAN vs Nominatim** (« Grand Est » vs « Région Grand Est ») | Normalisation à l'écriture : `trim`, retrait préfixes « Région » / « Département de » / « Province de ». Documenter que la valeur stockée est le libellé du provider (FR pour BAN, FR via accept-language=fr pour Nominatim). |
| **Concept région/département absent hors France** | Belgique : `admin_region` = région (Wallonne, Flamande, Bruxelles-Capitale), `department` = province. Luxembourg : `admin_region` = canton, `department` = NULL. Allemagne : `admin_region` = Bundesland, `department` = Landkreis (souvent NULL). Accepter l'hétérogénéité dans l'UI (les filtres restent utiles par pays + par région). |
| **Quota Nominatim dépassé** (Acceptable Use Policy : 1 req/s + bulk usage interdit) | Respect strict du rate limit via un `Cache::lock` distribué (1 req/s globalement, multi-worker safe). User-Agent identifié (`qui-vole.fr (contact@qui-vole.fr)`). |
| **BAN down** | Fallback immédiat à Nominatim, log warning. |
| **Nominatim down sur fallback étranger** | Job retry exponentiel (job mode), commande relançable (batch mode). Pas de cascade — les FR continuent de passer via BAN. |
| **Évolution des nomenclatures** (fusion régions, renommage Bundesland, etc.) | Commande `--force` pour re-géocoder à la demande. Pas de re-géocodage automatique périodique (la table change rarement). |
| **Doublons de libellés dans les filtres** (« Moselle » BAN + « Moselle » Nominatim → OK, mais « Liège (province) » vs « Province de Liège » → différents) | Normalisation post-géocodage (lowercase pour la comparaison, dédoublonnage côté query GROUP BY DISTINCT). Si problème en prod, table de mapping curatée plus tard. |

---

## 4. Modèle de données

### 4.1 Nouvelles colonnes (table `sites` ET `balises`)

| Colonne | Type | Description |
|---------|------|-------------|
| `country_code` | `CHAR(2) NULL` | ISO-3166-1 alpha-2 (`FR`, `BE`, `LU`, `DE`, `CH`, `IT`, `ES`…) en majuscules |
| `country` | `VARCHAR(80) NULL` | Nom du pays en français (`France`, `Belgique`, `Luxembourg`…) |
| `admin_region` | `VARCHAR(120) NULL` | Région (FR) / Région (BE) / Bundesland (DE) / Canton (LU) |
| `department` | `VARCHAR(120) NULL` | Département (FR) / Province (BE) / Landkreis (DE) / NULL pour LU |
| `geocoded_at` | `TIMESTAMP NULL` | Horodatage du dernier géocodage réussi |
| `geocoded_provider` | `VARCHAR(20) NULL` | `'ban'` ou `'nominatim'` |

Indexes :
- `INDEX (country_code)` — filtre fréquent par pays
- `INDEX (department)` — filtre fréquent par département
- `INDEX (country_code, admin_region)` — pour les dropdowns en cascade éventuels

> **Le champ `region` existant** sur `sites` (libellé libre, ex: « grand-est »,
> « Grand Est » selon la source) est **conservé tel quel** pour rétrocompat
> avec l'admin existant. Les nouveaux filtres utilisent uniquement les
> colonnes `geo_*` normalisées. À terme (V3 ?), `region` pourra être déprécié.

### 4.2 Modèles Eloquent

- `Site` : ajout des 6 colonnes en `$fillable`, casts standards.
- `Balise` : idem.
- Pas de nouveaux scopes nécessaires — les filtres utilisent `where()` direct
  via le trait `HasFilterableIndex`.

---

## 5. Architecture services

```
App\Services\Geocoding\
├── LocationResult.php              ← DTO readonly (country_code, country, admin_region, department, provider, raw_score)
├── ReverseGeocoderInterface.php    ← contrat single-point : reverse(lat, lng): ?LocationResult
├── BanReverseGeocoder.php          ← implémente l'interface + méthode reverseBatch() pour le CSV
├── NominatimReverseGeocoder.php    ← implémente l'interface, rate-limit 1 req/s (Cache::lock distribué)
└── HybridReverseGeocoder.php       ← orchestrateur : BAN puis Nominatim
                                      bind comme implémentation par défaut de ReverseGeocoderInterface
```

### 5.1 BAN

- **Single-point** : `GET https://api-adresse.data.gouv.fr/reverse/?lat=&lon=` →
  JSON GeoJSON FeatureCollection. Prend `features[0].properties` si `score >= 0.3`
  et `context` non vide.
- **Batch CSV** : `POST https://api-adresse.data.gouv.fr/reverse/csv/` avec
  `data` (fichier CSV multipart, colonnes `latitude`, `longitude` au minimum)
  + paramètre `columns` à `latitude,longitude` (cf. doc).
  Réponse : CSV avec colonnes supplémentaires `result_label`, `result_score`,
  `result_context` (format `"57, Moselle, Grand Est"`), `result_city`, etc.
- Parsing du `result_context` : split par `, `, le pattern est
  `code_departement, nom_departement, nom_region`. Pour la Corse / DOM
  le code peut être 2A/2B/971… → garder comme chaîne.

### 5.2 Nominatim

- **Endpoint** : `GET https://nominatim.openstreetmap.org/reverse?lat=&lon=&format=jsonv2&accept-language=fr`
- **User-Agent obligatoire** : configuré via `services.geocoding.nominatim.user_agent`
  (défaut `qui-vole.fr (contact email configurable)`).
- **Rate limit** : 1 req/s. Implémenté via `Cache::lock('geocoding.nominatim', 1)`
  → tous les workers respectent un délai global ≥ 1 s entre deux appels.
- **Parsing** :
  - `address.country_code` → uppercase pour `country_code`
  - `address.country` → `country`
  - `address.state` → `admin_region` (avec normalisation : retirer préfixe « Région » si présent)
  - `address.county` ou `address.state_district` ou `address.region` (fallback) → `department`

### 5.3 Hybrid

```php
public function reverse(float $lat, float $lng): ?LocationResult
{
    $result = $this->ban->reverse($lat, $lng);
    if ($result !== null) return $result;
    return $this->nominatim->reverse($lat, $lng);
}
```

---

## 6. Job + observer (création unitaire)

### 6.1 `App\Jobs\GeocodeLocationJob`

- Reçoit `(string $modelClass, int $id)` (pour éviter les soucis de
  sérialisation d'Eloquent et la query auto sur restoration).
- Charge le modèle, appelle `HybridReverseGeocoder::reverse()`, écrit les
  colonnes geo.
- `tries = 3`, backoff exponentiel `[60, 300, 900]` secondes.
- Queue : `default` (pas besoin d'un worker dédié).

### 6.2 `App\Observers\GeocodableObserver`

- Méthode `created()` : dispatch `GeocodeLocationJob` si `geocoded_at IS NULL`.
- Méthode `updated()` : dispatch si `latitude` OU `longitude` a changé
  (alors aussi reset `geocoded_at = null` avant dispatch, pour clarté).
- Enregistré dans `AppServiceProvider::boot()` :
  ```php
  Site::observe(GeocodableObserver::class);
  Balise::observe(GeocodableObserver::class);
  ```

---

## 7. Commande artisan

### 7.1 `php artisan geocode:locations`

Options :
- `--sites` : ne traite que les sites (par défaut : sites + balises)
- `--balises` : ne traite que les balises
- `--force` : re-géocode tout (sinon, ne traite que `geocoded_at IS NULL`)
- `--chunk=100` : taille des batches BAN CSV (max 1000 par appel)
- `--no-batch` : force le mode point-par-point (debug / fallback si BAN down)
- `--limit=N` : ne traite que les N premières entrées (test/dry-run)

Comportement :
1. Récupère les entrées à traiter.
2. Pour chaque chunk :
   - Envoie le batch CSV à BAN (sauf si `--no-batch`).
   - Parse la réponse, écrit les résultats valides.
   - Pour les lignes sans match (étranger), fait un Nominatim single par
     coord avec respect du 1 req/s.
3. Affiche un récap : `X géocodés (BAN: Y, Nominatim: Z, échecs: W)`.

Idempotent : relançable à volonté. Sans `--force`, ne re-traite que les
échecs précédents (`geocoded_at IS NULL`).

### 7.2 Intégration dans `/admin/sync`

Ajouter un bloc « Enrichissement géolocalisation » sur la page
`/admin/sync` avec deux boutons :
- « Géocoder sites non géocodés »
- « Géocoder balises non géocodées »

Qui appellent `Artisan::call('geocode:locations', [...])` et affichent
la sortie. Pour 1 100 sites étrangers, le délai peut dépasser le timeout
HTTP — restreindre l'UI à la cible « non géocodés » (idempotent, relançable
sans état perdu).

---

## 8. Configuration

### 8.1 `config/services.php` — nouveau bloc `geocoding`

```php
'geocoding' => [
    'ban' => [
        'base_url' => env('BAN_BASE_URL', 'https://api-adresse.data.gouv.fr'),
        'min_score' => (float) env('BAN_MIN_SCORE', 0.3),
        'timeout' => (int) env('BAN_TIMEOUT', 15),
    ],
    'nominatim' => [
        'base_url' => env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'qui-vole.fr'),
        'contact_email' => env('NOMINATIM_CONTACT_EMAIL', ''),
        'timeout' => (int) env('NOMINATIM_TIMEOUT', 15),
        'rate_limit_seconds' => (int) env('NOMINATIM_RATE_LIMIT', 1),
    ],
],
```

### 8.2 `.env` à compléter

```
NOMINATIM_USER_AGENT=qui-vole.fr
NOMINATIM_CONTACT_EMAIL=contact@qui-vole.fr
```

---

## 9. Filtres admin

### 9.1 `/admin/sites` (existant : enrichir)

Ajouter trois selects dans les filtres de liste :
- **Pays** (`country_code`) — options peuplées depuis
  `Site::distinct()->whereNotNull('country_code')->pluck('country_code')`
- **Région** (`admin_region`)
- **Département** (`department`)

Pas de cascade dynamique (KISS) — on accepte qu'un filtre département seul
puisse retourner des résultats répartis sur plusieurs régions, c'est
volontaire.

### 9.2 `/admin/balises` (existant : enrichir à l'identique)

Idem 9.1.

### 9.3 `/profil/scorings` (futur — pas dans ce ticket)

La page est purement Alpine.js (API `/api/users/me/scorings/`). L'API
expose déjà `site` (id/name/slug/level/region/lat/lng) — ajouter
`country_code`, `admin_region`, `department` au payload `site` puis
ajouter un filtre client-side (3 selects au-dessus de la liste).
**À faire dans un PR séparé**, pas couvert par ce ticket.

---

## 10. Ordre de découpage suggéré

1. Migration `add_geocoding_to_sites_and_balises` + fillable/casts des modèles
2. Config `services.geocoding`
3. DTO `LocationResult` + interface `ReverseGeocoderInterface`
4. `BanReverseGeocoder` (single + batch)
5. `NominatimReverseGeocoder` (single + rate limit)
6. `HybridReverseGeocoder` + binding dans `AppServiceProvider`
7. Commande `geocode:locations`
8. Job `GeocodeLocationJob` + Observer + enregistrement
9. Filtres admin Site + Balise (controller + vue)
10. Bouton sur `/admin/sync`
11. Tests unitaires : parsing BAN CSV, parsing Nominatim JSON, fusion hybrid
12. **Run initial** sur la base de prod : `php artisan geocode:locations` (~5 min)

---

## 11. Estimation grossière

- ~600 lignes de code (services, command, job, observer, migration, vues)
- ~200 lignes de tests
- Temps réel implémentation : 4-6 h
- Temps de run initial en prod : ~5 min (BAN batch ~30 s + Nominatim
  ~250 × 1 s = 4 min + marge)

---

## 12. Notes de mise en prod

1. Déployer le code (migration appliquée auto via `php artisan migrate`).
2. Lancer **une fois** : `docker exec parapente_php php artisan geocode:locations`
   → enrichit les 1 100 sites + 270 balises existantes.
3. Vérifier le récap (échecs < 5 % attendu, sinon investiguer).
4. À partir de ce moment, les nouveaux sites/balises sont géocodés
   automatiquement à la création via l'observer + job async.
5. Pour re-géocoder un site dont les coords ont été corrigées : pas
   d'action manuelle, l'observer le détecte au save (changement
   `latitude`/`longitude`).
