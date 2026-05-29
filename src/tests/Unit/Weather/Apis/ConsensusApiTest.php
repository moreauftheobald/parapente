<?php

declare(strict_types=1);

namespace Tests\Unit\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\ConsensusApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Vérifie le parsing de la réponse du sidecar consensus (format
 * Open-Meteo) : conversion m/s → km/h, mapping du plafond et des
 * métadonnées de consensus.
 *
 * Pas de DB : on n'appelle pas setConfig() (les compteurs de la WeatherApi
 * ne sont donc pas touchés) — l'URL par défaut suffit, Http::fake intercepte.
 */
class ConsensusApiTest extends TestCase
{
    public function test_parses_open_meteo_response_and_converts_wind_to_kmh(): void
    {
        $hour = now('Europe/Paris')->addHours(2)->format('Y-m-d\TH:00');

        Http::fake([
            '*forecast*' => Http::response([
                'latitude'  => 49.0,
                'longitude' => 6.0,
                'hourly'    => [
                    'time'                       => [$hour],
                    'wind_speed_10m'             => [5.0],   // m/s → 18.0 km/h
                    'wind_gusts_10m'             => [8.0],   // m/s → 28.8 km/h
                    'wind_direction_10m'         => [120],
                    'precipitation'              => [0.2],
                    'cloud_cover_low'            => [40],
                    'cloud_cover_mid'            => [10],
                    'cloud_cover_high'           => [5],
                    'temperature_2m'             => [18.5],
                    'relative_humidity_2m'       => [55],
                    'qui_vole_cloud_base'        => [1500],
                    'qui_vole_models_count'      => [10],
                    'qui_vole_models_converging' => [8],
                ],
            ], 200),
        ]);

        $api  = new ConsensusApi();
        $site = new Site(['slug' => 's', 'latitude' => 49.0, 'longitude' => 6.0]);
        $model = new WeatherModel(['code' => ConsensusApi::MODEL_CODE]);

        $parsed = $api->fetchForSiteAndModel($site, $model);

        $this->assertCount(1, $parsed);
        $row = array_values($parsed)[0];

        $this->assertSame(120, $row['wind_direction']);
        $this->assertSame(18.0, $row['wind_speed_avg']);   // 5.0 × 3.6
        $this->assertSame(28.8, $row['wind_speed_max']);   // 8.0 × 3.6
        $this->assertSame(0.2, $row['precipitation']);
        $this->assertSame(1500, $row['cloud_base_m']);     // qui_vole_cloud_base direct
        $this->assertSame(40, $row['cloud_cover_low']);
        $this->assertSame(10, $row['models_count']);
        $this->assertSame(8, $row['models_converging']);
    }

    public function test_gust_falls_back_to_speed_when_absent(): void
    {
        $hour = now('Europe/Paris')->addHours(2)->format('Y-m-d\TH:00');

        Http::fake([
            '*forecast*' => Http::response([
                'hourly' => [
                    'time'               => [$hour],
                    'wind_speed_10m'     => [10.0], // → 36.0 km/h
                    'wind_direction_10m' => [200],
                ],
            ], 200),
        ]);

        $api  = new ConsensusApi();
        $site = new Site(['slug' => 's', 'latitude' => 49.0, 'longitude' => 6.0]);
        $model = new WeatherModel(['code' => ConsensusApi::MODEL_CODE]);

        $row = array_values($api->fetchForSiteAndModel($site, $model))[0];

        $this->assertSame(36.0, $row['wind_speed_avg']);
        $this->assertSame(36.0, $row['wind_speed_max']);   // repli sur la vitesse
        $this->assertNull($row['cloud_base_m']);
        $this->assertNull($row['models_count']);
    }

    public function test_empty_on_http_error(): void
    {
        Http::fake(['*forecast*' => Http::response('boom', 500)]);

        $api  = new ConsensusApi();
        $site = new Site(['slug' => 's', 'latitude' => 49.0, 'longitude' => 6.0]);
        $model = new WeatherModel(['code' => ConsensusApi::MODEL_CODE]);

        $this->assertSame([], $api->fetchForSiteAndModel($site, $model));
    }
}
