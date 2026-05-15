# FF — I Am Here : Filtrage des sites par temps de route

**Statut :** À développer  
**Priorité :** Secondaire (après stabilisation du core météo)  
**Dépendance externe :** API OpenRouteService (ORS) — tier gratuit, sans infrastructure additionnelle

---

## 1. Description fonctionnelle

Cette feature permet à l'utilisateur de poser un marqueur sur la carte Leaflet représentant sa position actuelle (ou n'importe quel point de départ), puis de filtrer les sites de parapente visibles sur la carte pour n'afficher que ceux accessibles en voiture dans un délai défini (ex. : 30 min, 1h, 2h).

L'objectif est d'afficher des **temps de route réels** et non des distances à vol d'oiseau, ce dernier étant trompeur en zone de montagne.

### Parcours utilisateur

1. L'utilisateur clique sur un bouton "Je suis ici" dans la toolbar Leaflet
2. Un marqueur spécifique (distinct des marqueurs sites) est posé sur la carte, déplaçable
3. Un sélecteur de durée apparaît (30 min / 1h / 1h30 / 2h / ou valeur libre)
4. L'application filtre les marqueurs de sites : seuls les sites accessibles dans le délai apparaissent en couleur normale, les autres sont grisés ou masqués
5. En option visuelle : affichage d'un polygone isochrone semi-transparent sur la carte
6. L'utilisateur peut déplacer son marqueur pour relancer le calcul depuis une nouvelle position
7. Un bouton "Réinitialiser" remet tous les sites visibles

---

## 2. Architecture technique

### 2.1 Vue d'ensemble du flux

```
[Utilisateur pose un pin sur la carte]
        ↓
[Frontend envoie lat/lon + max_minutes au backend Laravel]
        ↓
[Service Laravel : snap sur grille de 0.05°]
        ↓
[Lookup en base routing_cache]
        ↓ HIT                    ↓ MISS
[Retour immédiat]       [Pré-filtrage spatial SQL]
                                 ↓
                        [Appel API ORS Matrix]
                                 ↓
                        [Stockage en routing_cache]
                                 ↓
                        [Retour au frontend]
        ↓
[Frontend met à jour les marqueurs Leaflet]
```

### 2.2 Pré-filtrage spatial avant appel ORS

Avant tout appel à l'API ORS, le backend applique un filtre géométrique pour réduire le nombre de sites candidats. Ce filtre repose sur la règle suivante :

> Si la distance à vol d'oiseau entre la position de l'utilisateur et un site est supérieure à `(max_minutes / 60) × vitesse_max_km_h`, alors ce site est **mathématiquement inaccessible** dans le temps imparti, quelle que soit la route empruntée.

La vitesse maximale retenue est **130 km/h** (vitesse légale maximale en France sur autoroute), ce qui constitue une borne supérieure stricte. Ce filtre n'engendre **aucun faux négatif** : aucun site valide ne sera éliminé.

**Exemples de rayons d'exclusion :**

| Durée max | Rayon crow-flies | Réduction estimée (700 sites) |
|-----------|-----------------|-------------------------------|
| 30 min    | 65 km           | ~85% des sites éliminés       |
| 1h        | 130 km          | ~70% éliminés                 |
| 2h        | 260 km          | ~40% éliminés                 |

Le filtre SQL utilise la formule Haversine avec une optimisation par bounding box (filtre `BETWEEN` sur `latitude` et `longitude` exploitant les index B-tree) avant l'application du cercle exact.

### 2.3 Snap sur grille (cache spatial)

Pour maximiser le taux de réutilisation du cache, la position de l'utilisateur est **discrétisée sur une grille de 0.05°** avant tout lookup ou stockage.

- 0.05° de latitude ≈ 5.6 km
- 0.05° de longitude ≈ 3.5 à 4 km selon la latitude (France métropolitaine)

**Principe :** deux utilisateurs dans un rayon de ~5 km partagent automatiquement le résultat de routage, puisqu'ils tombent sur la même case de grille.

L'imprécision introduite (~5 km sur le point de départ) est totalement acceptable dans le contexte du parapente, où la marge d'erreur sur le temps de trajet correspondant est de l'ordre de 3-4 minutes.

**Le calcul ORS est toujours effectué depuis le point snappé**, jamais depuis la position GPS exacte de l'utilisateur, ce qui garantit la cohérence des résultats en cache.

---

## 3. Stratégie de cache

### 3.1 Principe général

Les résultats de routage (durée en secondes et distance en mètres entre un point de grille et chaque site) sont stockés en base de données dans une table `routing_cache`. Lors d'une requête, le backend cherche d'abord un résultat valide en cache avant de solliciter l'API ORS.

Ce cache est **durable** : le réseau routier et les coordonnées des sites de parapente évoluent très rarement. Un résultat calculé en janvier est encore valide en décembre.

### 3.2 TTL (Time To Live)

Le TTL est **configurable dans le panneau d'administration** de l'application.

- **Valeur par défaut : 365 jours**
- Plage admissible : 30 jours minimum, sans maximum imposé
- Le TTL s'applique à la date de création de l'entrée (`created_at + TTL = expires_at`)
- Toute modification du TTL en admin n'affecte que les nouvelles entrées ; les entrées existantes conservent leur `expires_at` original

Le paramètre est stocké en base dans la table `settings` sous la clé `routing_cache_ttl_days`.

### 3.3 Stratégie d'invalidation

Le cache n'est **pas purgé par expiration passive uniquement**. Les événements suivants déclenchent une invalidation ciblée :

| Événement | Invalidation |
|-----------|-------------|
| Ajout d'un nouveau site en base | Purge des entrées de grille dont le centroïde est à moins de `(max_minutes / 60 × 130)` km du nouveau site |
| Modification des coordonnées d'un site existant | Purge de toutes les entrées liées à ce `site_id` |
| Suppression d'un site | Purge de toutes les entrées liées à ce `site_id` |
| Mise à jour manuelle (admin) | Purge totale ou purge par zone géographique (bounding box) |
| Mise à jour des données OSM / Valhalla (futur) | Purge totale + recalcul progressif au fil des requêtes |

Un job Laravel planifié hebdomadaire effectue le nettoyage des entrées dont `expires_at` est dépassé.

### 3.4 Structure de la table `routing_cache`

Champs principaux :
- `origin_lat` : latitude snappée sur la grille (DECIMAL 4,2)
- `origin_lon` : longitude snappée sur la grille (DECIMAL 5,2)
- `max_minutes` : durée maximale de la requête en minutes (TINYINT)
- `site_id` : référence au site de parapente
- `duration_sec` : temps de trajet calculé en secondes (NULL si inaccessible dans le délai)
- `distance_m` : distance routière en mètres (NULL si inaccessible)
- `created_at` : date de calcul
- `expires_at` : date d'expiration (= `created_at` + TTL admin)

Index :
- Clé unique composite sur `(origin_lat, origin_lon, max_minutes, site_id)`
- Index sur `(origin_lat, origin_lon, max_minutes)` pour les lookups
- Index sur `expires_at` pour la purge planifiée

---

## 4. Intégration API ORS (OpenRouteService)

### 4.1 Endpoint utilisé

**Matrix (sources to targets)** — calcule les temps et distances entre 1 origine et N destinations en une seule requête.

- URL : `https://api.openrouteservice.org/v2/matrix/driving-car`
- Méthode : POST
- Authentification : clé API dans le header `Authorization`
- Métriques demandées : `duration` et `distance`

### 4.2 Limites du tier gratuit ORS

- 500 requêtes matrix / jour
- 40 requêtes / minute
- 3 500 éléments max (sources × destinations) par requête

Avec le cache spatial, le volume d'appels réels à ORS décroît rapidement au fil de l'utilisation. Après quelques semaines, les zones habitées fréquentes sont entièrement couvertes et le quota quotidien n'est pratiquement plus atteint.

### 4.3 Gestion des erreurs et rate limiting

- En cas de dépassement du quota ORS (HTTP 429), retourner une réponse dégradée gracieuse côté frontend : les marqueurs restent tous affichés, un message indique que le filtrage est temporairement indisponible
- Aucun retry automatique agressif — attendre le cycle suivant
- Logger les appels ORS (timestamp, nb de candidats, durée de réponse) pour monitorer la consommation du quota

### 4.4 Evolution future vers Valhalla (self-hosted)

Quand le trafic le justifie et que le serveur est upgradé (cible : 16 cores / 48-64 GB RAM), l'API ORS sera remplacée par une instance **Valhalla auto-hébergée** via Docker. Le service Laravel est conçu pour que ce remplacement soit transparent : seule la configuration du endpoint change, la logique de cache et de pré-filtrage reste identique.

Données Valhalla recommandées à ce stade : extrait OSM couvrant France, Belgique, Luxembourg, SO Allemagne, Suisse, NO Italie, N Espagne — soit environ 7-8 GB de PBF source.

---

## 5. Composants Laravel à créer

### Services
- `RoutingFilterService` — orchestration : snap, lookup cache, pré-filtrage, appel ORS, stockage
- `OrsMatrixClient` — encapsulation de l'appel HTTP à ORS (remplaçable par `ValhallaMatrixClient`)
- `RoutingCacheRepository` — accès à la table `routing_cache`

### Modèles / Migrations
- Migration `create_routing_cache_table`
- Migration `add_routing_cache_ttl_to_settings` (ou seed dans la table `settings` existante)

### Controller
- `RoutingFilterController` — endpoint `POST /api/routing/filter`
    - Paramètres : `lat`, `lon`, `max_minutes`
    - Réponse : liste de `site_id` avec `duration_sec` et `distance_m`

### Jobs
- `PurgeExpiredRoutingCache` — planifié hebdomadairement via Laravel Scheduler

### Admin (Nova ou panel existant)
- Paramètre `routing_cache_ttl_days` : champ numérique, valeur par défaut 365, validation min 30
- Bouton "Purger le cache routing" : purge totale avec confirmation
- Bouton "Purger par zone" : purge par bounding box (lat_min, lat_max, lon_min, lon_max)
- Compteur : nombre d'entrées en cache / nombre de cases de grille couvertes

---

## 6. Composants Frontend Leaflet à créer

- Bouton "Je suis ici" dans la toolbar existante (icône épingle + croix)
- Marqueur draggable distinct des marqueurs sites (style différencié)
- Sélecteur de durée : dropdown ou boutons radio (30 min / 1h / 1h30 / 2h)
- Logique de grisage ou masquage des marqueurs hors scope
- Affichage optionnel du polygone isochrone (GeoJSON retourné par ORS ou Valhalla)
- Bouton "Réinitialiser le filtre"
- Message d'indisponibilité gracieux en cas d'erreur ORS

---

## 7. Paramètres de configuration (`.env`)

```
ORS_API_KEY=                    # Clé API OpenRouteService
ORS_BASE_URL=https://api.openrouteservice.org
ROUTING_GRID_SIZE=0.05          # Taille de la grille de snap en degrés (~5 km)
ROUTING_MAX_SPEED_KMH=130       # Vitesse max pour le pré-filtrage crow-flies
ROUTING_CACHE_TTL_DAYS=365      # Valeur par défaut (surchargeable en admin)
```

---

## 8. Points d'attention

- **Confidentialité** : la position de l'utilisateur n'est jamais stockée en base. Seul le point snappé sur la grille est utilisé. Le frontend ne doit pas logger la position GPS brute.
- **Précision du snap** : documenter dans l'UI que les temps affichés sont calculés depuis un point approché (~5 km), pas depuis la position exacte.
- **Sites sans accès routier** : certains sites de montagne peuvent être inaccessibles en voiture ou renvoyer `null` depuis ORS. Prévoir l'affichage d'une mention "accès non calculé" plutôt qu'une erreur.
- **Internationalisation des vitesses** : le plafond de 130 km/h est conservateur et valide pour tous les pays du scope (France 130, Allemagne autoroute non limitée mais ~130 pris comme borne, Suisse 120, Italie 130, Belgique 120, Espagne 120).