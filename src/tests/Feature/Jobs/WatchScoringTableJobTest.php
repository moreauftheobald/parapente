<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RebuildMapBundleJob;
use App\Jobs\WatchScoringTableJob;
use App\Services\Map\MapBundleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WatchScoringTableJobTest extends TestCase
{
    use RefreshDatabase;

    private function run(): void
    {
        app(WatchScoringTableJob::class)->handle(
            app(\App\Services\Map\SiteDetailCache::class),
            app(\App\Services\Weather\UserScoringService::class),
            app(\Illuminate\Contracts\Cache\Repository::class),
        );
    }

    public function test_first_run_marks_seen_and_invalidates_bundle(): void
    {
        Queue::fake();
        Cache::put(MapBundleBuilder::cacheKey(), ['sites' => []], 60);

        $this->run();

        // Premier passage : pas de valeur « vue » → invalidation + mémorisation.
        $this->assertSame('site_scores_1', Cache::get(WatchScoringTableJob::SEEN_KEY));
        $this->assertNull(Cache::get(MapBundleBuilder::cacheKey()));
        Queue::assertPushed(RebuildMapBundleJob::class);
    }

    public function test_no_flip_is_a_noop(): void
    {
        // Le buffer actif est déjà mémorisé → rien à faire.
        Cache::forever(WatchScoringTableJob::SEEN_KEY, 'site_scores_1');
        Cache::put(MapBundleBuilder::cacheKey(), ['sites' => []], 60);

        Queue::fake();
        $this->run();

        $this->assertNotNull(Cache::get(MapBundleBuilder::cacheKey()));
        Queue::assertNotPushed(RebuildMapBundleJob::class);
    }

    public function test_flip_is_detected(): void
    {
        Cache::forever(WatchScoringTableJob::SEEN_KEY, 'site_scores_1');

        // Le sidecar bascule le pointeur vers le buffer 2.
        DB::table('settings')->where('key', 'scoring_table')->update(['value' => '2']);

        Queue::fake();
        Cache::put(MapBundleBuilder::cacheKey(), ['sites' => []], 60);

        $this->run();

        $this->assertSame('site_scores_2', Cache::get(WatchScoringTableJob::SEEN_KEY));
        $this->assertNull(Cache::get(MapBundleBuilder::cacheKey()));
        Queue::assertPushed(RebuildMapBundleJob::class);
    }
}
