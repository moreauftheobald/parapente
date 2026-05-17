#!/usr/bin/env bash
#
# scripts/load-dump.sh
# ─────────────────────────────────────────────────────────────────────
# Charge un dump SQL prod dans la base MariaDB locale.
# Appelle ensuite `php artisan db:scrub` pour anonymiser les données
# sensibles (emails, passwords, secrets chiffrés).
#
# Usage :
#   ./scripts/load-dump.sh var/dumps/parapente-20260516_220000.sql.gz
#   ./scripts/load-dump.sh var/dumps/parapente-latest.sql       # .sql brut OK aussi
#
# Pré-requis :
#   - L'environnement local doit tourner : `docker compose up -d`
#   - Le conteneur parapente_mariadb doit être démarré.
#

set -euo pipefail

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || -z "${1:-}" ]]; then
    cat <<EOF
Usage : $(basename "$0") <dump.sql[.gz]>

Charge un dump SQL prod dans la base locale, puis lance le scrub.

Le dump peut être en .sql ou .sql.gz. Le scrub :
  - anonymise les emails (user{id}@local.test)
  - reset tous les passwords à 'password'
  - vide les secrets chiffrés (api_keys, oauth tokens)
EOF
    exit 0
fi

DUMP_FILE="$1"
if [[ ! -f "$DUMP_FILE" ]]; then
    echo "✗ Fichier introuvable : $DUMP_FILE" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_ROOT/.env"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "✗ $ENV_FILE introuvable. Lance d'abord 'cp .env.example .env'." >&2
    exit 1
fi

# Charge DB_DATABASE/DB_USERNAME/DB_PASSWORD du .env racine (lu par docker compose).
set -a
# shellcheck source=/dev/null
source "$ENV_FILE"
set +a

: "${DB_DATABASE:?DB_DATABASE manquant dans .env}"
: "${DB_PASSWORD:?DB_PASSWORD manquant dans .env}"

# Vérifie que le conteneur tourne.
if ! docker ps --format '{{.Names}}' | grep -qx 'parapente_mariadb'; then
    echo "✗ parapente_mariadb n'est pas démarré. Lance 'docker compose up -d'." >&2
    exit 1
fi

echo ""
echo "⚠️  La base locale '$DB_DATABASE' va être ÉCRASÉE."
echo "    Source : $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"
read -rp "    Continuer ? [y/N] " ok
[[ "$ok" =~ ^[yY]$ ]] || { echo "Annulé."; exit 0; }

echo ""
echo "→ Drop + recreate '$DB_DATABASE'..."
docker exec -i parapente_mariadb mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-$DB_PASSWORD}" <<SQL
DROP DATABASE IF EXISTS \`$DB_DATABASE\`;
CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '${DB_USERNAME}'@'%';
FLUSH PRIVILEGES;
SQL

echo "→ Import du dump..."
if [[ "$DUMP_FILE" == *.gz ]]; then
    gunzip -c "$DUMP_FILE" | docker exec -i parapente_mariadb mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-$DB_PASSWORD}" "$DB_DATABASE"
else
    docker exec -i parapente_mariadb mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-$DB_PASSWORD}" "$DB_DATABASE" < "$DUMP_FILE"
fi

echo "→ Application des migrations manquantes (au cas où le dump est antérieur)..."
docker exec parapente_php php artisan migrate --force

echo "→ Scrub des données sensibles..."
docker exec parapente_php php artisan db:scrub --force

echo "→ Cache clear (sessions / Redis / map bundle)..."
docker exec parapente_php php artisan cache:clear
docker exec parapente_php php artisan map:rebuild-bundle --clear || true

echo ""
echo "✓ Dump rechargé et scrubbed."
echo "  Mot de passe de TOUS les users : 'password'"
echo "  Emails anonymisés : user{id}@local.test"
