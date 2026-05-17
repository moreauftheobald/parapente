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
