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
            . '<svg x="5" y="5" width="14" height="14" viewBox="0 0 640 640">'
            . '<path fill="#fff" d="M119.9 75.5C108.6 68.6 93.8 72.3 86.9 83.6C62.1 124.6 47.9 172.7 47.9 224C47.9 275.3 62.1 323.4 86.9 364.4C93.8 375.7 108.5 379.4 119.9 372.5C131.3 365.6 134.9 350.9 128 339.5C107.7 305.9 96 266.3 96 224C96 181.7 107.7 142.1 128.1 108.4C135 97.1 131.3 82.3 120 75.4zM520 75.5C508.7 82.4 505 97.1 511.9 108.5C532.3 142.2 544 181.8 544 224.1C544 266.4 532.3 306 511.9 339.7C505 351 508.7 365.8 520 372.7C531.3 379.6 546.1 375.9 553 364.6C577.8 323.6 592 275.5 592 224.2C592 172.9 577.8 124.6 553 83.6C546.1 72.3 531.4 68.6 520 75.5zM352 279.4C371.1 268.3 384 247.7 384 224C384 188.7 355.3 160 320 160C284.7 160 256 188.7 256 224C256 247.7 268.9 268.4 288 279.4L288 544C288 561.7 302.3 576 320 576C337.7 576 352 561.7 352 544L352 279.4zM212.2 155C219.4 143.8 216.1 129 205 121.8C193.9 114.6 179 117.9 171.8 129C154.2 156.4 144 189 144 224C144 259 154.2 291.6 171.8 319C179 330.2 193.8 333.4 205 326.2C216.2 319 219.4 304.2 212.2 293C199.4 273.1 192 249.4 192 224C192 198.6 199.4 174.9 212.2 155zM468.2 129C461 117.8 446.2 114.6 435 121.8C423.8 129 420.6 143.8 427.8 155C440.6 174.9 448 198.6 448 224C448 249.4 440.6 273.1 427.8 293C420.6 304.2 423.9 319 435 326.2C446.1 333.4 461 330.1 468.2 319C485.8 291.6 496 259 496 224C496 189 485.8 156.4 468.2 129z"/>'
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
