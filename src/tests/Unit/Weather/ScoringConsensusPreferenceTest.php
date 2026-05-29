<?php

declare(strict_types=1);

namespace Tests\Unit\Weather;

use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteCondition;
use App\Models\SiteScore;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\ConsensusApi;
use App\Services\Weather\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie que le ScoringService :
 *  - utilise EN PRIORITÉ la prévision `qui_vole_consensus` fraîche
 *    (valeurs reprises telles quelles, métadonnées de l'API) ;
 *  - retombe sur la voting logic interne (autres modèles) quand le
 *    consensus est périmé/absent.
 */
class ScoringConsensusPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        $site = Site::create([
            'name'       => 'Test',
            'slug'       => 'test-' . uniqid(),
            'latitude'   => 49.0,
            'longitude'  => 6.0,
            'altitude_m' => 500,
            'region'     => 'test',
            'active'     => true,
        ]);

        SiteCondition::create([
            'site_id'         => $site->id,
            'wind_dir_min'    => 90,
            'wind_dir_max'    => 180,
            'wind_speed_min'  => 5,
            'wind_speed_max'  => 25,
            'wind_speed_ideal' => 15,
        ]);

        return $site;
    }

    private function consensusModel(): WeatherModel
    {
        // Déjà inséré par la migration de données du consensus —
        // on récupère la ligne existante (firstOrCreate idempotent).
        return WeatherModel::firstOrCreate(
            ['code' => ConsensusApi::MODEL_CODE],
            [
                'name' => 'Consensus', 'provider' => 'Qui-Vole',
                'resolution_km' => 2.5, 'max_horizon_h' => 120,
                'weight_short' => 1.0, 'weight_medium' => 1.0,
                'refresh_frequency_minutes' => 60, 'active' => true,
            ]
        );
    }

    private function normalModel(): WeatherModel
    {
        return WeatherModel::firstOrCreate(
            ['code' => 'meteofrance_arpege_europe'],
            [
                'name' => 'ARPEGE', 'provider' => 'Météo-France',
                'resolution_km' => 11.0, 'max_horizon_h' => 96,
                'weight_short' => 1.0, 'weight_medium' => 1.0,
                'refresh_frequency_minutes' => 180, 'active' => true,
            ]
        );
    }

    private function forecast(Site $site, WeatherModel $model, \DateTimeInterface $at, array $values, \DateTimeInterface $fetchedAt): void
    {
        Forecast::create(array_merge([
            'site_id' => $site->id, 'weather_model_id' => $model->id,
            'forecast_at' => $at, 'fetched_at' => $fetchedAt,
            'wind_direction' => 120, 'wind_speed_avg' => 15.0, 'wind_speed_min' => 15.0,
            'wind_speed_max' => 20.0, 'precipitation' => 0.0,
            'cloud_cover_low' => 0, 'cloud_cover_mid' => 0, 'cloud_cover_high' => 0,
            'temperature' => 18.0, 'humidity' => 50,
        ], $values));
    }

    public function test_uses_consensus_forecast_when_fresh(): void
    {
        $site      = $this->site();
        $consensus = $this->consensusModel();
        $normal    = $this->normalModel();
        $at        = now()->addHour()->startOfHour();

        // Consensus frais : valeurs favorables + métadonnées API.
        $this->forecast($site, $consensus, $at, [
            'wind_direction' => 120, 'wind_speed_avg' => 15.0, 'wind_speed_max' => 20.0,
            'models_count' => 8, 'models_converging' => 7,
        ], now());

        // Modèle "normal" très divergent : s'il était utilisé (voting),
        // le statut serait rouge (direction hors axe + rafale > seuil rouge).
        $this->forecast($site, $normal, $at, [
            'wind_direction' => 300, 'wind_speed_avg' => 40.0, 'wind_speed_max' => 50.0,
        ], now());

        app(ScoringService::class)->computeScoresForSite($site);

        $score = SiteScore::where('site_id', $site->id)->where('forecast_at', $at)->first();
        $this->assertNotNull($score);
        $this->assertSame('green', $score->status);
        $this->assertSame(120, (int) $score->wind_dir_consensus);
        $this->assertSame(8, (int) $score->models_count);
        $this->assertSame(7, (int) $score->models_converging);
        // confidence dérivée de l'API : 7/8 = 88 %
        $this->assertSame(88, (int) $score->confidence_pct);
    }

    public function test_falls_back_to_voting_when_consensus_stale(): void
    {
        $site      = $this->site();
        $consensus = $this->consensusModel();
        $normal    = $this->normalModel();
        $at        = now()->addHour()->startOfHour();

        // Consensus PÉRIMÉ (fetched_at il y a 5 h) → ignoré.
        $this->forecast($site, $consensus, $at, [
            'wind_direction' => 120, 'wind_speed_avg' => 15.0, 'wind_speed_max' => 20.0,
            'models_count' => 8, 'models_converging' => 7,
        ], now()->subHours(5));

        // Le modèle normal (favorable) doit alors piloter le consensus interne.
        $this->forecast($site, $normal, $at, [
            'wind_direction' => 140, 'wind_speed_avg' => 12.0, 'wind_speed_max' => 18.0,
        ], now());

        app(ScoringService::class)->computeScoresForSite($site);

        $score = SiteScore::where('site_id', $site->id)->where('forecast_at', $at)->first();
        $this->assertNotNull($score);
        // Consensus interne calculé sur le seul modèle normal → 140°.
        $this->assertSame(140, (int) $score->wind_dir_consensus);
    }
}
