<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Weather\Grib\GribExtractor;
use Tests\TestCase;

/**
 * Format de sortie de `grib_get -l <lat>,<lon>,1 -p <keys>` :
 *   <keys...> <value>
 * Une ligne par message GRIB. Le 1 final demande UNE valeur (au point
 * le plus proche, sans le quad de voisins par défaut).
 */
class GribExtractorTest extends TestCase
{
    public function test_parses_single_var_output(): void
    {
        $extractor = new GribExtractor();
        $output = <<<TXT
20260510 1200 12.5
20260510 1300 14.2
TXT;

        $series = $extractor->parsePointSeries($output);

        $this->assertSame([
            '2026-05-10 12:00:00' => 12.5,
            '2026-05-10 13:00:00' => 14.2,
        ], $series);
    }

    public function test_skips_missing_values(): void
    {
        $extractor = new GribExtractor();
        $output = <<<TXT
20260510 1200 9999
20260510 1300 14.2
TXT;

        $series = $extractor->parsePointSeries($output);

        $this->assertSame(['2026-05-10 13:00:00' => 14.2], $series);
    }

    public function test_pads_short_validity_time(): void
    {
        $extractor = new GribExtractor();
        $output = <<<TXT
20260510 0 12.5
20260510 600 13.0
TXT;

        $series = $extractor->parsePointSeries($output);

        $this->assertSame([
            '2026-05-10 00:00:00' => 12.5,
            '2026-05-10 06:00:00' => 13.0,
        ], $series);
    }

    public function test_parses_multi_var_output(): void
    {
        $extractor = new GribExtractor();
        // Cas réel observé sur AROME SP1 : 4 colonnes.
        $output = <<<TXT
10wdir 20260510 600 340.8
10wdir 20260510 700 357.6
10si 20260510 600 1.94
10si 20260510 700 1.918
i10fg 20260510 700 4.763
TXT;

        $series = $extractor->parseMultiVarPointSeries($output);

        $this->assertEqualsWithDelta(340.8, $series['10wdir']['2026-05-10 06:00:00'], 0.01);
        $this->assertEqualsWithDelta(357.6, $series['10wdir']['2026-05-10 07:00:00'], 0.01);
        $this->assertEqualsWithDelta(1.94,  $series['10si']['2026-05-10 06:00:00'], 0.01);
        $this->assertEqualsWithDelta(1.918, $series['10si']['2026-05-10 07:00:00'], 0.01);
        $this->assertEqualsWithDelta(4.763, $series['i10fg']['2026-05-10 07:00:00'], 0.01);
    }
}
