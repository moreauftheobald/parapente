<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityProfile extends Model
{
    protected $fillable = [
        'slug',
        'label',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'active'     => 'boolean',
    ];

    public function axes(): HasMany
    {
        return $this->hasMany(QualityAxis::class, 'profile_id');
    }
}
