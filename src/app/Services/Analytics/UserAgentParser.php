<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Parseur léger de User-Agent — heuristique par substring matching,
 * sans dépendance externe (pas besoin de `jenssegers/agent` pour
 * de simples stats de fréquentation).
 *
 * Ordre d'évaluation important :
 *  1. Bot — détection prioritaire pour ne pas polluer les stats
 *     « humaines ».
 *  2. Tablet — avant Mobile car « iPad » contient « Mobile ».
 *  3. Mobile — explicite (Android Mobile, iPhone, Windows Phone).
 *  4. Desktop — fallback si OS desktop reconnu.
 *  5. Unknown — UA absent ou irreconnaissable.
 */
final class UserAgentParser
{
    /**
     * @return array{
     *   device_type: 'desktop'|'mobile'|'tablet'|'bot'|'unknown',
     *   os: ?string,
     *   browser: ?string,
     * }
     */
    public function parse(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['device_type' => 'unknown', 'os' => null, 'browser' => null];
        }

        return [
            'device_type' => $this->deviceType($ua),
            'os'          => $this->os($ua),
            'browser'     => $this->browser($ua),
        ];
    }

    private function deviceType(string $ua): string
    {
        // Bots et crawlers (liste non-exhaustive mais couvre l'essentiel)
        if (preg_match('/bot|crawler|spider|slurp|mediapartners|facebookexternalhit|whatsapp|telegrambot|preview|monitor|curl|wget|http-client|python-requests|axios|go-http-client/i', $ua)) {
            return 'bot';
        }

        // Tablette : iPad, Android tablette, Windows Tablet
        // (« Android » sans « Mobile » = tablette par convention Google)
        if (stripos($ua, 'iPad') !== false) return 'tablet';
        if (stripos($ua, 'Tablet') !== false) return 'tablet';
        if (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false) {
            return 'tablet';
        }

        // Mobile : iPhone, Android Mobile, Windows Phone
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPod') !== false) return 'mobile';
        if (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') !== false) return 'mobile';
        if (stripos($ua, 'Windows Phone') !== false) return 'mobile';
        if (preg_match('/Mobile|BlackBerry|Opera Mini/i', $ua)) return 'mobile';

        // Desktop : OS de bureau reconnu
        if (preg_match('/Windows NT|Macintosh|Mac OS X|Linux|X11|CrOS/i', $ua)) {
            return 'desktop';
        }

        return 'unknown';
    }

    private function os(string $ua): ?string
    {
        // Ordre : plus spécifique d'abord (iOS avant Mac, Android avant Linux)
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iPod') !== false) return 'iOS';
        if (stripos($ua, 'Android') !== false)       return 'Android';
        if (stripos($ua, 'Windows Phone') !== false) return 'Windows Phone';
        if (stripos($ua, 'Windows NT') !== false)    return 'Windows';
        if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS X') !== false) return 'macOS';
        if (stripos($ua, 'CrOS') !== false)          return 'ChromeOS';
        if (stripos($ua, 'Linux') !== false || stripos($ua, 'X11') !== false) return 'Linux';
        return 'Autre';
    }

    private function browser(string $ua): ?string
    {
        // Ordre crucial : Edge AVANT Chrome (Edge UA contient « Chrome/»),
        // Chrome AVANT Safari (Chrome UA contient « Safari/»), etc.
        if (preg_match('/Edg(e|A|iOS)?\//i', $ua)) return 'Edge';
        if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) return 'Opera';
        if (stripos($ua, 'Vivaldi/') !== false) return 'Vivaldi';
        if (stripos($ua, 'Brave/') !== false) return 'Brave';
        if (stripos($ua, 'SamsungBrowser') !== false) return 'Samsung Internet';
        if (stripos($ua, 'Firefox/') !== false || stripos($ua, 'FxiOS') !== false) return 'Firefox';
        if (stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS') !== false) return 'Chrome';
        if (stripos($ua, 'Safari/') !== false) return 'Safari';
        return 'Autre';
    }
}
