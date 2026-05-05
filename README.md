# ParapenteFR

Plateforme web dédiée aux pilotes de parapente — météo multi-modèles, journal de vol, comparatif équipements.

## Prérequis

- Docker & Docker Compose
- Git
- PHPStorm (recommandé)

## Installation

### 1. Cloner le dépôt

```bash
git clone git@github.com:TON_COMPTE/parapente.git
cd parapente
```

### 2. Configurer l'environnement

```bash
cp .env.example .env
# Éditer .env avec tes valeurs (mots de passe, etc.)
```

### 3. Démarrer les containers

```bash
docker compose up -d --build
```

### 4. Installer Laravel

```bash
# Créer le dossier src si ce n'est pas déjà fait
docker exec -it parapente_php bash
composer create-project laravel/laravel . --prefer-dist
exit
```

### 5. Configurer Laravel

```bash
# Copier le .env Laravel
cp .env.example src/.env

# Générer la clé applicative
docker exec -it parapente_php php artisan key:generate

# Lancer les migrations
docker exec -it parapente_php php artisan migrate
```

### 6. Installer les dépendances frontend

```bash
docker exec -it parapente_php npm install
docker exec -it parapente_php npm run dev
```

L'application est accessible sur **http://localhost:8001**

---

## Structure

```
parapente/
├── src/            ← Application Laravel
├── nginx/          ← Configuration Nginx
│   ├── nginx.conf
│   └── log/
├── docker-compose.yml
├── Dockerfile
├── .env.example
├── .gitignore
├── CLAUDE.md       ← Instructions pour Claude Code
└── README.md
```

## Documentation

Voir [CLAUDE.md](./CLAUDE.md) pour la documentation technique complète.
