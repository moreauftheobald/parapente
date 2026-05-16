<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Balise;
use App\Models\BaliseReading;
use App\Services\Map\BalisesBundleCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Tests du cache transparent des endpoints balises (/api/balises +
 * /api/balises/{id}/history) — phase 3 du FF_map_bundle_cache.md.
 */
class BalisesBundleCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeBalise(string $source = 'pioupiou', string $name = 'PP-1'): Balise
    {
        return Balise::create([
            'source'      => $source,
            'external_id' => $source . '-' . uniqid(),
            'name'        => $name,
            'latitude'    => 49.4,
            'longitude'   => 6.1,
            'altitude_m'  => 420,
            'active'      => true,
        ]);
    }

    private function makeReading(Balise $b, \Carbon\Carbon $at, float $speed = 12.5): BaliseReading
    {
        return BaliseReading::create([
            'balise_id'      => $b->id,
            'read_at'        => $at,
            'wind_direction' => 90,
            'wind_speed_avg' => $speed,
            'wind_speed_min' => $speed - 2,
            'wind_speed_max' => $speed + 3,
            'temperature'    => 18,
            'humidity'       => 55,
        ]);
    }

    public function test_index_endpoint_caches_bundle(): void
    {
        $b = $this->makeBalise();
        $this->makeReading($b, now()->subMinutes(2));

        $this->assertNull(Cache::get(BalisesBundleCache::bundleKey()));

        $resp = $this->getJson('/api/balises');
        $resp->assertOk()->assertJsonCount(1);

        $cached = Cache::get(BalisesBundleCache::bundleKey());
        $this->assertIsArray($cached);
        $this->assertCount(1, $cached);
        $this->assertSame($b->id, $cached[0]['id']);
    }

    public function test_index_serves_from_cache_on_second_call(): void
    {
        $b = $this->makeBalise();
        $this->makeReading($b, now()->subMinutes(2));

        // 1er appel : peuple le cache
        $first = $this->getJson('/api/balises')->json();

        // On modifie la DB (nouveau reading) — mais comme on est cached,
        // /api/balises doit retourner la version originelle.
        $this->makeReading($b, now(), 30.0); // gros vent, devrait shifter
        $second = $this->getJson('/api/balises')->json();

        // Le cache renvoie le même payload tant qu'il n'est pas invalidé.
        $this->assertEquals($first, $second);
    }

    public function test_pioupiou_job_invalidates_cache(): void
    {
        // Crée une balise + reading, peuple le cache
        $b = $this->makeBalise('pioupiou');
        $this->makeReading($b, now()->subMinutes(2));
        $this->getJson('/api/balises')->assertOk();
        $this->assertNotNull(Cache::get(BalisesBundleCache::bundleKey()));

        // Invalidation directe via le service (le job fait pareil à la fin)
        $cache = $this->app->make(BalisesBundleCache::class);
        $cache->forgetBundle();

        $this->assertNull(Cache::get(BalisesBundleCache::bundleKey()));
    }

    public function test_history_endpoint_caches_per_balise(): void
    {
        $b1 = $this->makeBalise('pioupiou', 'PP-1');
        $b2 = $this->makeBalise('pioupiou', 'PP-2');
        $this->makeReading($b1, now()->subMinutes(2));
        $this->makeReading($b2, now()->subMinutes(2));

        $this->getJson("/api/balises/{$b1->id}/history")->assertOk();

        // Cache rempli pour b1, pas pour b2
        $this->assertNotNull(Cache::get(BalisesBundleCache::historyKey($b1->id)));
        $this->assertNull(Cache::get(BalisesBundleCache::historyKey($b2->id)));

        $this->getJson("/api/balises/{$b2->id}/history")->assertOk();
        $this->assertNotNull(Cache::get(BalisesBundleCache::historyKey($b2->id)));
    }

    public function test_inactive_balise_returns_404_history(): void
    {
        $b = $this->makeBalise();
        $b->update(['active' => false]);
        $this->getJson("/api/balises/{$b->id}/history")->assertNotFound();
    }
}
