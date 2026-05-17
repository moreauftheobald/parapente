<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Page de pseudo-wiki / aide en ligne (route /aide/{slug}).
 *
 * Arborescence simple : parent_id auto-référent, pas de profondeur
 * imposée. `body` = HTML issu du WYSIWYG (rendu via {!! !!}).
 */
class WikiPage extends Model
{
    protected $fillable = [
        'parent_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'author_id',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('title');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Charge tout l'arbre publié, eager-loaded sur 1 niveau d'enfants
     * (suffisant pour la navigation latérale). Pour aller plus profond,
     * appeler `loadChildrenTree()` sur la collection retournée.
     *
     * @return Collection<int, WikiPage>
     */
    public static function publishedRoots(): Collection
    {
        return self::published()
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->where('is_published', true)])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }

    /** Fil d'Ariane de la page courante (de la racine jusqu'à $this). */
    public function ancestors(): array
    {
        $chain = [];
        $node = $this->parent;
        while ($node) {
            array_unshift($chain, $node);
            $node = $node->parent;
        }

        return $chain;
    }
}
