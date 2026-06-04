<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WeatherStation;
use App\Models\WeatherStationObservation;
use App\Services\Stations\MfStationProvider;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MfStationBackfill extends Command
{
    protected $signature = 'mf:backfill
        {--from= : Date/heure de début (ex: 2026-06-04T06:00)}
        {--to=   : Date/heure de fin (ex: 2026-06-04T18:00, défaut = maintenant)}
        {--rate=80 : Nombre max de requêtes API par minute (défaut 80, max 90)}
        {--dry-run : Afficher les slots sans appeler l\'API}';

    protected $description = 'Backfill historique des observations MF infrahoraire-6m sur une période';

    public function handle(): int
    {
        $fromRaw = $this->option('from');
        if (! $fromRaw) {
            $this->error('--from est obligatoire (ex: --from=2026-06-04T06:00)');
            return self::FAILURE;
        }

        $from = Carbon::parse($fromRaw)->second(0);
        $from->minute((int) floor($from->minute / 6) * 6);

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->second(0)
            : now()->second(0);
        $to->minute((int) floor($to->minute / 6) * 6);

        $ratePerMin = min(90, max(1, (int) $this->option('rate')));
        $sleepMs = (int) ceil(60000 / $ratePerMin);

        $slots = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $slots[] = $cursor->copy();
            $cursor->addMinutes(6);
        }

        $this->info("Période : {$from->format('Y-m-d H:i')} → {$to->format('Y-m-d H:i')}");
        $this->info("Slots de 6 min : " . count($slots));
        $this->info("Rate limit : {$ratePerMin} req/min → pause {$sleepMs}ms entre chaque appel");

        $estimatedSeconds = (int) ceil(count($slots) * $sleepMs / 1000);
        $estimatedMinutes = (int) ceil($estimatedSeconds / 60);
        $this->info("Durée estimée : ~{$estimatedMinutes} min ({$estimatedSeconds}s)");

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Mode dry-run — slots qui seraient fetchés :');
            foreach ($slots as $i => $slot) {
                $this->line("  [{$i}] {$slot->format('Y-m-d\TH:i:s\Z')}");
            }
            return self::SUCCESS;
        }

        if (! $this->confirm("Lancer le backfill de " . count($slots) . " slots (~{$estimatedSeconds}s) ?")) {
            return self::SUCCESS;
        }

        $stations = WeatherStation::query()
            ->where('network', WeatherStation::NETWORK_MF)
            ->where('active', true)
            ->get(['id', 'external_id'])
            ->keyBy('external_id');

        if ($stations->isEmpty()) {
            $this->error('Aucune station MF active en base.');
            return self::FAILURE;
        }

        $this->info("Stations MF actives : {$stations->count()}");
        $this->newLine();

        $provider = app(MfStationProvider::class);
        $bar = $this->output->createProgressBar(count($slots));
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% — %message%");
        $bar->setMessage('Démarrage…');

        $totalInserted = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($slots as $i => $slot) {
            $date = $slot->format('Y-m-d\TH:i:s\Z');
            $bar->setMessage($date);

            try {
                $readings = $this->fetchSlot($provider, $date);
            } catch (\Throwable $e) {
                $totalErrors++;
                $bar->setMessage("ERREUR {$date}: {$e->getMessage()}");
                $bar->advance();
                if ($i < count($slots) - 1) {
                    usleep($sleepMs * 1000);
                }
                continue;
            }

            if (empty($readings)) {
                $bar->advance();
                if ($i < count($slots) - 1) {
                    usleep($sleepMs * 1000);
                }
                continue;
            }

            $inserted = 0;
            $skipped = 0;

            foreach ($stations as $extId => $station) {
                $r = $readings[$extId] ?? null;
                if (! $r || ! ($r['observed_at'] ?? null)) {
                    continue;
                }

                $exists = WeatherStationObservation::where('weather_station_id', $station->id)
                    ->where('observed_at', $r['observed_at'])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                WeatherStationObservation::create([
                    'weather_station_id'  => $station->id,
                    'observed_at'         => $r['observed_at'],
                    'wind_direction'      => $r['wind_direction'],
                    'wind_speed_avg'      => $r['wind_speed_avg'],
                    'wind_speed_max'      => $r['wind_speed_max'],
                    'wind_speed_max_10m'  => $r['wind_speed_max_10m'] ?? null,
                    'wind_direction_max'  => $r['wind_direction_max'] ?? null,
                    'wind_direction_gust' => $r['wind_direction_gust'] ?? null,
                    'temperature'         => $r['temperature'],
                    'temperature_min'     => $r['temperature_min'] ?? null,
                    'temperature_max'     => $r['temperature_max'] ?? null,
                    'humidity'            => $r['humidity'],
                    'humidity_min'        => $r['humidity_min'] ?? null,
                    'humidity_max'        => $r['humidity_max'] ?? null,
                    'pressure_hpa'        => $r['pressure_hpa'] ?? null,
                    'precipitation_mm'    => $r['precipitation_mm'] ?? null,
                    'cloud_cover_pct'     => $r['cloud_cover_pct'] ?? null,
                    'visibility_m'        => $r['visibility_m'] ?? null,
                    'dew_point'           => $r['dew_point'] ?? null,
                    'raw_data'            => $r['raw_data'] ?? null,
                ]);
                $inserted++;
            }

            $totalInserted += $inserted;
            $totalSkipped += $skipped;

            $bar->advance();

            if ($i < count($slots) - 1) {
                sleep($sleepSeconds);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Backfill terminé !");
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Slots traités', count($slots)],
                ['Observations insérées', $totalInserted],
                ['Doublons ignorés', $totalSkipped],
                ['Erreurs API', $totalErrors],
            ]
        );

        return self::SUCCESS;
    }

    private function fetchSlot(MfStationProvider $provider, string $date): array
    {
        $api = \App\Models\StationApi::where('code', 'mf')->first();
        if (! $api) {
            return [];
        }

        $token = $api->getOAuth2Token();

        $resp = \Illuminate\Support\Facades\Http::timeout(60)
            ->withToken($token)
            ->accept('*/*')
            ->get('https://public-api.meteofrance.fr/public/DPPaquetObs/v1/paquet/stations/infrahoraire-6m', [
                'format' => 'json',
                'date'   => $date,
            ]);

        $api->incrementRequestsToday();

        if (! $resp->ok()) {
            $api->recordError("MF backfill HTTP {$resp->status()} (date={$date})");
            throw new \RuntimeException("HTTP {$resp->status()}");
        }

        $api->recordSuccess();

        $data = $resp->json();
        if (! is_array($data)) {
            return [];
        }

        return $this->callParseObservations($provider, $data);
    }

    private function callParseObservations(MfStationProvider $provider, array $data): array
    {
        $ref = new \ReflectionMethod($provider, 'parseObservations');
        $ref->setAccessible(true);

        return $ref->invoke($provider, $data);
    }
}
