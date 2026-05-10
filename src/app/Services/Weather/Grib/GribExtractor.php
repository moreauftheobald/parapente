<?php

declare(strict_types=1);

namespace App\Services\Weather\Grib;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Wrapper sur la CLI eccodes pour extraire des valeurs au point le
 * plus proche d'un (lat, lon) dans un fichier GRIB2 multi-message.
 *
 * On utilise `grib_get -l lat,lon,1` qui fait la recherche
 * nearest-neighbor côté C — beaucoup plus rapide que `grib_get_data`
 * qui dump TOUS les points de la grille (impraticable sur AROME 0.025
 * où chaque message contient ~130k points).
 *
 * Format de sortie attendu de `grib_get -l <lat>,<lon>,1 -p <keys>` :
 *   <key1> <key2> ... <nearestLat1> <nearestLon1> <distance1> <value1>
 * Une ligne par message GRIB. Le 1 final demande 1 voisin.
 *
 * Le binaire `grib_get` est fourni par `libeccodes-tools`.
 */
class GribExtractor
{
    private const MISSING_SENTINEL = '9999';

    public function __construct(
        private readonly string $binaryPath = 'grib_get',
        private readonly int $timeoutSeconds = 120,
    ) {}

    /**
     * Extrait la série (forecast_at => value) au point le plus proche
     * (GRIB single-paramètre).
     *
     * @return array<string, float>  'Y-m-d H:i:s' (UTC) => valeur
     */
    public function extractPointSeries(string $filePath, float $lat, float $lon): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException("GRIB file not found: {$filePath}");
        }
        $output = $this->runGribGet($filePath, $lat, $lon, withShortName: false);
        return $this->parsePointSeries($output);
    }

    /**
     * Extrait, pour chaque shortName présent, la série
     * (forecast_at => valeur) au point le plus proche (GRIB multi-paramètre).
     *
     * @return array<string, array<string, float>>  shortName => [ts => value]
     */
    public function extractMultiVarPointSeries(string $filePath, float $lat, float $lon): array
    {
        if (! is_file($filePath)) {
            throw new RuntimeException("GRIB file not found: {$filePath}");
        }
        $output = $this->runGribGet($filePath, $lat, $lon, withShortName: true);
        return $this->parseMultiVarPointSeries($output);
    }

    /**
     * Parse `grib_get -l lat,lon,1 -p validityDate,validityTime` :
     *   validityDate validityTime value
     *
     * @return array<string, float>
     */
    public function parsePointSeries(string $output): array
    {
        $series = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 3) {
                continue;
            }
            [$vDate, $vTime, $value] = $parts;
            if (! is_numeric($value) || $value === self::MISSING_SENTINEL) {
                continue;
            }
            $forecastAt = $this->buildForecastAt($vDate, $vTime);
            if ($forecastAt === null) {
                continue;
            }
            $series[$forecastAt] = (float) $value;
        }
        ksort($series);
        return $series;
    }

    /**
     * Parse `grib_get -l lat,lon,1 -p shortName,validityDate,validityTime` :
     *   shortName validityDate validityTime value
     *
     * @return array<string, array<string, float>>
     */
    public function parseMultiVarPointSeries(string $output): array
    {
        $byShortName = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 4) {
                continue;
            }
            [$shortName, $vDate, $vTime, $value] = $parts;
            if (! is_numeric($value) || $value === self::MISSING_SENTINEL) {
                continue;
            }
            $forecastAt = $this->buildForecastAt($vDate, $vTime);
            if ($forecastAt === null) {
                continue;
            }
            $byShortName[$shortName][$forecastAt] = (float) $value;
        }
        foreach ($byShortName as $sn => $byTime) {
            ksort($byTime);
            $byShortName[$sn] = $byTime;
        }
        return $byShortName;
    }

    /**
     * validityDate=20260510, validityTime=1200 → '2026-05-10 12:00:00'
     */
    private function buildForecastAt(string $date, string $time): ?string
    {
        if (! preg_match('/^\d{8}$/', $date)) {
            return null;
        }
        $time = str_pad($time, 4, '0', STR_PAD_LEFT);
        if (! preg_match('/^\d{4}$/', $time)) {
            return null;
        }
        return sprintf(
            '%s-%s-%s %s:%s:00',
            substr($date, 0, 4),
            substr($date, 4, 2),
            substr($date, 6, 2),
            substr($time, 0, 2),
            substr($time, 2, 2),
        );
    }

    private function runGribGet(
        string $filePath,
        float $lat,
        float $lon,
        bool $withShortName,
    ): string {
        $keys = $withShortName
            ? 'shortName,validityDate,validityTime'
            : 'validityDate,validityTime';

        $cmd = [
            $this->binaryPath,
            '-l', sprintf('%.6f,%.6f,1', $lat, $lon),
            '-p', $keys,
            '-F', '%.4g',
            $filePath,
        ];

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException("Unable to spawn {$this->binaryPath}");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);

        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (! $status['running']) {
                break;
            }
            if ((microtime(true) - $startedAt) > $this->timeoutSeconds) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                throw new RuntimeException("grib_get timed out after {$this->timeoutSeconds}s on {$filePath}");
            }
            usleep(50_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            Log::warning('GribExtractor: grib_get failed', [
                'file'      => $filePath,
                'exit_code' => $exitCode,
                'stderr'    => mb_substr($stderr, 0, 500),
            ]);
            throw new RuntimeException(
                "grib_get exit {$exitCode} on {$filePath}: " . mb_substr($stderr, 0, 200)
            );
        }

        return $stdout;
    }
}
