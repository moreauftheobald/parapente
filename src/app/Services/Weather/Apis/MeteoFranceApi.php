<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\MeteoFranceDps\DpsClient;
use App\Services\Weather\Apis\MeteoFranceDps\PackageMap;
use App\Services\Weather\Apis\MeteoFranceDps\RunResolver;
use App\Services\Weather\Grib\GribExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Météo-France API — AROME 1.3km/2.5km, ARPEGE 0.1°/0.25°.
 *
 * Approche DPS Paquets : un GET par (modèle, grille, paquet, run)
 * livre tout le paquet (toutes variables × tout l'horizon) en un seul
 * GRIB. Pour notre périmètre (SP1 + SP2 surface), 2 appels HTTP par
 * modèle par run, mutualisés entre tous les sites via GribCache.
 *
 * Auth : OAuth2 client_credentials par souscription, stockée sur
 * weather_models (chaque souscription DPS a ses propres credentials).
 */
class MeteoFranceApi implements WeatherApiInterface
{
    private const TOKEN_URL = 'https://portail-api.meteofrance.fr/token';
    private const TOKEN_REFRESH_MARGIN_S = 300;

    private ?WeatherApi $config = null;

    public function __construct(
        private readonly DpsClient $dps,
        private readonly GribExtractor $extractor,
    ) {}

    public function code(): string
    {
        return 'meteofrance';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config = $config;
    }

    public function supportedModelCodes(): array
    {
        return [
            'meteofrance_arome_france',
            'meteofrance_arpege_europe',
        ];
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        if (empty($model->oauth_client_id) || empty($model->oauth_client_secret)) {
            $this->fail("Credentials OAuth2 manquants pour {$model->code} (configurer client_id/secret dans l'admin du modèle).");
            return [];
        }
        if (empty($model->endpoint_url)) {
            $this->fail("Endpoint URL manquant pour {$model->code} (renseigner l'URL DPS de la souscription dans l'admin).");
            return [];
        }

        $config = PackageMap::forModel($model->code);
        if ($config === null) {
            $this->fail("Modèle MF non géré dans PackageMap: {$model->code}");
            return [];
        }

        $token = $this->getAccessToken($model);
        if ($token === null) {
            return [];
        }
        $this->dps->setAccessToken($token);

        // Résolution du run : on essaie chaque candidat en demandant
        // le manifest (JSON, rapide). Le 1er run avec un manifest non
        // vide est verrouillé.
        [$activeRun, $manifests] = $this->resolveRunAndFirstManifest($model, $config);
        if ($activeRun === null) {
            $this->fail("MF: aucun run accessible pour {$model->code} (testé candidats des dernières heures).");
            return [];
        }

        // Téléchargement des paquets et extraction au point du site.
        // Pour chaque paquet : 1 appel manifest + N appels productAR*
        // (un par échéance). Tous mutualisés via cache fichier.
        $byShortName = [];
        foreach ($config['packages'] as $package) {
            // Le manifest du 1er paquet a déjà été récupéré pendant
            // resolveRunAndFirstManifest — on évite un appel inutile.
            try {
                $manifest = $manifests[$package]
                    ?? $this->dps->fetchPackageManifest($model, $this->config, $activeRun, $config['grid'], $package);
            } catch (Throwable $e) {
                Log::warning('MeteoFranceApi: manifest paquet KO', [
                    'model'   => $model->code,
                    'package' => $package,
                    'message' => mb_substr($e->getMessage(), 0, 300),
                ]);
                continue;
            }

            foreach ($manifest as $entry) {
                try {
                    $path = $this->dps->ensureGribFromHref(
                        $model,
                        $this->config,
                        $activeRun,
                        $config['grid'],
                        $package,
                        $entry['time'],
                        $entry['href'],
                    );
                    $extracted = $this->extractor->extractMultiVarPointSeries(
                        $path,
                        (float) $site->latitude,
                        (float) $site->longitude,
                    );
                    foreach ($extracted as $shortName => $series) {
                        $byShortName[$shortName] = array_replace(
                            $byShortName[$shortName] ?? [],
                            $series,
                        );
                    }
                } catch (Throwable $e) {
                    Log::warning('MeteoFranceApi: échéance KO', [
                        'model'    => $model->code,
                        'package'  => $package,
                        'echeance' => $entry['time'] ?? '?',
                        'message'  => mb_substr($e->getMessage(), 0, 300),
                    ]);
                    // On continue avec les autres échéances — un slot
                    // partiel reste exploitable côté ScoringService.
                }
            }
        }

        if (empty($byShortName)) {
            $this->fail("MF {$model->code}: aucun paquet téléchargé pour {$site->slug} (run {$activeRun->format('Y-m-d H:i')}).");
            return [];
        }

        $slots = $this->mapToSlots($byShortName, $config['slotMapping']);
        $this->postProcess($slots);
        $kept = $this->filterUsable($slots);

        if (empty($kept)) {
            $this->fail("MF {$model->code}: paquets téléchargés mais aucun slot exploitable extrait (vérifie le mapping shortName → slot dans PackageMap, shortNames vus : " . implode(',', array_keys($byShortName)) . ').');
            return [];
        }

        Log::info('MeteoFranceApi: fetch OK', [
            'site'       => $site->slug,
            'model'      => $model->code,
            'run'        => $activeRun->format('Y-m-d H:i'),
            'shortNames' => array_keys($byShortName),
            'slots'      => count($kept),
        ]);
        $this->config?->recordSuccess();
        return $kept;
    }

    /**
     * Probe les runs candidats en récupérant le manifest du 1er paquet
     * (appel JSON rapide, pas de téléchargement GRIB). Le 1er run qui
     * répond avec une liste d'échéances non vide est verrouillé.
     *
     * Le manifest récupéré est renvoyé pour éviter un 2e appel quand
     * on traitera ce paquet plus tard.
     *
     * @return array{0: ?CarbonImmutable, 1: array<string, list<array{time:string,href:string}>>}
     */
    private function resolveRunAndFirstManifest(WeatherModel $model, array $config): array
    {
        $candidates = RunResolver::candidates(
            $config['runHours'],
            $config['delayHours'],
            fallbacks: 3,
        );
        $firstPackage = $config['packages'][0];

        foreach ($candidates as $run) {
            try {
                $manifest = $this->dps->fetchPackageManifest(
                    $model,
                    $this->config,
                    $run,
                    $config['grid'],
                    $firstPackage,
                );
                return [$run, [$firstPackage => $manifest]];
            } catch (Throwable $e) {
                Log::info('MeteoFranceApi: run candidate KO', [
                    'model'   => $model->code,
                    'run'     => $run->format('Y-m-d H:i'),
                    'message' => mb_substr($e->getMessage(), 0, 300),
                ]);
                continue;
            }
        }
        return [null, []];
    }

    /**
     * Pour chaque clé de slot configurée, prend le 1er shortName qui
     * a des données dans $byShortName. Construit une map ts => slot.
     *
     * @param array<string, array<string, float>> $byShortName
     * @param array<string, list<string>>          $slotMapping
     * @return array<string, array<string, float|int>>
     */
    private function mapToSlots(array $byShortName, array $slotMapping): array
    {
        $slots = [];
        foreach ($slotMapping as $slotKey => $candidateShortNames) {
            $picked = null;
            foreach ($candidateShortNames as $sn) {
                if (! empty($byShortName[$sn])) {
                    $picked = $byShortName[$sn];
                    break;
                }
            }
            if ($picked === null) {
                continue;
            }
            foreach ($picked as $when => $value) {
                $slots[$when] ??= [];
                $slots[$when][$slotKey] = $value;
            }
        }
        return $slots;
    }

    /**
     * Calculs dérivés et conversions d'unités. La logique tolère la
     * présence de DD/FF directs OU d'U/V à combiner — les deux sources
     * sont prises en compte avec préférence à DD/FF (déjà sous forme
     * vent météo).
     */
    private function postProcess(array &$slots): void
    {
        ksort($slots);

        foreach ($slots as $when => $slot) {
            // Vent : si DD/FF déjà présents (ARPEGE SP1 expose les deux
            // formes), on convertit FF m/s → km/h. Sinon U/V → speed/dir.
            if (isset($slot['wind_speed_avg'])) {
                $slots[$when]['wind_speed_avg'] = (float) $slot['wind_speed_avg'] * 3.6;
            } elseif (isset($slot['_wind_u'], $slot['_wind_v'])) {
                $u = (float) $slot['_wind_u'];
                $v = (float) $slot['_wind_v'];
                $slots[$when]['wind_speed_avg'] = sqrt($u * $u + $v * $v) * 3.6;
            }
            if (! isset($slots[$when]['wind_direction'])
                && isset($slot['_wind_u'], $slot['_wind_v'])
            ) {
                $slots[$when]['wind_direction'] = self::uvToFromDirection(
                    (float) $slot['_wind_u'],
                    (float) $slot['_wind_v'],
                );
            } elseif (isset($slots[$when]['wind_direction'])) {
                $slots[$when]['wind_direction'] = (int) round($slots[$when]['wind_direction']) % 360;
            }
            $slots[$when]['wind_speed_min'] ??= $slots[$when]['wind_speed_avg'] ?? null;

            // Rafale
            if (isset($slot['wind_speed_max'])) {
                $slots[$when]['wind_speed_max'] = (float) $slot['wind_speed_max'] * 3.6;
            } elseif (isset($slot['_gust_u'], $slot['_gust_v'])) {
                $gu = (float) $slot['_gust_u'];
                $gv = (float) $slot['_gust_v'];
                $slots[$when]['wind_speed_max'] = sqrt($gu * $gu + $gv * $gv) * 3.6;
            } elseif (isset($slots[$when]['wind_speed_avg'])) {
                $slots[$when]['wind_speed_max'] = $slots[$when]['wind_speed_avg'];
            }
            unset(
                $slots[$when]['_wind_u'], $slots[$when]['_wind_v'],
                $slots[$when]['_gust_u'], $slots[$when]['_gust_v'],
            );

            // Température : K → °C si > 100 (heuristique anti double conversion).
            if (isset($slots[$when]['temperature']) && $slots[$when]['temperature'] > 100) {
                $slots[$when]['temperature'] = (float) $slots[$when]['temperature'] - 273.15;
            }

            // Humidité — eccodes 2r est généralement déjà en %.
            if (isset($slots[$when]['humidity'])) {
                $slots[$when]['humidity'] = (float) $slots[$when]['humidity'];
            }

            // Plafond nuageux : Td direct si exposé, sinon dérivé via
            // Magnus inverse depuis T+RH.
            if (isset($slots[$when]['temperature'])) {
                $td = null;
                if (isset($slots[$when]['dew_point'])) {
                    $td = (float) $slots[$when]['dew_point'];
                    if ($td > 100) {
                        $td -= 273.15;
                    }
                } elseif (isset($slots[$when]['humidity'])) {
                    $td = self::dewPointFromTRh(
                        (float) $slots[$when]['temperature'],
                        (float) $slots[$when]['humidity'],
                    );
                }
                if ($td !== null) {
                    $slots[$when]['cloud_base_m'] = self::henningCloudBase(
                        (float) $slots[$when]['temperature'],
                        $td,
                    );
                }
            }
            unset($slots[$when]['dew_point']);

            // Cloud cover : valeurs eccodes typiquement en %.
            foreach (['cloud_cover_low', 'cloud_cover_mid', 'cloud_cover_high'] as $k) {
                if (isset($slots[$when][$k])) {
                    $slots[$when][$k] = max(0, min(100, (int) round($slots[$when][$k])));
                } else {
                    $slots[$when][$k] = 0;
                }
            }
            unset($slots[$when]['cloud_cover_total']);

            // Précipitations : on garde la valeur brute (cumulative
            // ou horaire selon la table eccodes). Dé-cumulation
            // simple : différence avec le slot précédent. Faite plus
            // bas dans une seconde passe.
            $slots[$when]['precipitation'] ??= 0.0;
        }

        // Dé-cumulation des précipitations (si le shortName tp est
        // cumulatif depuis le début du run).
        $prev = null;
        foreach ($slots as $when => $slot) {
            if (! isset($slot['precipitation_cumul'])) {
                continue;
            }
            $cumul = (float) $slot['precipitation_cumul'];
            $slots[$when]['precipitation'] = $prev === null ? 0.0 : max(0.0, $cumul - $prev);
            unset($slots[$when]['precipitation_cumul']);
            $prev = $cumul;
        }
    }

    /**
     * Garde uniquement les slots utilisables (vent direction + vitesse
     * présents) et qui ne sont pas dans le passé.
     */
    private function filterUsable(array $slots): array
    {
        $now = CarbonImmutable::now();
        $kept = [];
        foreach ($slots as $when => $slot) {
            if (! isset($slot['wind_direction'], $slot['wind_speed_avg'])) {
                continue;
            }
            try {
                $ts = CarbonImmutable::parse($when, 'UTC');
            } catch (Throwable) {
                continue;
            }
            if ($ts->lt($now)) {
                continue;
            }
            $kept[$when] = $slot;
        }
        return $kept;
    }

    /**
     * Composantes (u, v) m/s → direction FROM (météo standard, degrés).
     *   Convention WMO : u positif = vent vers l'est, v positif = vers le nord.
     *   FROM = atan2(-u, -v) puis normalisation [0, 360[.
     */
    private static function uvToFromDirection(float $u, float $v): int
    {
        $deg = rad2deg(atan2(-$u, -$v));
        $deg = fmod($deg + 360.0, 360.0);
        return (int) round($deg) % 360;
    }

    /** Magnus inverse : RH (%) + T (°C) → Td (°C). */
    private static function dewPointFromTRh(float $tempC, float $rhPct): float
    {
        $a = 17.625;
        $b = 243.04;
        $rh = max(1.0, min(100.0, $rhPct));
        $gamma = log($rh / 100.0) + ($a * $tempC) / ($b + $tempC);
        return ($b * $gamma) / ($a - $gamma);
    }

    /** Henning : plafond nuageux (m) depuis spread T-Td (°C). */
    private static function henningCloudBase(float $tempC, float $dewC): int
    {
        $spread = max(0.0, $tempC - $dewC);
        return (int) round(($spread / 8.0) * 1000.0);
    }

    private function fail(string $msg): void
    {
        Log::warning($msg);
        $this->config?->recordError($msg);
    }

    /**
     * Récupère un access_token valide. Renouvelle automatiquement si
     * absent ou proche de l'expiration. Le token est chiffré sur
     * weather_models (cf. cast 'encrypted').
     */
    private function getAccessToken(WeatherModel $model): ?string
    {
        if (! empty($model->oauth_token)
            && $model->oauth_expires_at
            && $model->oauth_expires_at->gt(now()->addSeconds(self::TOKEN_REFRESH_MARGIN_S))
        ) {
            return $model->oauth_token;
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth($model->oauth_client_id, $model->oauth_client_secret)
                ->asForm()
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'client_credentials',
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                $this->fail(
                    "OAuth2 token MF: HTTP {$response->status()}. "
                    . 'Vérifie client_id/secret. Body: '
                    . mb_substr((string) $response->body(), 0, 200)
                );
                return null;
            }

            $payload = $response->json();
            $token   = $payload['access_token'] ?? null;
            $ttl     = (int) ($payload['expires_in'] ?? 3600);

            if (! $token) {
                $this->fail('OAuth2 token MF: réponse sans access_token: ' . json_encode($payload));
                return null;
            }

            $model->oauth_token      = $token;
            $model->oauth_expires_at = now()->addSeconds($ttl);
            $model->save();

            return $token;
        } catch (Throwable $e) {
            $this->fail('OAuth2 token MF exception: ' . $e->getMessage());
            return null;
        }
    }
}
