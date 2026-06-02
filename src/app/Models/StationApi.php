<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StationApi extends Model
{
    protected $fillable = [
        'code',
        'name',
        'base_url',
        'auth_type',
        'api_key',
        'user_agent',
        'daily_quota',
        'requests_today',
        'requests_counter_date',
        'last_error',
        'last_error_at',
        'last_success_at',
        'active',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'api_key'               => 'encrypted',
            'daily_quota'           => 'integer',
            'requests_today'        => 'integer',
            'requests_counter_date' => 'date',
            'last_error_at'         => 'datetime',
            'last_success_at'       => 'datetime',
            'active'                => 'boolean',
            'config'                => 'array',
        ];
    }

    protected $hidden = ['api_key'];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function incrementRequestsToday(int $count = 1): void
    {
        $today = now()->toDateString();
        if ($this->requests_counter_date?->toDateString() !== $today) {
            $this->requests_counter_date = $today;
            $this->requests_today        = 0;
        }
        $this->requests_today += $count;
        $this->save();
    }

    public function recordSuccess(): void
    {
        $this->last_success_at = now();
        $this->last_error      = null;
        $this->last_error_at   = null;
        $this->save();
    }

    public function recordError(string $message): void
    {
        $this->last_error    = mb_substr($message, 0, 1000);
        $this->last_error_at = now();
        $this->save();
    }

    public function networkColor(): string
    {
        return WeatherStation::NETWORKS[$this->code]['color'] ?? '#6b7280';
    }

    public function networkLabel(): string
    {
        return WeatherStation::NETWORKS[$this->code]['label'] ?? $this->name;
    }

    /**
     * Récupère un access token OAuth2 valide (client_credentials).
     *
     * Le token est caché en Redis avec une marge de sécurité de 60 s
     * avant expiration. Le champ `api_key` contient le header Basic
     * (Base64 de client_id:client_secret).
     *
     * @throws \RuntimeException si le token ne peut pas être obtenu
     */
    public function getOAuth2Token(): string
    {
        if ($this->auth_type !== 'oauth2') {
            throw new \RuntimeException("StationApi [{$this->code}] n'est pas en OAuth2");
        }

        $cacheKey = "station_api.oauth2_token:{$this->code}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $tokenUrl = $this->config['token_url'] ?? ($this->base_url . '/token');
        $basicAuth = $this->api_key;

        if (empty($basicAuth)) {
            throw new \RuntimeException("StationApi [{$this->code}] : credentials OAuth2 non configurés");
        }

        $resp = Http::timeout(15)
            ->withHeaders([
                'Authorization' => 'Basic ' . $basicAuth,
            ])
            ->asForm()
            ->post($tokenUrl, [
                'grant_type' => 'client_credentials',
            ]);

        if (! $resp->ok()) {
            $msg = "OAuth2 token error [{$this->code}]: HTTP {$resp->status()} — {$resp->body()}";
            Log::error($msg);
            throw new \RuntimeException($msg);
        }

        $data = $resp->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        if (! $token) {
            throw new \RuntimeException("OAuth2 [{$this->code}]: pas d'access_token dans la réponse");
        }

        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }

    public function hasOAuth2Credentials(): bool
    {
        return $this->auth_type === 'oauth2' && ! empty($this->api_key);
    }

    public function isOAuth2TokenCached(): bool
    {
        return Cache::has("station_api.oauth2_token:{$this->code}");
    }
}

