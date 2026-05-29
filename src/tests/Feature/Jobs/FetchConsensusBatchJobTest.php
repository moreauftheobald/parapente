<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchConsensusBatchJob;
use App\Models\Forecast;
use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\ConsensusApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le job batch alimente `forecasts` pour le modèle consensus à partir
 * d'un appel multi-coordonnées au sidecar, et reste robuste si le sidecar
 * ne répond pas (pas de lignes, pas d'exception).
 *
 * Le modèle `qui_vole_consensus` + l'API `consensus` sont déjà insérés par
 * la migration de données (RefreshDatabase la rejoue).
 */
class FetchConsensusBatchJobTest extends TestCase
{
    use RefreshDatabase;

    private function activeSite(float $lat, float $lng): Site
    {
        return Site::create([
            'name'      => 'S' . uniqid(),
            'slug'      => 's-' . uniqid(),
            'latitude'  => $lat,
            'longitude' => $lng,
            'altitude_m' => 500,
            'region'    => 'test',
            'active'    => true,
        ]);
    }

    public function test_batch_job_upserts_consensus_forecasts(): void
    {
        $s1 = $this->activeSite(49.0, 6.0);
        $s2 = $this->activeSite(48.0, 5.0);

        $hour = now('Europe/Paris')->addHours(2)->format('Y-m-d\TH:00');
        $station = [
            'hourly' => [
                'time'                       => [$hour],
                'wind_speed_10m'             => [5.0],
                'wind_gusts_10m'             => [8.0],
                'wind_direction_10m'         => [120],
                'precipitation'              => [0.0],
                'temperature_2m'             => [18.0],
                'relative_humidity_2m'       => [50],
                'qui_vole_cloud_base'        => [1500],
                'qui_vole_models_count'      => [9],
                'qui_vole_models_converging' => [7],
            ],
        ];

        Http::fake([
            '*forecast*' => Http::response([$station, $station], 200),
        ]);

        app(FetchConsensusBatchJob::class)->handle(app(\App\Services\Weather\Apis\WeatherApiRegistry::class));

        $consensusModelId = WeatherModel::where('code', ConsensusApi::MODEL_CODE)->value('id');

        $f1 = Forecast::where('site_id', $s1->id)->where('weather_model_id', $consensusModelId)->first();
        $f2 = Forecast::where('site_id', $s2->id)->where('weather_model_id', $consensusModelId)->first();

        $this->assertNotNull($f1);
        $this->assertNotNull($f2);
        $this->assertSame(120, (int) $f1->wind_direction);
        $this->assertSame(18.0, (float) $f1->wind_speed_avg);   // 5.0 × 3.6
        $this->assertSame(1500, (int) $f1->cloud_base_m);
        $this->assertSame(9, (int) $f1->models_count);
        $this->assertSame(7, (int) $f1->models_converging);
    }

    public function test_batch_job_no_rows_when_sidecar_down(): void
    {
        $this->activeSite(49.0, 6.0);
        Http::fake(['*forecast*' => Http::response('boom', 500)]);

        app(FetchConsensusBatchJob::class)->handle(app(\App\Services\Weather\Apis\WeatherApiRegistry::class));

        $consensusModelId = WeatherModel::where('code', ConsensusApi::MODEL_CODE)->value('id');
        $this->assertSame(0, Forecast::where('weather_model_id', $consensusModelId)->count());
    }
}
