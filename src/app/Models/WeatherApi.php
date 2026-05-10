<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeatherApi extends Model
{
    protected $fillable = [
        'code',
        'name',
        'base_url',
        'auth_type',
        'api_key',
        'user_agent',
        'oauth_client_id',
        'oauth_client_secret',
        'oauth_token',
        'oauth_expires_at',
        'daily_quota',
        'requests_today',
        'requests_counter_date',
        'last_error',
        'last_error_at',
        'last_success_at',
        'active',
    ];

    protected $casts = [
        'api_key'               => 'encrypted',
        'oauth_client_id'       => 'encrypted',
        'oauth_client_secret'   => 'encrypted',
        'oauth_token'           => 'encrypted',
        'oauth_expires_at'      => 'datetime',
        'daily_quota'           => 'integer',
        'requests_today'        => 'integer',
        'requests_counter_date' => 'date',
        'last_error_at'         => 'datetime',
        'last_success_at'       => 'datetime',
        'active'                => 'boolean',
    ];

    protected $hidden = [
        'api_key',
        'oauth_client_secret',
        'oauth_token',
    ];

    public function weatherModels(): HasMany
    {
        return $this->hasMany(WeatherModel::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Incrémente le compteur de requêtes du jour. Reset auto au changement de date.
     */
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
}
