<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis\MeteoFranceDps;

/**
 * Configuration statique de l'API DPS Paquets Météo-France.
 *
 * Contrairement à WCS (un appel = 1 variable × 1 timestep), DPS Paquets
 * livre dans UN seul GRIB toutes les variables d'un paquet sur tout
 * l'horizon — un appel HTTP par (modèle, grille, paquet, run).
 *
 * Pour notre usage : 2 paquets surface (SP1 + SP2) par modèle = 2
 * appels HTTP par run, mutualisés entre tous les sites via GribCache.
 */
final class PackageMap
{
    /**
     * @return array{
     *   grid: string,
     *   packages: list<string>,
     *   runHours: int,
     *   delayHours: int,
     *   slotMapping: array<string, list<string>>
     * }|null
     */
    public static function forModel(string $modelCode): ?array
    {
        return match ($modelCode) {
            'meteofrance_arome_france'  => self::aromeFrance(),
            'meteofrance_arpege_europe' => self::arpegeEurope(),
            default                     => null,
        };
    }

    private static function aromeFrance(): array
    {
        return [
            'grid'        => '0.025',
            'packages'    => ['SP1', 'SP2'],
            'runHours'    => 3,
            'delayHours'  => 4,
            'slotMapping' => self::commonSlotMapping(),
        ];
    }

    private static function arpegeEurope(): array
    {
        return [
            'grid'        => '0.1',
            'packages'    => ['SP1', 'SP2'],
            'runHours'    => 6,
            'delayHours'  => 4,
            'slotMapping' => self::commonSlotMapping(),
        ];
    }

    /**
     * Mapping clé de slot → liste de shortNames eccodes candidats.
     *
     * On accepte plusieurs noms par champ parce que le mapping
     * paramID GRIB → shortName n'est pas garanti identique entre
     * AROME et ARPEGE (ni entre versions de la table eccodes). Le
     * GribExtractor prendra le premier shortName qui ressort.
     *
     * Note : DD/FF directs sont préférés à U/V parce que pas besoin
     * de trigo. On garde U/V en fallback. Pour les rafales : FF_RAF
     * en priorité, sinon √(U_RAF²+V_RAF²).
     */
    private static function commonSlotMapping(): array
    {
        return [
            'wind_direction'   => ['10wdir', 'wdir10', 'wdir', 'dd10'],
            'wind_speed_avg'   => ['10si', 'si10', 'ws10', 'ff10'],
            'wind_speed_max'   => ['i10fg', '10fg', 'fg10', 'wsmax', 'ff_raf', 'gust10'],
            '_wind_u'          => ['10u', 'u10'],
            '_wind_v'          => ['10v', 'v10'],
            '_gust_u'          => ['ugust10', 'ufg10', 'u_raf'],
            '_gust_v'          => ['vgust10', 'vfg10', 'v_raf'],
            'temperature'      => ['2t', 't2m'],
            'humidity'         => ['2r', 'r2', '2hr', 'rh2m'],
            'dew_point'        => ['2d', '2td', 'td2m'],
            'cloud_cover_low'  => ['lcc'],
            'cloud_cover_mid'  => ['mcc'],
            'cloud_cover_high' => ['hcc'],
            'cloud_cover_total' => ['tcc'],
            'precipitation_cumul' => ['tp', 'rprate'],
        ];
    }
}
