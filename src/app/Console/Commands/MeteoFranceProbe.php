<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\MeteoFranceApi;
use App\Services\Weather\Apis\MeteoFranceDps\PackageMap;
use App\Services\Weather\Apis\MeteoFranceDps\RunResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use ReflectionClass;

/**
 * Diagnostic CLI pour le fetch GRIB Météo-France (API DPS Paquets).
 *
 * Modes :
 *  --dps-discover : explore l'API (grids/packages/runs) et dump pour
 *                   pouvoir caler PackageMap sur la souscription réelle.
 *  défaut         : fetch complet via fetchForSiteAndModel (download
 *                   des paquets configurés + extraction au point du
 *                   site via eccodes).
 *
 * Le bouton « Test » de l'admin lance le même fetch via HTTP, ce qui
 * peut dépasser le timeout PHP-FPM (60s) si le download du GRIB est
 * lent. Cette commande fait pareil mais en CLI, sans timeout.
 */
class MeteoFranceProbe extends Command
{
    protected $signature = 'meteofrance:probe
                            {model : code du modèle (meteofrance_arome_france, meteofrance_arpege_europe)}
                            {--site= : slug du site (par défaut, premier site actif)}
                            {--dps-discover : explore l\'API DPS et dump grids/packages/runs disponibles}';

    protected $description = 'Diagnostic du fetch GRIB Météo-France via l\'API DPS Paquets.';

    public function handle(MeteoFranceApi $api): int
    {
        $modelCode = (string) $this->argument('model');
        $model = WeatherModel::where('code', $modelCode)->first();
        if (! $model) {
            $this->error("Modèle introuvable: {$modelCode}");
            return self::FAILURE;
        }
        $model->loadMissing('api');
        if (! $model->api) {
            $this->error("Modèle {$modelCode} sans API associée.");
            return self::FAILURE;
        }

        if ($this->option('dps-discover')) {
            return $this->dpsDiscover($api, $model);
        }

        $siteSlug = (string) ($this->option('site') ?? '');
        $site = $siteSlug !== ''
            ? Site::where('slug', $siteSlug)->first()
            : Site::active()->orderBy('id')->first();
        if (! $site) {
            $this->error('Aucun site disponible.');
            return self::FAILURE;
        }

        $config = PackageMap::forModel($modelCode);
        if ($config === null) {
            $this->error("PackageMap ne connait pas {$modelCode}.");
            return self::FAILURE;
        }
        $candidates = RunResolver::candidates($config['runHours'], $config['delayHours'], 3);

        $this->info("Modèle:    {$model->code}");
        $this->info("Grille:    {$config['grid']}");
        $this->info("Paquets:   " . implode(', ', $config['packages']));
        $this->info("Site:      {$site->slug} ({$site->latitude}, {$site->longitude})");
        $this->info("Cadence:   run toutes les {$config['runHours']}h, délai pub ~{$config['delayHours']}h");
        $this->info("Runs candidats:");
        foreach ($candidates as $r) {
            $this->line('  - ' . $r->format('Y-m-d H:i') . ' UTC');
        }

        $api->setConfig($model->api);
        $startedAt = microtime(true);
        $slots     = $api->fetchForSiteAndModel($site, $model);
        $elapsed   = (int) ((microtime(true) - $startedAt) * 1000);

        if (empty($slots)) {
            $this->error("Aucun slot exploitable extrait. last_error: " . ($model->api->fresh()->last_error ?? '(vide)'));
            $this->warn("Astuce : `php artisan meteofrance:probe {$modelCode} --dps-discover` pour voir grids/packages réels.");
            return self::FAILURE;
        }

        $this->info("OK — " . count($slots) . " slots extraits en {$elapsed} ms.");
        $this->newLine();
        $this->line('Échantillon (3 premiers slots) :');
        $i = 0;
        foreach ($slots as $when => $slot) {
            if ($i++ >= 3) break;
            $this->line("  {$when} → " . json_encode($slot, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    /**
     * Explore l'API DPS Paquets MF :
     *  - GET {endpoint}/grids                       → liste des grilles
     *  - GET {endpoint}/grids/{grid}/packages       → liste des paquets
     *  - GET {endpoint}/grids/{grid}/packages/{pkg} → détail d'un paquet
     *
     * Affiche tout pour qu'on cale ensuite PackageMap sur la réalité.
     */
    private function dpsDiscover(MeteoFranceApi $api, WeatherModel $model): int
    {
        $api->setConfig($model->api);
        $token = $this->getTokenViaReflection($api, $model);
        if ($token === null) {
            $this->error('Impossible d\'obtenir un access_token. Vérifie credentials.');
            return self::FAILURE;
        }

        $base = rtrim((string) $model->endpoint_url, '/');
        if ($base === '' || ! str_contains($base, '/models/')) {
            $this->error("endpoint_url doit pointer sur .../models/AROME ou .../models/ARPEGE. Reçu : {$model->endpoint_url}");
            return self::FAILURE;
        }

        $this->line("Base DPS : {$base}");
        $this->newLine();

        // 1) Description du modèle
        $this->info('GET ' . $base);
        $modelDesc = $this->dpsGetJson($base, $token);
        if ($modelDesc !== null) {
            $this->line(json_encode($modelDesc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
        }

        // 2) Liste des grilles
        $gridsUrl = $base . '/grids';
        $this->info("GET {$gridsUrl}");
        $grids = $this->dpsGetJson($gridsUrl, $token);
        if ($grids === null) {
            return self::FAILURE;
        }
        $this->line(json_encode($grids, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        $gridList = is_array($grids) ? $this->extractIds($grids, ['grid', 'name', 'id']) : [];
        if (empty($gridList)) {
            $this->warn('Aucune grille reconnaissable dans la réponse — adapte extractIds() si besoin.');
            return self::SUCCESS;
        }

        // 3) Pour chaque grille → liste des paquets
        foreach ($gridList as $g) {
            $pkgsUrl = "{$base}/grids/{$g}/packages";
            $this->info("GET {$pkgsUrl}");
            $pkgs = $this->dpsGetJson($pkgsUrl, $token);
            if ($pkgs === null) {
                continue;
            }
            $this->line(json_encode($pkgs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();

            $pkgList = is_array($pkgs) ? $this->extractIds($pkgs, ['package', 'name', 'id']) : [];

            // 4) Détail du 1er paquet : liste des runs disponibles
            if (! empty($pkgList)) {
                $first = $pkgList[0];
                $detailUrl = "{$base}/grids/{$g}/packages/{$first}";
                $this->info("GET {$detailUrl}  (échantillon, 1er paquet seulement)");
                $detail = $this->dpsGetJson($detailUrl, $token);
                if ($detail !== null) {
                    $this->line(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $this->newLine();

                    // 5) Suit le 1er lien de run (ex: ?referencetime=...) pour voir
                    //    les échéances et URLs de téléchargement réelles.
                    $runLink = $this->firstNonSelfHref($detail);
                    if ($runLink !== null) {
                        $this->info("GET {$runLink}  (1er run, pour voir les échéances + format productAR*)");
                        $runDetail = $this->dpsGetJson($runLink, $token);
                        if ($runDetail !== null) {
                            $this->line(json_encode($runDetail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            $this->newLine();
                        }
                    }
                }
            }
        }

        $this->info('→ Colle cette sortie pour qu\'on adapte PackageMap.');
        return self::SUCCESS;
    }

    /** Prend le 1er lien rel != self du payload HATEOAS, ou null. */
    private function firstNonSelfHref(array $payload): ?string
    {
        if (! isset($payload['links']) || ! is_array($payload['links'])) {
            return null;
        }
        foreach ($payload['links'] as $link) {
            if (! is_array($link) || ! isset($link['href']) || ! is_string($link['href'])) {
                continue;
            }
            if (($link['rel'] ?? '') === 'self') {
                continue;
            }
            return $link['href'];
        }
        return null;
    }

    private function dpsGetJson(string $url, string $token): mixed
    {
        $resp = Http::timeout(30)->withToken($token)->acceptJson()->get($url);
        if (! $resp->successful()) {
            $this->error("HTTP {$resp->status()} sur {$url}: " . mb_substr((string) $resp->body(), 0, 300));
            return null;
        }
        return $resp->json();
    }

    /**
     * MF utilise HATEOAS — les enfants sont dans `links[]` avec rel != 'self'.
     * On extrait le dernier segment de chaque href après `$resourceType` dans
     * le path (ex: rel=grids → tout ce qui matche `.../grids/<id>`).
     */
    private function extractIds(array $payload, array $candidateKeys): array
    {
        $ids = [];

        // 1) HATEOAS : links[].href, rel != self.
        if (isset($payload['links']) && is_array($payload['links'])) {
            foreach ($payload['links'] as $link) {
                if (! is_array($link) || ! isset($link['href']) || ! is_string($link['href'])) {
                    continue;
                }
                if (($link['rel'] ?? '') === 'self') {
                    continue;
                }
                $href = $link['href'];
                // Strip query/fragment, take last segment
                $href = preg_replace('/[?#].*$/', '', $href);
                $href = rtrim((string) $href, '/');
                $segment = basename($href);
                if ($segment !== '' && $segment !== 'grids' && $segment !== 'packages') {
                    $ids[] = $segment;
                }
            }
        }

        // 2) Fallback : structure plate avec clés candidates.
        if (empty($ids)) {
            $walk = function ($node) use (&$walk, $candidateKeys, &$ids): void {
                if (! is_array($node)) {
                    return;
                }
                foreach ($candidateKeys as $k) {
                    if (isset($node[$k]) && is_string($node[$k])) {
                        $ids[] = $node[$k];
                    }
                }
                foreach ($node as $v) {
                    if (is_array($v)) {
                        $walk($v);
                    }
                }
            };
            $walk($payload);
        }

        return array_values(array_unique($ids));
    }

    private function getTokenViaReflection(MeteoFranceApi $api, WeatherModel $model): ?string
    {
        $rc = new ReflectionClass($api);
        $method = $rc->getMethod('getAccessToken');
        $method->setAccessible(true);
        return $method->invoke($api, $model);
    }

}
