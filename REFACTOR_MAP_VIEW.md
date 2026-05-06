# Plan de refactor — `map/index.blade.php`

> Document de planification pour une session future. À lire en priorité avant
> d'attaquer le refactor. Aucune implémentation nécessaire pour l'instant —
> ce fichier décrit *ce qu'il faut faire*, *dans quel ordre*, et avec
> *quelles précautions*.

## Contexte

Le fichier `src/resources/views/map/index.blade.php` est passé de ~540 lignes
à ~1230 lignes au fil des features (panel multi-modèles, 7 graphes SVG,
tooltip, onglet 5 jours…). Il devient difficile à naviguer pour la
maintenance, surtout pour quelqu'un qui débute en Laravel.

L'objectif : **éclater le fichier en partials Blade** par responsabilité,
sans changer le comportement, sans build Vite supplémentaire, sans toucher
aux conventions du `CLAUDE.md`.

## Contraintes à respecter (durant tout le refactor)

1. **Un seul `x-data="mapApp()"`** (cf. CLAUDE.md). Les partials Blade ne
   sont QUE des morceaux de template inclus dans la racine `<div id="pg-app"
   x-data="mapApp()">`. Aucun `x-data` imbriqué.
2. **CSS inline / pas de Tailwind** dans la vue carte. On ne migre RIEN vers
   les classes utilitaires.
3. **Pas de dépendance Vite supplémentaire**. Tout reste émis via Blade
   `@push('styles')` et `@push('scripts')`. Pas de fichiers `.js`/`.css`
   séparés à compiler.
4. **Scope global JS partagé** : tous les `@include` dans le `<script>`
   parent partagent le même top-level. `const CHART_CONFIGS` déclaré dans
   `config.blade.php` est donc accessible depuis `app.blade.php`. C'est
   voulu, ne pas tenter de wrapper dans des modules ES.
5. **Pas de PR sans demande explicite** (instruction utilisateur durable).
   Travailler sur une branche dédiée et pousser.

## Architecture cible

```
src/resources/views/map/
├── index.blade.php                   ← orchestrateur (~50 lignes)
└── _partials/
    ├── styles/
    │   ├── base.blade.php            ← layout, marqueurs, scrollbar, leaflet, fonts
    │   ├── toolbar.blade.php         ← .dd-trigger, .dd-menu, .dd-item, .dd-arrow, .dd-sub
    │   ├── popup.blade.php           ← #chart-popup et son contenu
    │   ├── panel.blade.php           ← #panel + header + tabs + day-row + legend + loader
    │   ├── charts.blade.php          ← .chart-section, .chart-svg, .chart-toggle, collapse
    │   └── tooltip.blade.php         ← #chart-tooltip
    ├── html/
    │   ├── toolbar.blade.php         ← dropdowns jour + fond de carte (toolbar du haut)
    │   ├── legend.blade.php          ← légende (coin bas-gauche carte)
    │   ├── popup-chart.blade.php     ← #chart-popup (header + svg + foot)
    │   ├── panel.blade.php           ← #panel complet (header, tabs, today, fivedays, footer)
    │   ├── dropdowns.blade.php       ← menus position:fixed (jour + basemap)
    │   └── tooltip.blade.php         ← #chart-tooltip
    └── scripts/
        ├── config.blade.php          ← BASEMAP_LIST, CHART_CONFIGS, COMPASS_TICKS, COMPASS_8, NS, SC, DF
        ├── icon.blade.php            ← pgIcon (SVG du marqueur)
        ├── geometry.blade.php        ← svgMk, computeYDomain, formatTickValue, buildLinePath, makeGeometry, drawBackgroundBands, drawAxes
        ├── chart-line.blade.php      ← buildLineChart
        ├── chart-bar.blade.php       ← buildBarChart
        ├── popup-chart.blade.php     ← buildChartSVG (le popup, distinct des charts panel)
        ├── tooltip.blade.php         ← degToCompass, formatTooltipValue, attachTooltipHandlers
        └── app.blade.php             ← mapApp() — composant Alpine (~400 lignes)
```

## Mapping ligne-par-ligne (sur l'état actuel de `index.blade.php`)

> Les numéros de ligne sont valables au moment de la rédaction de ce plan
> (V1_CLAUDE @ 5e4d9d6). Si le fichier a évolué, recalculer avec
> `grep -n` sur les ancres mentionnées en commentaire.

### Styles → `_partials/styles/`

| Partial | Contenu actuel (lignes approx.) | Ancre |
|---|---|---|
| `base.blade.php` | body / .mono / #pg-app / #pg-toolbar / #map-wrap / #map / .pg-marker / scrollbar / .leaflet-* | ~7-13, 24-26, 79-86 |
| `toolbar.blade.php` | .dd-trigger / .dd-arrow / .dd-menu / .dd-item / .dd-sub | ~14-22 |
| `popup.blade.php` | /* Popup chart */ block + .popup-meta / .popup-foot | ~28-30 |
| `panel.blade.php` | /* Side panel */ block jusqu'à .panel-footer + @keyframes spin | ~31-77 |
| `charts.blade.php` | /* Sections de graphes */ jusqu'à .chart-svg | ~78-91 |
| `tooltip.blade.php` | /* Tooltip multi-modèles */ + #chart-tooltip rules | ~92-101 |

> Note : le `@keyframes spin` peut rester dans `panel.blade.php` (il est
> utilisé là-bas) ou monter dans `base.blade.php`. Choix arbitraire,
> garder cohérent.

### HTML → `_partials/html/`

| Partial | Plages de lignes | Repère |
|---|---|---|
| `toolbar.blade.php` | 120-146 | `{{-- ═══ TOOLBAR ═══ --}}` jusqu'à fin `<div id="pg-toolbar">` |
| `legend.blade.php` | 152-162 | `{{-- Légende --}}` (coin bas-gauche carte) |
| `popup-chart.blade.php` | 164-213 | `{{-- ── POPUP GRAPHIQUE ──── --}}` complet |
| `panel.blade.php` | 215-336 | `{{-- Side panel ─── --}}` complet (header, tabs, today, fivedays, footer) |
| `tooltip.blade.php` | 339-355 | `{{-- ═══ TOOLTIP MULTI-MODÈLES ═══ --}}` |
| `dropdowns.blade.php` | 357-378 | `{{-- ═══ DROPDOWNS ═══ --}}` |

### Scripts → `_partials/scripts/`

| Partial | Symboles | Lignes approx. |
|---|---|---|
| `config.blade.php` | `BASEMAP_LIST`, `SC`, `DF`, `NS`, `CHART_CONFIGS`, `COMPASS_TICKS`, `COMPASS_8` | 384-396, 521-529, 635-641, 787 |
| `icon.blade.php` | `pgIcon(c)` | 392 |
| `geometry.blade.php` | `svgMk`, `computeYDomain`, `formatTickValue`, `buildLinePath`, `drawBackgroundBands`, `drawAxes`, `makeGeometry` | 532-708 (sauf `COMPASS_TICKS` qui va dans `config`) |
| `chart-line.blade.php` | `buildLineChart` | 711-744 |
| `chart-bar.blade.php` | `buildBarChart` | 749-784 |
| `popup-chart.blade.php` | `buildChartSVG` | 399-519 |
| `tooltip.blade.php` | `degToCompass`, `formatTooltipValue`, `attachTooltipHandlers` | 788-884 |
| `app.blade.php` | `mapApp()` (Alpine component complet) | 887-1230 |

## `index.blade.php` final

```blade
@extends('layouts.app')
@section('title', 'Carte météo parapente — Grand Est')

@push('styles')
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        @include('map._partials.styles.base')
        @include('map._partials.styles.toolbar')
        @include('map._partials.styles.popup')
        @include('map._partials.styles.panel')
        @include('map._partials.styles.charts')
        @include('map._partials.styles.tooltip')
    </style>
@endpush

@section('content')
    <div id="pg-app" x-data="mapApp()" x-init="init()"
         @click.window="dayDropOpen=false; bmDropOpen=false;">
        @include('map._partials.html.toolbar')

        <div id="map-wrap">
            <div id="map"></div>
            @include('map._partials.html.legend')
            @include('map._partials.html.popup-chart')
            @include('map._partials.html.panel')
        </div>

        @include('map._partials.html.tooltip')
        @include('map._partials.html.dropdowns')
    </div>
@endsection

@push('scripts')
    <script>
        @include('map._partials.scripts.config')
        @include('map._partials.scripts.icon')
        @include('map._partials.scripts.geometry')
        @include('map._partials.scripts.chart-line')
        @include('map._partials.scripts.chart-bar')
        @include('map._partials.scripts.popup-chart')
        @include('map._partials.scripts.tooltip')
        @include('map._partials.scripts.app')
    </script>
@endpush
```

> ⚠️ **Important sur l'ordre des `@include` scripts** : `config` doit venir
> en premier (les autres en dépendent), `app` en dernier (mapApp utilise
> tout le reste). Les autres peuvent s'intercaler dans n'importe quel
> ordre car les fonctions ne sont pas appelées au chargement, seulement
> définies.

## Plan d'exécution suggéré

### Branche dédiée
```
git checkout V1_CLAUDE && git pull
git checkout -b refactor/split-map-view
```

### Étape 1 — Scripts (le plus gros bénéfice, le plus risqué)
Découper d'abord les ~750 lignes de JS car c'est ce qui pèse le plus dans le
fichier et c'est là que le bénéfice de navigation est maximal.

1. Créer `_partials/scripts/config.blade.php` avec toutes les constantes
2. Créer les autres partials scripts (icon, geometry, chart-line, chart-bar, popup-chart, tooltip, app)
3. Remplacer dans `index.blade.php` le bloc `@push('scripts')` par la
   nouvelle version avec `@include`
4. **Tester** : recharger la page, vérifier que tout fonctionne (carte,
   popup, panel, onglet 5 jours, tooltip, collapse). Si ça plante, c'est
   probablement un problème d'ordre des `@include` ou d'oubli d'une
   constante / fonction.
5. Commit + push

### Étape 2 — HTML
1. Créer les 6 partials HTML
2. Remplacer le `@section('content')` par la nouvelle version compacte
3. Tester (rendu visuel identique attendu, aucune logique modifiée)
4. Commit + push

### Étape 3 — CSS
1. Créer les 6 partials styles (concaténation de blocs CSS bruts, **sans**
   `<style>` tag dans les partials — le `<style>` reste dans `index`)
2. Remplacer le bloc `@push('styles')` par la nouvelle version
3. Tester (rendu visuel identique attendu)
4. Commit + push

> Faire 3 commits séparés permet, en cas de bug introduit, de cibler la
> régression et au besoin de revert un seul commit.

## Stratégie de validation

À la fin de chaque étape, vérifier méthodiquement :

- [ ] La carte se charge avec les 14 marqueurs colorés
- [ ] Click sur marqueur → popup graphique apparaît correctement
- [ ] Click "Détails ›" → panel s'ouvre, recentrage carte OK
- [ ] Onglet "Aujourd'hui" : 6 graphes visibles avec lignes consensus
- [ ] Survol des graphes → tooltip multi-modèles + ligne curseur
- [ ] Collapse / expand d'une section → re-render correct
- [ ] Onglet "Vue 5 jours" : 5 séparateurs, 5 labels jour, fenêtres solaires multiples
- [ ] Dropdowns toolbar (jour + fond de carte) fonctionnels
- [ ] Pas d'erreur JS en console
- [ ] `php artisan view:clear` après chaque étape (cache des vues)

## Pièges connus

1. **Ordre des `@include`** dans `<script>` : `config` avant tout, `app`
   après tout. Sinon `mapApp()` lit des `undefined`.
2. **`@include` n'ajoute PAS de `<script>` ou `<style>`** : les balises
   restent dans `index.blade.php`. Les partials émettent du contenu brut.
3. **Indentation** : les partials émettent du texte brut. Si on indente
   les `@include` dans le `<style>`, l'indentation du CSS s'ajoute. Pas
   bloquant mais cosmétique. Pareil pour le JS.
4. **Cache de vues** : Laravel met en cache les vues compilées. Toujours
   `docker exec parapente_php php artisan view:clear` après modification
   de structure de partials.
5. **`@include` vs `@includeIf`** : utiliser `@include` (échoue si fichier
   manquant — détecte les fautes de frappe immédiatement).

## Critères de succès

- `index.blade.php` < 80 lignes
- Aucun partial > 250 lignes
- Aucune régression visuelle ou fonctionnelle
- Comportement identique à V1_CLAUDE @ refactor (à comparer)
- Le `git diff` final montre essentiellement des déplacements (lignes
  supprimées d'un fichier = lignes ajoutées dans un partial)
