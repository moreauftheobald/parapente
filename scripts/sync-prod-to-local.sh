#!/usr/bin/env bash
#
# scripts/sync-prod-to-local.sh
# ─────────────────────────────────────────────────────────────────────
# Récupère un dump SQL complet de la prod via SSH et le pose dans
# var/dumps/. Idempotent : un dump par appel, nom horodaté.
#
# Le dump n'est PAS chargé automatiquement — utiliser ensuite :
#     ./scripts/load-dump.sh var/dumps/<fichier>.sql.gz
#
# Configuration :
#   PROD_SSH_HOST   (défaut: franck@213.199.51.57)
#   PROD_PATH       (défaut: /srv/parapente-app/parapente)
#
# Exemple :
#   ./scripts/sync-prod-to-local.sh
#   PROD_SSH_HOST=user@host ./scripts/sync-prod-to-local.sh
#

set -euo pipefail
SSH_HOST="${PROD_SSH_HOST:-perso-user}"
PROD_PATH="${PROD_PATH:-/srv/parapente-app/parapente}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DUMP_DIR="$PROJECT_ROOT/var/dumps"
DUMP_FILENAME="parapente-$(date +%Y%m%d_%H%M%S).sql.gz"
DUMP_PATH="$DUMP_DIR/$DUMP_FILENAME"

mkdir -p "$DUMP_DIR"

echo "→ Dump prod via SSH ($SSH_HOST)"
echo "  Cible : $DUMP_PATH"

# `set -a + source .env` côté prod : on récupère DB_DATABASE/DB_USERNAME/DB_PASSWORD
# du fichier .env du projet, puis on lance mysqldump dans le conteneur mariadb
# avec ces credentials. --single-transaction = dump consistent sans LOCK des tables
# (donc sans impact sur la prod en cours d'utilisation).
ssh "$SSH_HOST" "cd $PROD_PATH && \
    set -a && source .env && set +a && \
    docker exec parapente_mariadb mysqldump \
        --single-transaction \
        --quick \
        --no-tablespaces \
        --default-character-set=utf8mb4 \
        --hex-blob \
        -u\"\$DB_USERNAME\" -p\"\$DB_PASSWORD\" \
        \"\$DB_DATABASE\" | gzip" > "$DUMP_PATH"

SIZE=$(du -h "$DUMP_PATH" | cut -f1)
echo "✓ Dump récupéré : $DUMP_PATH ($SIZE)"
echo ""
echo "Pour le charger en local :"
echo "  ./scripts/load-dump.sh $DUMP_PATH"
