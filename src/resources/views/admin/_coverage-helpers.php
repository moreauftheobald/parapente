<?php

/**
 * Helpers partagés pour les tableaux de couverture de données.
 * Inclus via require dans chaque onglet Data des paramètres de section.
 */

$coverageCellClass = function ($cell): string {
    if (is_array($cell)) {
        if (! ($cell['in_horizon'] ?? true)) {
            return 'bg-gray-950 text-gray-700 border-gray-800';
        }
        $pct = $cell['pct'] ?? null;
    } else {
        $pct = $cell;
    }
    if ($pct === null) return 'bg-gray-800 text-gray-600 border-gray-700';
    if ($pct < 0.1)    return 'bg-gray-900 text-gray-600 border-gray-800';
    if ($pct < 50)     return 'bg-red-500/20 text-red-200 border-red-500/40';
    if ($pct < 95)     return 'bg-amber-500/15 text-amber-200 border-amber-500/40';
    return 'bg-emerald-500/15 text-emerald-200 border-emerald-500/40';
};

$fmtCell = function ($cell): string {
    if (is_array($cell)) {
        if (! ($cell['in_horizon'] ?? true)) return '—';
        $pct = $cell['pct'] ?? null;
    } else {
        $pct = $cell;
    }
    if ($pct === null) return '—';
    if ($pct < 0.1)    return '0';
    return rtrim(rtrim(number_format($pct, 1, ',', ''), '0'), ',') . '%';
};

$cellTooltip = function ($cell): string {
    if (is_array($cell)) {
        if (! ($cell['in_horizon'] ?? true)) {
            $h = $cell['expected_h'] ?? 0;
            return $h === 0 ? 'Hors horizon du modèle' : 'Hors horizon';
        }
        $pct = $cell['pct'] ?? null;
        $h   = $cell['expected_h'] ?? null;
        $base = $pct === null ? 'N/A' : $pct . ' %';
        return $h !== null ? "$base (attendu : {$h} h/jour)" : $base;
    }
    return $cell === null ? 'N/A' : $cell . ' %';
};

$fmtPct = $fmtCell;

$fmtDateTime = function (?\Carbon\Carbon $dt): string {
    if ($dt === null) return '—';
    return $dt->format('d/m H:i');
};

$fmtDay = function (\Carbon\CarbonImmutable $day, \Carbon\CarbonImmutable $today): string {
    $delta = (int) $today->diffInDays($day, false);
    if ($delta === 0)  return 'Aujourd\'hui';
    if ($delta === 1)  return 'Demain';
    if ($delta === -1) return 'Hier';
    if ($delta > 0)    return 'J+' . $delta;
    return 'J' . $delta;
};

$driftBadge = function (?float $pct): string {
    if ($pct === null) return 'bg-gray-800 text-gray-500 border-gray-700';
    $abs = abs($pct);
    if ($abs < 15) return 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40';
    if ($abs < 50) return 'bg-amber-500/15 text-amber-300 border-amber-500/40';
    return 'bg-red-500/20 text-red-300 border-red-500/40';
};
