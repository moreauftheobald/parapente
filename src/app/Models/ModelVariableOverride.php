<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelVariableOverride extends Model
{
    protected $fillable = [
        'model_code',
        'variable',
        'enabled',
        'notes_admin',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
