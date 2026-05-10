<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis\MeteoFranceDps;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cache fichier pour les GRIB DPS téléchargés.
 *
 * Disposition : storage/app/grib/{model}/{run}/{grid}/{package}.grib2
 *
 * Un GRIB DPS contient toutes les variables d'un paquet sur tout
 * l'horizon d'un run, donc 1 fichier suffit pour traiter les 14 sites
 * du Grand Est. Lock flock() pour éviter les téléchargements
 * concurrents (workers, queue), atomic move via .part. Cleanup
 * délégué à un job scheduled (purgeOldRuns).
 */
final class GribCache
{
    public function __construct(
        private readonly string $diskName = 'local',
        private readonly string $rootSubdir = 'grib',
    ) {}

    /**
     * Renvoie le chemin local d'un GRIB pour (model, segments...).
     * Si absent, exécute `$downloader($absolutePath)` qui doit écrire
     * le fichier au chemin fourni.
     *
     * @param list<string> $pathSegments  Segments de chemin sous le model
     *                                    (ex: [run, grid, package, echeance])
     * @param Closure(string $absolutePath): void $downloader
     */
    public function ensure(
        string $modelCode,
        array $pathSegments,
        Closure $downloader,
    ): string {
        $relPath = $this->relativePath($modelCode, $pathSegments);
        $disk    = Storage::disk($this->diskName);
        $absPath = $disk->path($relPath);

        if (is_file($absPath) && filesize($absPath) > 0) {
            return $absPath;
        }

        $dir = dirname($absPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create cache directory: {$dir}");
        }

        $lockPath = $absPath . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException("Cannot open lock file: {$lockPath}");
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException("Cannot acquire lock on {$lockPath}");
            }

            // Re-vérification après l'acquisition du lock : un autre
            // process a peut-être terminé pendant qu'on attendait.
            if (is_file($absPath) && filesize($absPath) > 0) {
                return $absPath;
            }

            $tmpPath = $absPath . '.part';
            try {
                $downloader($tmpPath);
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                throw $e;
            }

            if (! is_file($tmpPath) || filesize($tmpPath) === 0) {
                @unlink($tmpPath);
                throw new RuntimeException(
                    "Downloader did not produce a non-empty file at {$tmpPath}"
                );
            }

            if (! @rename($tmpPath, $absPath)) {
                @unlink($tmpPath);
                throw new RuntimeException("Cannot atomically move {$tmpPath} → {$absPath}");
            }

            return $absPath;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    /**
     * Supprime les runs plus anciens que $keepRuns runs par modèle.
     * Utilisé par un job de cleanup planifié.
     */
    public function purgeOldRuns(string $modelCode, int $keepRuns = 4): int
    {
        $disk = Storage::disk($this->diskName);
        $modelDir = $this->rootSubdir . '/' . $modelCode;
        if (! $disk->exists($modelDir)) {
            return 0;
        }

        $runDirs = $disk->directories($modelDir);
        sort($runDirs); // tri lexicographique = chronologique (Ymd-Hi)
        $toDelete = array_slice($runDirs, 0, max(0, count($runDirs) - $keepRuns));

        $deleted = 0;
        foreach ($toDelete as $rd) {
            if ($disk->deleteDirectory($rd)) {
                $deleted++;
                Log::info('GribCache: purged old run', ['dir' => $rd]);
            }
        }
        return $deleted;
    }

    private function relativePath(string $modelCode, array $pathSegments): string
    {
        $parts = array_map([self::class, 'sanitize'], $pathSegments);
        $tail = implode('/', $parts);
        return sprintf(
            '%s/%s/%s.grib2',
            $this->rootSubdir,
            self::sanitize($modelCode),
            $tail,
        );
    }

    private static function sanitize(string $segment): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $segment) ?? '_';
    }
}
