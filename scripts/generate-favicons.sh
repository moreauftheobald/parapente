#!/usr/bin/env bash
# Génère les déclinaisons PNG + ICO de la favicon depuis le SVG source.
#
# Pré-requis : ImageMagick installé (commande `convert` ou `magick`).
# Sur Ubuntu/Debian : sudo apt install imagemagick librsvg2-bin
#
# Sortie (toutes dans src/public/) :
#   - favicon.ico          (multi-résolution 16/32/48)
#   - icon-16.png ... 512.png
#   - apple-touch-icon.png (alias 180×180)
#
# Idempotent : relance autant de fois que tu veux.

set -euo pipefail

cd "$(dirname "$0")/.."

SRC="src/public/favicon.svg"
DEST="src/public"

if [[ ! -f "$SRC" ]]; then
    echo "✗ Source introuvable : $SRC" >&2
    exit 1
fi

# Détecte l'outil disponible
if command -v magick >/dev/null 2>&1; then
    CMD="magick"
elif command -v convert >/dev/null 2>&1; then
    CMD="convert"
else
    echo "✗ ImageMagick introuvable. Installe-le :" >&2
    echo "    Ubuntu/Debian : sudo apt install imagemagick librsvg2-bin" >&2
    echo "    macOS        : brew install imagemagick librsvg" >&2
    exit 1
fi

# Vérifie que ImageMagick sait lire le SVG (librsvg activé)
if ! "$CMD" -list delegate 2>/dev/null | grep -q -E "svg|rsvg"; then
    echo "⚠ ImageMagick semble ne pas avoir le delegate SVG. Si ça échoue," >&2
    echo "  installe librsvg2-bin (Linux) ou librsvg (macOS) et relance." >&2
fi

echo "→ Génération des PNG depuis $SRC…"
for SIZE in 16 32 48 96 180 192 512; do
    OUT="$DEST/icon-${SIZE}.png"
    "$CMD" -background none -density 384 "$SRC" -resize "${SIZE}x${SIZE}" "$OUT"
    echo "  · ${OUT} ($(wc -c < "$OUT") octets)"
done

echo "→ Génération du favicon.ico multi-résolution…"
"$CMD" "$DEST/icon-16.png" "$DEST/icon-32.png" "$DEST/icon-48.png" "$DEST/favicon.ico"
echo "  · $DEST/favicon.ico ($(wc -c < "$DEST/favicon.ico") octets)"

echo "→ Alias apple-touch-icon.png…"
cp "$DEST/icon-180.png" "$DEST/apple-touch-icon.png"
echo "  · $DEST/apple-touch-icon.png"

echo
echo "✓ Terminé. Commit/push pour propager."
