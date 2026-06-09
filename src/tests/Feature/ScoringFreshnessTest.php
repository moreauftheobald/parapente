<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Map\ScoringFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ScoringFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): ScoringFreshness
    {
        return app(ScoringFreshness::class);
    }

    public function test_fresh_right_after_a_run(): void
    {
        $this->svc()->recordRun();

        $status = $this->svc()->evaluate();

        $this->assertFalse($status['stale']);
        $this->assertSame(0, $status['age_minutes']);
        $this->assertNull($status['stale_since']);
    }

    public function test_stale_past_threshold(): void
    {
        // Dernier run il y a 80 min, seuil par défaut 75.
        Cache::forever(ScoringFreshness::LAST_RUN_KEY, now()->subMinutes(80)->getTimestamp());

        $status = $this->svc()->evaluate();

        $this->assertTrue($status['stale']);
        $this->assertGreaterThanOrEqual(75, $status['age_minutes']);
        $this->assertNotNull($status['stale_since']);

        // status() (lecture cheap) reflète le même état.
        $this->assertTrue($this->svc()->status()['stale']);
    }

    public function test_record_run_recovers_freshness(): void
    {
        Cache::forever(ScoringFreshness::LAST_RUN_KEY, now()->subMinutes(200)->getTimestamp());
        $this->assertTrue($this->svc()->evaluate()['stale']);

        // Un nouveau run (flip) recale l'horloge.
        $this->svc()->recordRun();
        $this->assertFalse($this->svc()->evaluate()['stale']);
    }

    public function test_bootstrap_does_not_alert_on_first_tick(): void
    {
        // Aucune valeur LAST_RUN → 1er tick amorce sans alerter.
        $this->assertFalse(Cache::has(ScoringFreshness::LAST_RUN_KEY));

        $status = $this->svc()->evaluate();

        $this->assertFalse($status['stale']);
        $this->assertTrue(Cache::has(ScoringFreshness::LAST_RUN_KEY));
    }
}
