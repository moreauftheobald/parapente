# FF — Palette accessible (daltonisme & contraste)

> **Statut** : à planifier (note de design)
> **Date de rédaction** : 2026-05-13
> **Pré-requis bloquant** : aucun — peut démarrer à tout moment.

Ce document consigne la discussion de cadrage. Il sert de point de
reprise quand on décidera d'implémenter la feature.

---

## Concept

Permettre à l'utilisateur de choisir une **palette de couleurs** adaptée
à sa vision : palette par défaut (rouge / orange / vert), palette
*colorblind-friendly* (CB) conforme aux recommandations Okabe-Ito, et
mode monochrome pour les cas de très basse vision.

L'objectif n'est pas seulement de changer les teintes : c'est de
garantir que l'information **statut** (favorable / prudence /
défavorable / indisponible) reste lisible pour les ~8 % d'hommes et
~0,5 % de femmes affectés par une forme de dyschromatopsie — sans
dégrader l'expérience des autres utilisateurs.

Précisions retenues :

- 3 thèmes proposés en V1 : `default`, `cb-friendly`, `monochrome`.
- Préférence stockée **par utilisateur** dans `users.ui_theme` (loggués) ;
  fallback global dans `settings` (`ui.default_theme`) pour les invités.
- Sélecteur exposé dans `/profil` (section Identité ou nouvelle section
  Préférences) **et** dans `/admin/settings` (clé du défaut).
- La palette s'applique à **toute l'application** : carte (halos
  markers, légendes), volet droit (cellules voting + onglets graphes),
  profil, écran scorings, admin.
- Renforcement par **redondance non chromatique** sur les éléments
  porteurs d'information statut : glyphe discret sur les pastilles
  voting (●/▲/✕/–), dash-pattern sur les courbes.

Restrictions :

- Les **icônes SpotAir** des sites de vol viennent d'une URL externe
  paramétrée (`n=` pour le niveau IPPI) ; on ne peut pas en changer les
  couleurs internes. La couleur du **niveau** reste donc invariable. On
  agit sur le **halo** autour du marker (drop-shadow CSS) et sur le
  **badge** de scoring perso, qui sont sous notre contrôle.

---

## Architecture retenue

### Modèle de données

```
users
└── ui_theme  varchar(32) nullable default null
              ('default' | 'cb-friendly' | 'monochrome' | null = hérite du défaut global)

settings
└── 'ui.default_theme'  string  default 'default'
                        sert aux invités et aux users qui n'ont pas
                        encore choisi.
```

`null` côté user permet de distinguer « pas choisi » de « choisi
explicitement default » — utile si on veut migrer le défaut global plus
tard sans réécraser les choix utilisateurs.

### Architecture front : variables CSS sémantiques

Aujourd'hui les couleurs sont **éparpillées** :
- CSS inline `map/_partials/styles/*.blade.php` : hex `#22c55e` /
  `#f59e0b` / `#ef4444` dans les drop-shadows, les légendes, les
  bargraphes ;
- JS `map/_partials/scripts/config.blade.php` : constante `SC = {green,
  orange, red, unknown}` puis usage direct dans `chart-line` /
  `chart-bar` / `popup-chart` ;
- Tailwind utility classes : `text-emerald-300`, `bg-amber-500/15`,
  `border-red-500/30` un peu partout dans le profil et l'admin.

Le pattern visé :

```css
:root {
    /* Statut "vert / favorable" */
    --status-go-fg:    #22c55e;
    --status-go-bg:    rgba(34,197,94,.15);
    --status-go-glow:  #3BFF00;        /* halo marker, plus saturé */
    --status-go-border:rgba(34,197,94,.30);

    /* Statut "orange / prudence" */
    --status-warn-fg:  #f59e0b;
    --status-warn-bg:  rgba(245,158,11,.15);
    --status-warn-glow:#fb923c;
    --status-warn-border:rgba(245,158,11,.30);

    /* Statut "rouge / défavorable" */
    --status-stop-fg:  #ef4444;
    --status-stop-bg:  rgba(239,68,68,.10);
    --status-stop-glow:#f87171;
    --status-stop-border:rgba(239,68,68,.30);

    /* "N/A / inconnu" */
    --status-na-fg:    rgba(75,85,99,.5);
    --status-na-bg:    rgba(75,85,99,.10);

    /* Séparateur diagonal des cellules split (voting cell) */
    --status-sep:      #ffffff;
}

body.theme-cb-friendly {
    --status-go-fg:    #009E73;   /* Bleu-vert Okabe-Ito */
    --status-go-glow:  #00C896;
    --status-warn-fg:  #F0E442;   /* Jaune-or */
    --status-warn-glow:#FFD700;
    --status-stop-fg:  #D55E00;   /* Vermillon */
    --status-stop-glow:#F26C19;
    /* na, sep inchangés */
}

body.theme-monochrome {
    --status-go-fg:    #ffffff;
    --status-go-glow:  #ffffff;
    --status-warn-fg:  #cccccc;
    --status-warn-glow:#cccccc;
    --status-stop-fg:  #888888;
    --status-stop-glow:#888888;
    /* + glyphes obligatoires côté CSS pour distinguer */
}
```

La classe `theme-<key>` est posée sur `<body>` par `<x-app-shell>`
(racine de toutes les vues, y compris la carte) à partir d'un helper
`Theme::current()` qui lit `users.ui_theme ?? Settings::get('ui.default_theme')`.

### Migration des références existantes

Trois cibles, par ordre d'impact :

1. **CSS inline carte** (`map/_partials/styles/*.blade.php`,
   `scripts/config.blade.php`, `chart-*`) — remplacer les hex literals
   par les variables. C'est mécanique, ~150 occurrences à traiter.
2. **Classes Tailwind sémantiquement « statut »** — créer des classes
   utilitaires métiers qui consomment les variables :
   ```html
   <!-- Avant -->
   <span class="text-emerald-300 bg-emerald-500/15 border-emerald-500/30">Actif</span>
   <!-- Après -->
   <span class="status-token status-token--go">Actif</span>
   ```
   Cibles principales : badges actif/inactif, statuts admin, bandeau
   scoring perso, panneau day quality, tuiles compteur du profil.
3. **JS** (`SC` dans config, palettes inline dans chart-bar/line) —
   exposer les variables via `getComputedStyle(document.documentElement)
   .getPropertyValue('--status-go-fg')` au boot, ou plus simple : passer
   en utilitaires CSS sur les SVG via `fill="currentColor"` et la classe
   parent.

> Les classes utility **non sémantiques** (`text-gray-400` pour du
> texte secondaire neutre, `bg-gray-950` pour du fond, etc.) restent
> Tailwind — on ne migre **que** les couleurs porteuses d'information
> statut.

### Redondance visuelle (pattern + glyph)

Le changement de palette résout l'ambiguïté entre teintes vert/rouge
mais pas le cas où le user a une vision **monochrome** ou voit toutes
les couleurs comme un dégradé gris. Solution : ajouter une **deuxième
dimension perceptive** sur les éléments critiques :

| Élément | Redondance non chromatique |
|---|---|
| Pastilles voting | Petit glyphe Unicode au centre : `●` go, `▲` warn, `✕` stop, `–` na |
| Cellule voting *split* | Diagonale blanche déjà en place (cf. lot scoring perso) — on garde |
| Halo marker | Faire varier l'**épaisseur** du drop-shadow selon le statut (vert épais, orange moyen, rouge fin) — info disponible même en niveaux de gris |
| Courbes graphes (chart-line) | `stroke-dasharray` selon statut (`0` plein = go, `4 2` pointillés = warn, `2 4` clairsemé = stop) |
| Bargraphes vent (chart-bar) | Hachures CSS (`background-image: repeating-linear-gradient`) en plus du fond coloré |

Activable indépendamment via `body.theme-with-glyphs` (combinable avec
`theme-cb-friendly`), pour permettre les 4 combinaisons. Au plus simple :
on active toujours les glyphes en mode `monochrome`, optionnel en
`cb-friendly`.

### Limites à connaître

- **Icônes SpotAir** : URL externe, couleurs internes non modifiables.
  On peut appliquer un `filter: hue-rotate()` côté CSS si on veut
  vraiment neutraliser, mais ça dégrade le rendu général (le niveau
  IPPI vert/bleu/marron perd son code couleur historique). **Reco :
  laisser l'icône SpotAir intacte**, miser sur le halo + le badge
  scoring perso (sous notre contrôle).
- **Tailwind purgeur** : les utility classes doivent rester littérales
  dans le code source. Les `var(--status-*)` ne sont pas suivies par le
  purge, ce qui est exactement ce qu'on veut — les variables vivent
  dans des fichiers CSS dédiés que Tailwind ne touche pas.
- **Pas de cascade automatique** depuis l'attribut `prefers-color-scheme`
  ou `prefers-contrast`. On peut l'ajouter en V2 : `@media
  (prefers-contrast: more)` → applique `monochrome`. Hors scope V1.

---

## Bornes / garde-fous

| Borne | Valeur | Justification |
|-------|--------|---------------|
| Nombre de thèmes V1 | 3 (`default`, `cb-friendly`, `monochrome`) | Suffisant pour couvrir les 3 grands cas (vision normale, dyschromatopsie rouge-vert, basse vision). Au-delà → choix paralysant. |
| Stockage user | colonne nullable | `null` = hérite du défaut global. Permet de changer le défaut global plus tard sans écraser les choix explicites. |
| Validation | enum strict côté FormRequest | Empêche un user de poser `theme-evil-disco` qui n'est pas câblé côté CSS et casserait le rendu. |
| Fallback | `default` si `ui_theme` non reconnu | Robustesse pour les migrations + les sessions de longue durée. |

---

## Estimation de coût

| Lot | Estimation |
|-----|-----------|
| Centralisation variables CSS + refactor CSS inline carte | 0,5 j |
| Migration classes Tailwind sémantiques (badges + tuiles + bandeau) | 0,5 j |
| JS map : remplacer constantes hex par lecture des variables | 0,3 j |
| Thèmes `cb-friendly` + `monochrome` (override CSS) | 0,2 j |
| Redondance visuelle (glyphes pastilles, dash courbes, halo épaisseur) | 0,5 j |
| Stockage prefs (`users.ui_theme`, `settings.ui.default_theme`) | 0,2 j |
| Sélecteur dans `/profil` + `/admin/settings` | 0,3 j |
| Recettage (3 thèmes × écrans clés × vérif contraste WCAG AA) | 0,5 j |
| **Total** | **~3 j** |

## Ordre de découpage suggéré (en 2 PR)

1. **Refactor palette + variables CSS** *(invisible côté UI, livrable
   indépendant)* — centralisation des couleurs, prépa du terrain. Pas
   de changement fonctionnel pour l'utilisateur. Permet de découvrir
   les corner-cases (couleurs qui dépendent de l'animation, JS qui
   construit des SVG inline avec hex en dur, etc.) sans pression.
2. **Thèmes + sélecteur + redondance** — la partie visible : ajout des
   3 thèmes, glyphes voting, courbes en dash, écran de préférences. Une
   fois la couche 1 stable, c'est essentiellement du « pose une classe
   sur `<body>` et redéfinis 12 variables ».

Ce découpage permet de livrer la partie technique sans engagement
visuel immédiat, puis d'itérer sur les thèmes (palette, glyphes,
proportions) sans toucher au refactor sous-jacent.

---

## Risques résiduels

1. **Cas oubliés lors du refactor** : un hex dur planqué dans un SVG
   construit dynamiquement en JS, un `filter: drop-shadow(... color)`
   qui ne lit pas une variable, etc. → recettage des 3 thèmes sur
   chaque écran clé est non négociable. Test visuel humain, pas
   automatisable simplement.
2. **Contraste WCAG** : la palette CB-friendly Okabe-Ito est validée en
   recherche pour la **discrimination** entre couleurs, mais le
   contraste fond/texte doit être vérifié séparément (AA = ratio ≥ 4.5
   pour le texte normal). Notre fond `#0f172a` (gray-950) demande des
   premiers plans clairs ; à vérifier sur les 3 thèmes. Outil : axe
   DevTools / WebAIM Contrast Checker.
3. **Icônes SpotAir non modifiables** : si un user attend que **tout**
   change avec le thème, sera surpris que le bouclier de niveau
   conserve son code couleur historique. À documenter dans la note de
   préférences (« la palette s'applique aux statuts météo et aux
   éléments d'interface, pas aux icônes des sites issues d'une source
   externe »).
4. **Cache navigateur** : changer de thème ne devrait pas exiger un
   hard-refresh (CSS sur `<body>`, applique instantanément). Mais si le
   user a modifié sa préférence depuis un autre onglet, l'onglet
   courant ne le saura pas — c'est un cas marginal, à ignorer en V1.
5. **Performance imperceptible** : un thème = un changement de classe
   CSS, aucun rerender JS. Pas de risque mesurable.
6. **Régression visuelle pour les non-CB** : la palette par défaut ne
   bouge pas, donc aucun impact pour le user normal. Le mode
   `cb-friendly` introduit du jaune-or à la place de l'orange — c'est
   un changement esthétique notable que certains préféreront, d'autres
   non. Bien afficher un libellé clair (« Optimisé daltonisme ») dans
   le sélecteur.

---

## Pour aller plus loin (hors scope V1)

- **Détection auto** : `@media (prefers-contrast: more)` pour activer
  `monochrome` sans intervention user. Plus `prefers-reduced-motion`
  pour neutraliser les animations de halo si pertinent.
- **Mode clair** : la V2 du projet est en thème sombre uniquement. Un
  thème clair éventuel s'intégrerait naturellement dans le même système
  de classes `theme-*` — pas de surcoût d'architecture.
- **Personnalisation libre** : laisser l'utilisateur définir ses
  propres couleurs (color picker). Overkill V1, intéressant si demande
  utilisateur. La couche variables CSS le rend trivial à brancher.
- **Tests automatisés** : impossible de tester la perception
  daltonienne en CI, mais on peut au moins **lint** que les seules
  couleurs « statut » utilisées dans les vues sont via `var(--status-*)`
  (grep custom dans un test). Bonus si on veut éviter qu'un dev re-coule
  un `text-emerald-500` dur en V3.
