<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Module = entrée du menu principal.
 *
 * Visibilité dans la barre de menu : voir isVisibleFor().
 */
class Module extends Model
{
    protected $fillable = [
        'key',
        'label',
        'icon',
        'route_name',
        'is_active',
        'access_level',
        'requires_registration',
        'sort_order',
    ];

    protected $casts = [
        'is_active'             => 'boolean',
        'requires_registration' => 'boolean',
        'sort_order'            => 'integer',
    ];

    /** Niveaux de droit possibles. */
    public const ACCESS_LEVELS = ['guest', 'user', 'admin'];

    /**
     * Le module doit-il apparaître dans la barre de menu pour cet utilisateur ?
     */
    public function isVisibleFor(?User $user): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->requires_registration && $user === null) {
            return false;
        }

        return match ($this->access_level) {
            'admin' => $user !== null && $user->isAdmin(),
            'user'  => $user !== null,
            default => true, // guest
        };
    }
}
