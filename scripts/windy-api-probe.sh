#!/usr/bin/env bash
# Test de l'API Windy Stations v2 (Open Data).
#
# Usage : ./scripts/windy-api-probe.sh
#
# Lit la clé API depuis settings.windy.api_key (via `php artisan tinker`
# dans le container parapente_php), interroge les endpoints opendata et
# écrit un rapport lisible dans /tmp/windy-test-results.txt. Les JSON
# bruts sont également sauvegardés dans /tmp/windy-*.json pour relecture.
#
# Aucune sortie volumineuse en console : le script affiche uniquement
# le chemin du fichier final.

set -uo pipefail

REPORT="/tmp/windy-test-results.txt"
RAW_CATALOG="/tmp/windy-catalog.json"
RAW_CATALOG_FR="/tmp/windy-catalog-fr.json"
RAW_OBS="/tmp/windy-observation.json"
HEADERS="/tmp/windy-headers.txt"

# ── helpers ──────────────────────────────────────────────────────────
have() { command -v "$1" >/dev/null 2>&1; }

# Pretty-print JSON si jq est dispo, sinon dump brut tronqué.
pp() {
    if have jq; then
        jq '.' "$1" 2>/dev/null || cat "$1"
    else
        cat "$1"
    fi
}

# Compte d'un JSON (longueur si array, sinon liste des top-level keys).
summarize_root() {
    if ! have jq; then echo "  (jq absent — installer pour voir la structure)"; return; fi
    jq -r '
        if type == "array" then
            "  Racine = ARRAY de \(length) éléments"
        elif type == "object" then
            "  Racine = OBJECT, clés top-level :",
            (to_entries[] | "    · \(.key) (\(.value|type)\(if (.value|type)=="array" then ", \(.value|length) éléments" else "" end))")
        else
            "  Racine = \(type)"
        end
    ' "$1" 2>/dev/null || echo "  (JSON invalide ou non parseable)"
}

# Échantillon de la première station — sonde tous les conteneurs probables.
sample_station() {
    if ! have jq; then echo "  (jq absent)"; return; fi
    jq '
        def first_station:
            if type == "array" then .[0]
            elif .stations? then .stations[0]
            elif .data?     then .data[0]
            elif .features? then .features[0]
            else . end;
        first_station
    ' "$1" 2>/dev/null
}

extract_station_id() {
    if ! have jq; then return 1; fi
    jq -r '
        def first_station:
            if type == "array" then .[0]
            elif .stations? then .stations[0]
            elif .data?     then .data[0]
            elif .features? then .features[0]
            else . end;
        first_station
        | (.id // .stationId // .station_id // .properties.id // .properties.stationId // empty)
        | tostring
    ' "$1" 2>/dev/null
}

# ── 0. Récupération de la clé ────────────────────────────────────────
echo "→ Récupération de la clé API depuis settings.windy.api_key…" >&2

# Tinker imprime du bruit; on extrait la dernière ligne non vide.
KEY=$(docker exec parapente_php php artisan tinker --execute='echo trim((string) app(App\Services\Settings::class)->get("windy.api_key", ""));' 2>/dev/null \
    | tr -d '\r' | awk 'NF{x=$0} END{print x}')

if [[ -z "${KEY:-}" || "$KEY" == "null" ]]; then
    echo "✗ Clé API Windy introuvable. Configure-la dans /admin/settings → Sources balises." >&2
    exit 1
fi

KEY_PREVIEW="${KEY:0:4}…${KEY: -4}"

# ── 1. Initialisation du rapport ─────────────────────────────────────
{
    echo "════════════════════════════════════════════════════════════════════"
    echo "Windy Stations API v2 — sonde Open Data"
    echo "Date           : $(date -Iseconds)"
    echo "Clé utilisée   : $KEY_PREVIEW (${#KEY} caractères)"
    echo "Host           : https://stations.windy.com"
    echo "════════════════════════════════════════════════════════════════════"
} > "$REPORT"

# ── 2. Catalogue complet ─────────────────────────────────────────────
{
    echo
    echo "─── [1/4] GET /api/v2/opendata/station (catalogue complet) ────────"
} >> "$REPORT"

HTTP_CODE=$(curl -sS -w "%{http_code}" -o "$RAW_CATALOG" \
    -D "$HEADERS" \
    -H "windy-api-key: $KEY" \
    -H "Accept: application/json" \
    "https://stations.windy.com/api/v2/opendata/station")

{
    echo "HTTP status : $HTTP_CODE"
    echo "Taille body : $(wc -c < "$RAW_CATALOG") octets"
    echo
    echo "Headers de réponse :"
    sed 's/^/  /' "$HEADERS"
    echo
    if [[ "$HTTP_CODE" != "200" ]]; then
        echo "⚠ Réponse non-200 — body brut (tronqué à 2 ko) :"
        head -c 2048 "$RAW_CATALOG"
        echo
    else
        echo "Structure racine :"
        summarize_root "$RAW_CATALOG"
        echo
        echo "Échantillon — 1ère station (JSON formaté) :"
        sample_station "$RAW_CATALOG" | sed 's/^/  /'
    fi
} >> "$REPORT"

# ── 3. Catalogue avec bbox France métropolitaine ─────────────────────
{
    echo
    echo "─── [2/4] GET …?lat_min=41&lat_max=51.5&lng_min=-5.5&lng_max=10 ───"
    echo "(test du filtre serveur par bbox — si ignoré, même taille que [1])"
} >> "$REPORT"

HTTP_CODE_FR=$(curl -sS -w "%{http_code}" -o "$RAW_CATALOG_FR" \
    -H "windy-api-key: $KEY" \
    -H "Accept: application/json" \
    "https://stations.windy.com/api/v2/opendata/station?lat_min=41&lat_max=51.5&lng_min=-5.5&lng_max=10")

{
    echo "HTTP status : $HTTP_CODE_FR"
    echo "Taille body : $(wc -c < "$RAW_CATALOG_FR") octets"
    if [[ "$HTTP_CODE_FR" == "200" ]]; then
        echo "Structure racine :"
        summarize_root "$RAW_CATALOG_FR"
    fi
} >> "$REPORT"

# ── 4. Observation d'une station ─────────────────────────────────────
{
    echo
    echo "─── [3/4] GET /api/v2/opendata/station/{id}/observation ──────────"
} >> "$REPORT"

STATION_ID=""
if [[ "$HTTP_CODE" == "200" ]]; then
    STATION_ID=$(extract_station_id "$RAW_CATALOG" | head -1)
fi

if [[ -z "$STATION_ID" || "$STATION_ID" == "empty" || "$STATION_ID" == "null" ]]; then
    echo "✗ Pas pu extraire d'ID de station depuis le catalogue — skip." >> "$REPORT"
else
    echo "Station testée : id=$STATION_ID" >> "$REPORT"

    HTTP_CODE_OBS=$(curl -sS -w "%{http_code}" -o "$RAW_OBS" \
        -H "windy-api-key: $KEY" \
        -H "Accept: application/json" \
        "https://stations.windy.com/api/v2/opendata/station/$STATION_ID/observation")

    {
        echo "HTTP status : $HTTP_CODE_OBS"
        echo "Taille body : $(wc -c < "$RAW_OBS") octets"
        echo
        if [[ "$HTTP_CODE_OBS" == "200" ]]; then
            echo "Structure racine :"
            summarize_root "$RAW_OBS"
            echo
            echo "Réponse complète (JSON formaté, tronqué à 5 ko) :"
            pp "$RAW_OBS" | head -c 5120 | sed 's/^/  /'
            echo
        else
            echo "⚠ Réponse non-200 — body brut (tronqué à 2 ko) :"
            head -c 2048 "$RAW_OBS"
            echo
        fi
    } >> "$REPORT"
fi

# ── 5. Notes finales pour Claude ─────────────────────────────────────
{
    echo
    echo "─── [4/4] Fichiers bruts conservés ───────────────────────────────"
    echo "  · $RAW_CATALOG     ($(wc -c < "$RAW_CATALOG") octets)"
    echo "  · $RAW_CATALOG_FR  ($(wc -c < "$RAW_CATALOG_FR") octets)"
    if [[ -s "$RAW_OBS" ]]; then
        echo "  · $RAW_OBS    ($(wc -c < "$RAW_OBS") octets)"
    fi
    echo "  · $HEADERS    (headers HTTP catalogue)"
    echo
    echo "Pour me partager : colle le contenu de $REPORT."
    echo "Si tu veux que je voie le JSON complet d'une station, copie aussi"
    echo "le résultat de :  jq '.stations[0] // .data[0] // .[0]' $RAW_CATALOG"
    echo "════════════════════════════════════════════════════════════════════"
} >> "$REPORT"

echo "✓ Terminé. Rapport : $REPORT"
