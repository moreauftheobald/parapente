<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article / entrée de changelog affiché sur la page d'accueil.
 *
 * `body` = HTML issu du WYSIWYG. Le rendu se fait via {!! !!} : le contenu
 * est saisi par des administrateurs uniquement (pas d'entrée publique).
 */
class Article extends Model
{
    protected $fillable = [
        'title',
        'body',
        'author_id',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Articles visibles publiquement, du plus récent au plus ancien. */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }
}
