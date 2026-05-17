<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WikiPage;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Aide en ligne / pseudo-wiki publique — routes /aide et /aide/{slug}.
 *
 * Le contenu est purement informatif (pas de saisie publique). Le panneau
 * gauche du shell expose l'arborescence des pages publiées.
 */
class WikiController extends Controller
{
    public function index(): View
    {
        $roots = Schema::hasTable('wiki_pages') ? WikiPage::publishedRoots() : collect();

        return view('wiki.index', [
            'roots' => $roots,
            'page'  => null,
        ]);
    }

    public function show(string $slug): View
    {
        $page = WikiPage::published()
            ->with(['author', 'children' => fn ($q) => $q->where('is_published', true)])
            ->where('slug', $slug)
            ->firstOrFail();

        return view('wiki.show', [
            'roots' => WikiPage::publishedRoots(),
            'page'  => $page,
        ]);
    }
}
