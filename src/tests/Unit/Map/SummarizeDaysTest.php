<?php

declare(strict_types=1);

namespace Tests\Unit\Map;

use App\Services\Map\MapBundleBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Vérifie l'agrégat journalier pur (best_status + green_slots) et son
 * recalcul en excluant des sites masqués. Pas de DB : on passe des
 * payloads sites synthétiques. Cf. FF_site_blacklist.md.
 */
class SummarizeDaysTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function payload(): array
    {
        return [
            [
                'id'              => 1,
                'days'           => ['08/06' => ['status' => 'green', 'viability' => 60, 'green_hours' => 3]],
                'green_hours_set' => ['08/06' => [11, 12, 13]],
            ],
            [
                'id'              => 2,
                'days'           => ['08/06' => ['status' => 'orange', 'viability' => 20, 'green_hours' => 1]],
                'green_hours_set' => ['08/06' => [14]],
            ],
        ];
    }

    public function test_aggregates_best_status_and_union_of_green_hours(): void
    {
        $out = MapBundleBuilder::summarizeDays($this->payload());

        $this->assertCount(1, $out);
        $this->assertSame('08/06', $out[0]['raw']);
        $this->assertSame('green', $out[0]['best_status']); // green > orange
        $this->assertSame(4, $out[0]['green_slots']);       // {11,12,13} ∪ {14}
    }

    public function test_excluding_a_site_drops_its_green_hours_and_status(): void
    {
        // On exclut le site 1 (le seul green) → best devient orange,
        // green_slots ne garde que l'heure du site 2.
        $out = MapBundleBuilder::summarizeDays($this->payload(), [1]);

        $this->assertCount(1, $out);
        $this->assertSame('orange', $out[0]['best_status']);
        $this->assertSame(1, $out[0]['green_slots']); // {14}
    }

    public function test_excluding_all_sites_yields_empty(): void
    {
        $out = MapBundleBuilder::summarizeDays($this->payload(), [1, 2]);
        $this->assertSame([], $out);
    }
}
