<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'visited_at',
        'visitor_hash',
        'user_id',
        'path',
        'method',
        'status_code',
        'device_type',
        'os',
        'browser',
        'referer_host',
    ];

    protected $casts = [
        'visited_at'  => 'datetime',
        'status_code' => 'integer',
        'user_id'     => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
