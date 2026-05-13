<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paramètre global stocké en base (table `settings`).
 *
 * À ne pas manipuler directement depuis le code applicatif : passer par le
 * service `App\Services\Settings` (cache Redis + valeurs par défaut).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'label', 'description'];

    protected $casts = [
        'value' => 'array',
    ];
}
