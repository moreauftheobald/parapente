<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site masqué par un utilisateur sur la carte de volabilité.
 *
 * Une ligne = « cet utilisateur ne veut pas voir ce site ». Modèle
 * d'exclusion pure : pas de ligne ⇒ site affiché (défaut). Le filtrage
 * est appliqué côté carte uniquement quand l'utilisateur est connecté.
 * Cf. FF_site_blacklist.md.
 */
class UserHiddenSite extends Model
{
    protected $fillable = [
        'user_id',
        'site_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeForUser(Builder $query, int|User $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }
}
