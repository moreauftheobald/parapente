<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

// Tampon disque des icônes SpotAir (sites + balises).
//
// Les URL exposées par ce contrôleur sont des chemins statiques sous
// public/icons-cache/. Nginx les sert directement via try_files dès
// que le fichier existe sur disque (cf. nginx/nginx.prod.conf : SVG
// dans la liste d'assets statiques avec Cache-Control 30j). PHP n'est
// donc invoqué qu'au premier hit pour chaque combinaison de paramètres
// — ensuite, plus aucun appel ni à PHP ni à SpotAir.
//
// Réinitialisation : rm -rf public/icons-cache/ (ou un sous-dossier).
class IconCacheController extends Controller
{
    private const SPOTAIR_SITE_URL = 'https://www.spotair.mobi/icones/spots/spot.svg.php';
    private const SPOTAIR_BALISE_URL = 'https://www.spotair.mobi/icones/balises/balise.svg.php';

    private const STATION_NETWORK_COLORS = [
        'mf'         => '#3b82f6',
        'metar'      => '#7c3aed',
        'infoclimat' => '#16a34a',
    ];

    private const STATION_FRESHNESS_BORDERS = [
        'fresh' => null,
        'stale' => '#6b7280',
        'dead'  => '#4b5563',
    ];

    public function site(int $p, int $t, int $n, int $o): Response
    {
        $params = ["p={$p}", "t={$t}"];
        if ($n > 0) {
            $params[] = "n={$n}";
        }
        if ($o > 0) {
            $params[] = "o={$o}";
        }
        $url = self::SPOTAIR_SITE_URL . '?' . implode('&', $params);
        $relPath = "icons-cache/site/{$p}/{$t}/{$n}/{$o}.svg";

        return $this->fetchAndSave($url, $relPath);
    }

    public function balise(int $d, int $v, int $t, string $bg, string $c): Response
    {
        if (!in_array($bg, ['w', 'l', 'd'], true) || !in_array($c, ['g', 'b', 'o'], true)) {
            abort(404);
        }
        if ($d < 0 || $d > 359 || $v < 0 || $v > 999 || $t < -2 || $t > 2) {
            abort(404);
        }

        $url = self::SPOTAIR_BALISE_URL . "?d={$d}&v={$v}&t={$t}&bg={$bg}&c={$c}";
        $relPath = "icons-cache/balise/{$d}/{$v}/{$t}/{$bg}/{$c}.svg";

        return $this->fetchAndSave($url, $relPath);
    }

    public function station(string $network, string $freshness): Response
    {
        if (! isset(self::STATION_NETWORK_COLORS[$network])) {
            abort(404);
        }
        if (! array_key_exists($freshness, self::STATION_FRESHNESS_BORDERS)) {
            abort(404);
        }

        $relPath = "icons-cache/station/{$network}/{$freshness}.svg";
        $absPath = public_path($relPath);

        if (is_file($absPath)) {
            return response((string) file_get_contents($absPath), 200, $this->svgHeaders());
        }

        $svg = $this->buildStationSvg($network, $freshness);

        $dir = dirname($absPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            abort(500, 'Impossible de créer le répertoire tampon');
        }

        $tmp = $absPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $svg) === false) {
            abort(500, 'Écriture tampon échouée');
        }
        @rename($tmp, $absPath);

        return response($svg, 200, $this->svgHeaders());
    }

    private function buildStationSvg(string $network, string $freshness): string
    {
        $bg = self::STATION_NETWORK_COLORS[$network];
        $border = self::STATION_FRESHNESS_BORDERS[$freshness] ?? $bg;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">'
            . '<circle cx="12" cy="12" r="10" fill="' . $bg . '" stroke="' . $border . '" stroke-width="2"/>'
            . '<svg x="5" y="4.5" width="14" height="15" viewBox="0 0 576 544">'
            . '<path fill="#fff" d="M80 192v16c0 8.8-7.2 16-16 16s-16-7.2-16-16v-16c0-53 43-96 96-96h48V48c0-26.5 21.5-48 48-48s48 21.5 48 48v48h48c53 0 96 43 96 96v16c0 8.8-7.2 16-16 16s-16-7.2-16-16v-16c0-35.3-28.7-64-64-64H192c-35.3 0-64 28.7-64 64zm176-144a16 16 0 1 0 32 0 16 16 0 1 0-32 0zm-16 208a32 32 0 1 1 32 32 32 32 0 0 1-32-32zm-96 176v80h224v-80l-64-64H208l-64 64zm-32 80c0 17.7 14.3 32 32 32h224c17.7 0 32-14.3 32-32v-92.4l-73.4-73.4c-6-6-14.1-9.4-22.6-9.4H240c-8.5 0-16.6 3.4-22.6 9.4L144 419.6V512z"/>'
            . '</svg></svg>';
    }

    private function fetchAndSave(string $url, string $relPath): Response
    {
        $absPath = public_path($relPath);

        if (is_file($absPath)) {
            return response((string) file_get_contents($absPath), 200, $this->svgHeaders());
        }

        $response = Http::timeout(5)->get($url);
        if (!$response->successful()) {
            abort(502, 'SpotAir indisponible');
        }
        $svg = $response->body();
        if (!str_contains($svg, '<svg')) {
            abort(502, 'Réponse SpotAir invalide');
        }

        $dir = dirname($absPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            abort(500, 'Impossible de créer le répertoire tampon');
        }

        // Écriture atomique : tmp + rename, évite les fichiers à moitié écrits
        // si deux requêtes concurrentes touchent la même clé.
        $tmp = $absPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $svg) === false) {
            abort(500, 'Écriture tampon échouée');
        }
        @rename($tmp, $absPath);

        return response($svg, 200, $this->svgHeaders());
    }

    private function svgHeaders(): array
    {
        return [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=2592000',
        ];
    }
}
