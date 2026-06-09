<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\WeatherApi;
use InvalidArgumentException;

/**
 * Résout le code d'une `weather_apis` vers son implémentation PHP.
 *
 * Singleton applicatif, partagé. Mappe code => classe d'implémentation
 * et instancie via le container Laravel.
 */
class WeatherApiRegistry
{
    /**
     * Codes WeatherApi connus → implémentation PHP.
     *
     * `openmeteo`        : instance Open-Meteo self-hosted (réseau docker
     *                      `meteo-net`).
     * `openmeteo_public` : API publique api.open-meteo.com (utilisée pour
     *                      les modèles que notre instance self-hosted ne
     *                      sert pas correctement — typiquement UKMO Global
     *                      qui n'expose pas le vent à 10 m).
     *
     * Le consensus multi-modèles n'est plus fetché par Laravel : il est
     * calculé ET scoré par le sidecar `consensus-grid-v2`, qui écrit
     * directement les statuts dans `site_scores_{1,2}`.
     *
     * @var array<string, class-string<WeatherApiInterface>>
     */
    private const REGISTRY = [
        'openmeteo'        => OpenMeteoApi::class,
        'openmeteo_public' => OpenMeteoApi::class,
    ];

    /**
     * Instancie l'implémentation associée à une WeatherApi et y injecte
     * la configuration runtime.
     */
    public function for(WeatherApi $config): WeatherApiInterface
    {
        $class = self::REGISTRY[$config->code] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException("WeatherApi inconnue: {$config->code}");
        }
        /** @var WeatherApiInterface $instance */
        $instance = app($class);
        $instance->setConfig($config);
        return $instance;
    }

    /**
     * Vérifie qu'une API peut servir un modèle donné.
     */
    public function supports(WeatherApi $config, string $modelCode): bool
    {
        $class = self::REGISTRY[$config->code] ?? null;
        if ($class === null) {
            return false;
        }
        /** @var WeatherApiInterface $instance */
        $instance = app($class);
        return in_array($modelCode, $instance->supportedModelCodes(), true);
    }

    /**
     * Liste toutes les APIs connues du registry (codes).
     *
     * @return array<int, string>
     */
    public function knownCodes(): array
    {
        return array_keys(self::REGISTRY);
    }
}
