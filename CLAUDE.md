# CLAUDE.md — Instructions pour Claude Code

## Présentation du projet

**ParapenteFR** est une plateforme web dédiée aux pilotes de parapente, construite en Laravel.
Le projet est modulaire : chaque grande fonctionnalité est un module indépendant.

---

## Stack technique

| Composant      | Technologie                          |
|----------------|--------------------------------------|
| Serveur        | Nginx 1.26                           |
| Backend        | PHP 8.4 / Laravel 11                 |
| Base de données| MariaDB 10.11                        |
| Cache / Queue  | Redis 7                              |
| Frontend       | Tailwind CSS + Alpine.js + Livewire  |
| Carte          | Leaflet.js                           |
| Build assets   | Vite                                 |
| Conteneurs     | Docker / Docker Compose              |

---

## Architecture du projet

```
src/                        ← Racine Laravel
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   └── Livewire/       ← Composants Livewire
│   ├── Models/
│   ├── Services/           ← Logique métier (météo, scoring, etc.)
│   └── Jobs/               ← Jobs de queue (fetch météo, calcul scores)
├── database/
│   ├── migrations/
│   └── seeders/
├── resources/
│   ├── views/
│   │   ├── layouts/        ← Layout principal
│   │   ├── components/     ← Composants Blade
│   │   └── livewire/       ← Vues des composants Livewire
│   ├── js/
│   │   └── app.js          ← Alpine.js + Leaflet initialisés ici
│   └── css/
│       └── app.css         ← Tailwind CSS
├── routes/
│   ├── web.php
│   └── api.php             ← Endpoints JSON pour Leaflet
└── config/
    └── meteo.php           ← Config des modèles météo et APIs
```

---

## Modules prévus

### Module 1 — Météo & Carte des sites (en cours)
Le cœur de la plateforme. Affiche une carte des sites de vol du Grand Est
avec un scoring météo multi-modèles en temps réel.

**Concepts clés :**
- Chaque site a un profil de conditions idéales (vent, direction, précipitations)
- Un job de queue interroge Open-Meteo toutes les heures pour 10 modèles météo
- Une voting logic + moyenne pondérée par distance inverse carré produit un score de confiance
- Les balises PiouPiou/FFVL en temps réel valident ou challengent la prévision
- Résultat affiché : feu tricolore + % de confiance par site, par créneau, sur 5 jours max

**Modèles météo utilisés (via Open-Meteo) :**
```
Court terme (J+1/J+2) : AROME, ICON-D2, HARMONIE (haute résolution locale)
Moyen terme (J+3/J+5) : ARPEGE-EU, ICON-EU, IFS-HRES, AIFS, ICON, GEM
```

**Variables météo :**
- DÉTERMINISTES (éliminatoires) : direction vent, force vent (min/moy/max), précipitations
- QUALITATIVES : plafond nuageux, couverture nuageuse (haute/moyenne/basse), température, humidité

### Module 2 — Journal de vol
Carnet de vol numérique par utilisateur. Log des vols, statistiques, progression.
Nécessite authentification.

### Module 3 — Comparatif voiles & sellettes
Base de données des équipements parapente avec système de comparaison.
Notation communautaire, fiches techniques.

### Module 4 — (à définir)

---

## Conventions de code

### PHP / Laravel
- PSR-12 strict
- Typage fort partout (`declare(strict_types=1)` en tête de chaque fichier)
- Services dans `app/Services/` — logique métier jamais dans les controllers
- Jobs dans `app/Jobs/` — tout traitement async passe par la queue Redis
- Toujours utiliser l'ORM Eloquent, pas de requêtes SQL brutes
- Les réponses API retournent toujours du JSON avec la structure :
  ```json
  { "success": true, "data": {}, "message": "" }
  ```

### Frontend
- Tailwind CSS uniquement pour le style (pas de CSS custom sauf cas extrême)
- Alpine.js pour la réactivité légère (dropdowns, toggles, etc.)
- Livewire pour les composants avec état côté serveur
- Leaflet.js pour tout ce qui est cartographique — initialisé dans `resources/js/map.js`
- Pas de jQuery

### Base de données
- Toujours passer par les migrations Laravel, jamais de modification manuelle
- snake_case pour les colonnes
- Chaque table a `created_at` et `updated_at` (timestamps Laravel)
- Indexes sur toutes les clés étrangères et les colonnes fréquemment filtrées

### Sécurité
- Toutes les routes authentifiées utilisent le middleware `auth`
- Validation systématique des inputs via Form Requests Laravel
- Ne jamais exposer les clés API dans le frontend

---

## Commandes Docker utiles

```bash
# Démarrer l'environnement
docker compose up -d

# Accéder au container PHP
docker exec -it parapente_php bash

# Lancer les migrations
docker exec -it parapente_php php artisan migrate

# Lancer les seeders
docker exec -it parapente_php php artisan db:seed

# Vider les caches
docker exec -it parapente_php php artisan optimize:clear

# Compiler les assets (dev)
docker exec -it parapente_php npm run dev

# Voir les logs du worker de queue
docker logs parapente_worker -f
```

---

## URLs de développement

| Service     | URL                        |
|-------------|----------------------------|
| Application | http://localhost:8001       |
| phpMyAdmin  | http://localhost:8081       |
| MailDev     | http://localhost:6082       |
| Xdebug port | 6001                        |

---

## Variables d'environnement importantes

Voir `.env.example` à la racine du projet.
Copier en `.env` et renseigner les valeurs avant le premier `docker compose up`.

---

## Points d'attention

1. **Le dossier `src/` contient l'application Laravel** — ne pas modifier la structure Docker depuis ce dossier
2. **Les jobs météo sont asynchrones** — ils tournent dans le container `parapente-worker`
3. **Redis est utilisé** pour le cache, les sessions ET les queues — ne pas changer `QUEUE_CONNECTION`
4. **Horizon temporel météo limité à 5 jours** — au-delà c'est de la divination, pas de la prévision
5. **Les ports sont décalés** pour coexister avec Dolibarr sur le même serveur (voir tableau URLs)
