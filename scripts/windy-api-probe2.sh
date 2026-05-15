#!/usr/bin/env bash
# Sonde Windy v2 — round 2.
#
# Approfondit le rapport de probe1 sur les deux zones d'ombre restantes :
#  1. Structure exacte du bloc `pagination` (offset/limit/cursor/has_more ?)
#     → pour brancher correctement la pagination automatique.
#  2. Structure complète du bloc `data` d'une observation (séries
#     disponibles, longueur, dernière valeur de chaque série, présence
#     d'un axe `time` ou `ts`)
#     → pour finaliser le parsing des observations.
#
# Sortie : /tmp/windy-test-results2.txt.

set -uo pipefail

REPORT="/tmp/windy-test-results2.txt"
CATALOG="/tmp/windy-catalog.json"
OBS="/tmp/windy-observation.json"

if ! command -v jq >/dev/null 2>&1; then
    echo "✗ jq requis pour cette sonde." >&2
    exit 1
fi

if [[ ! -s "$CATALOG" || ! -s "$OBS" ]]; then
    echo "✗ Lance d'abord scripts/windy-api-probe.sh — fichiers /tmp/windy-{catalog,observation}.json attendus." >&2
    exit 1
fi

{
    echo "════════════════════════════════════════════════════════════════════"
    echo "Windy API v2 — sonde round 2 (pagination + observation détail)"
    echo "Date : $(date -Iseconds)"
    echo "════════════════════════════════════════════════════════════════════"

    echo
    echo "─── [1/3] Bloc pagination du catalogue ────────────────────────────"
    echo "Contenu complet de .pagination :"
    jq '.pagination' "$CATALOG" | sed 's/^/  /'
    echo
    echo "Type et clés :"
    jq -r '
        .pagination
        | if type == "object"
          then "  type=object — clés : " + (keys|join(", "))
          else "  type=" + (.|type)
          end
    ' "$CATALOG"

    echo
    echo "─── [2/3] Distribution des station_type (1ère page = 100 stations) ─"
    jq -r '
        [.data[].station_type] | group_by(.) | map({type:.[0], count:length})
        | sort_by(-.count) | .[]
        | "  · " + (.type // "(null)") + " : " + (.count|tostring)
    ' "$CATALOG"
    echo
    echo "Distribution de share_option :"
    jq -r '
        [.data[].share_option] | group_by(.) | map({so:.[0], count:length})
        | sort_by(-.count) | .[]
        | "  · " + (.so // "(null)") + " : " + (.count|tostring)
    ' "$CATALOG"
    echo
    echo "Distribution de is_online :"
    jq -r '
        [.data[].is_online] | group_by(.) | map({on:.[0], count:length})
        | .[]
        | "  · " + (.on|tostring) + " : " + (.count|tostring)
    ' "$CATALOG"

    echo
    echo "─── [3/3] Structure du bloc .data d'une observation ──────────────"
    echo "Clés top-level de .data, avec type, longueur, premier+dernier élément :"
    jq -r '
        .data
        | to_entries
        | .[]
        | "  · " + .key
          + " (type=" + (.value|type)
          + (if (.value|type)=="array" then ", len=" + (.value|length|tostring) else "" end)
          + ")"
          + (if (.value|type)=="array" and (.value|length)>0
             then "  | first=" + (.value[0]|tostring|.[0:60])
                + "  | last=" + (.value[-1]|tostring|.[0:60])
             else "" end)
    ' "$OBS"
    echo
    echo "Présence d'un axe temporel (recherche time, ts, timestamp, dates) :"
    jq -r '
        .data
        | to_entries
        | map(select(.key | test("^(time|ts|timestamp|date|dates)$")))
        | if length == 0
          then "  ⚠ Aucun axe temporel évident — il faudra peut-être déduire le pas via header.last_observation_time + interval implicite"
          else .[] | "  ✓ " + .key + " (len=" + (.value|length|tostring) + ", premier=" + (.value[0]|tostring) + ", dernier=" + (.value[-1]|tostring) + ")"
          end
    ' "$OBS"

    echo
    echo "Header brut de l'observation :"
    jq '.header' "$OBS" | sed 's/^/  /'

    echo
    echo "════════════════════════════════════════════════════════════════════"
    echo "Rapport prêt — colle le contenu de $REPORT."
    echo "════════════════════════════════════════════════════════════════════"
} > "$REPORT"

echo "✓ Terminé. Rapport : $REPORT"
