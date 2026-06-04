<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WeatherStation;
use App\Models\WeatherStationObservation;
use App\Services\Stations\InfoclimatStationProvider;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InfoclimatStationBackfill extends Command
{
    protected $signature = 'infoclimat:backfill
        {--from= : Date de début (ex: 2026-06-01)}
        {--to=   : Date de fin (ex: 2026-06-04, défaut = aujourd\'hui)}
        {--batch=50 : Nombre de stations par requête API (défaut 50)}
        {--dry-run : Afficher les jours/batches sans appeler l\'API}';

    protected $description = 'Backfill historique des observations Infoclimat (StatIC) jour par jour';

    public function handle(): int
    {
        $fromRaw = $this->option('from');
        if (! $fromRaw) {
            $this->error('--from est obligatoire (ex: --from=2026-06-01)');
            return self::FAILURE;
        }

        $from = Carbon::parse($fromRaw)->startOfDay();
        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->startOfDay()
            : now()->startOfDay();

        $batchSize = max(1, (int) $this->option('batch'));

        $api = \App\Models\StationApi::where('code', 'infoclimat')->first();
        if (! $api || ! $api->active) {
            $this->error('API Infoclimat inactive ou absente dans station_apis.');
            return self::FAILURE;
        }

        $token = $api->api_key;
        if (empty($token)) {
            $this->error('Infoclimat : aucune API key configurée.');
            return self::FAILURE;
        }

        $stations = WeatherStation::query()
            ->where('network', WeatherStation::NETWORK_INFOCLIMAT)
            ->where('active', true)
            ->get(['id', 'external_id'])
            ->keyBy('external_id');

        if ($stations->isEmpty()) {
            $this->error('Aucune station Infoclimat active en base.');
            return self::FAILURE;
        }

        $stationIds = $stations->pluck('external_id')->all();
        $chunks = array_chunk($stationIds, $batchSize);

        $days = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        $totalRequests = count($days) * count($chunks);

        $this->info("Période : {$from->format('Y-m-d')} → {$to->format('Y-m-d')} (" . count($days) . " jours)");
        $this->info("Stations actives : {$stations->count()} → " . count($chunks) . " batches de {$batchSize}");
        $this->info("Requêtes API totales : {$totalRequests}");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Mode dry-run — jours qui seraient fetchés :');
            foreach ($days as $day) {
                $this->line("  {$day->format('Y-m-d')} → " . count($chunks) . " requêtes");
            }
            return self::SUCCESS;
        }

        if (! $this->confirm("Lancer le backfill ({$totalRequests} requêtes API) ?")) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalRequests);
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% — %message%");
        $bar->setMessage('Démarrage…');

        $totalInserted = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($days as $day) {
            $dateStr = $day->format('Y-m-d');

            foreach ($chunks as $chunkIdx => $chunk) {
                $bar->setMessage("{$dateStr} batch " . ($chunkIdx + 1) . '/' . count($chunks));

                try {
                    $result = $this->fetchDayBatch($token, $dateStr, $chunk);
                } catch (\Throwable $e) {
                    $totalErrors++;
                    $bar->setMessage("ERREUR {$dateStr}: {$e->getMessage()}");
                    $bar->advance();
                    usleep(500_000);
                    continue;
                }

                $hourly = $result['hourly'] ?? [];

                foreach ($hourly as $stationId => $observations) {
                    if (! is_array($observations) || empty($observations)) continue;

                    $station = $stations->get((string) $stationId);
                    if (! $station) continue;

                    $rows = [];
                    foreach ($observations as $obs) {
                        if (! is_array($obs)) continue;

                        $row = $this->parseRow($obs);
                        if (! $row) continue;

                        $rows[] = [
                            'weather_station_id' => $station->id,
                            'observed_at'        => $row['observed_at']->format('Y-m-d H:i:s'),
                            'wind_direction'     => $row['wind_direction'],
                            'wind_speed_avg'     => $row['wind_speed_avg'],
                            'wind_speed_max'     => $row['wind_speed_max'],
                            'temperature'        => $row['temperature'],
                            'humidity'           => $row['humidity'],
                            'pressure_hpa'       => $row['pressure_hpa'],
                            'precipitation_mm'   => $row['precipitation_mm'],
                            'cloud_cover_pct'    => null,
                            'visibility_m'       => null,
                            'dew_point'          => $row['dew_point'],
                            'raw_data'           => isset($obs) ? json_encode($obs) : null,
                            'created_at'         => now()->format('Y-m-d H:i:s'),
                        ];
                    }

                    if (! empty($rows)) {
                        $count = WeatherStationObservation::insertOrIgnore($rows);
                        $totalInserted += $count;
                        $totalSkipped += count($rows) - $count;
                    }
                }

                $bar->advance();
                usleep(500_000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Backfill terminé !");
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Jours traités', count($days)],
                ['Requêtes API', $totalRequests],
                ['Observations insérées', $totalInserted],
                ['Doublons ignorés', $totalSkipped],
                ['Erreurs API', $totalErrors],
            ]
        );

        return self::SUCCESS;
    }

    private function fetchDayBatch(string $token, string $date, array $stationIds): array
    {
        $query = http_build_query([
            'method' => 'get',
            'format' => 'json',
            'token'  => $token,
            'start'  => $date,
            'end'    => $date,
        ]);
        foreach ($stationIds as $id) {
            $query .= '&' . urlencode('stations[]') . '=' . urlencode($id);
        }

        $resp = Http::timeout(30)
            ->get('https://www.infoclimat.fr/opendata/?' . $query);

        if (! $resp->ok()) {
            throw new \RuntimeException("HTTP {$resp->status()}");
        }

        $data = $resp->json();
        if (($data['status'] ?? '') !== 'OK') {
            throw new \RuntimeException("Status: " . ($data['status'] ?? 'unknown'));
        }

        return $data;
    }

    private function parseRow(array $obs): ?array
    {
        $obsTimeRaw = $obs['dh_utc'] ?? null;
        if (! $obsTimeRaw) return null;

        try {
            $obsTime = Carbon::parse($obsTimeRaw);
        } catch (\Throwable) {
            return null;
        }

        return [
            'observed_at'      => $obsTime,
            'wind_direction'   => $this->parseInt($obs['vent_direction'] ?? null),
            'wind_speed_avg'   => $this->parseFloat($obs['vent_moyen'] ?? null),
            'wind_speed_max'   => $this->parseFloat($obs['vent_rafales'] ?? null),
            'temperature'      => $this->parseFloat($obs['temperature'] ?? null),
            'humidity'         => $this->parseInt($obs['humidite'] ?? null),
            'pressure_hpa'     => $this->parseFloat($obs['pression'] ?? null),
            'precipitation_mm' => $this->parseFloat($obs['pluie_1h'] ?? null),
            'dew_point'        => $this->parseFloat($obs['point_de_rosee'] ?? null),
        ];
    }

    private function parseFloat(mixed $val): ?float
    {
        if ($val === null || $val === '' || ! is_numeric($val)) return null;
        return round((float) $val, 1);
    }

    private function parseInt(mixed $val): ?int
    {
        if ($val === null || $val === '' || ! is_numeric($val)) return null;
        return (int) round((float) $val);
    }

}
