<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WikiPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * BackOffice — pages de l'aide en ligne (/aide).
 *
 * Même éditeur TinyMCE que les articles, même endpoint d'upload d'image
 * (réutilisé via la route admin.articles.upload côté formulaire — on
 * pourrait factoriser plus tard si besoin).
 */
class WikiPageController extends Controller
{
    public function index(): View
    {
        return view('admin.wiki.index', [
            'pages' => WikiPage::with(['author', 'parent'])
                ->orderBy('parent_id')
                ->orderBy('sort_order')
                ->orderBy('title')
                ->paginate(50),
        ]);
    }

    public function create(): View
    {
        return view('admin.wiki.create', [
            'page'    => new WikiPage(['is_published' => true, 'sort_order' => 100]),
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['author_id'] = $request->user()?->id;
        $data['slug']      = $this->uniqueSlug($data['slug'] ?: $data['title']);

        WikiPage::create($data);

        return redirect()->route('admin.wiki.index')->with('status', 'Page créée.');
    }

    public function edit(WikiPage $wikiPage): View
    {
        return view('admin.wiki.edit', [
            'page'    => $wikiPage,
            'parents' => $this->parentOptions($wikiPage->id),
        ]);
    }

    public function update(Request $request, WikiPage $wikiPage): RedirectResponse
    {
        $data = $this->validateData($request, $wikiPage);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?: $data['title'], $wikiPage->id);

        if ((int) ($data['parent_id'] ?? 0) === $wikiPage->id) {
            $data['parent_id'] = null;
        }

        $wikiPage->update($data);

        return redirect()->route('admin.wiki.index')->with('status', 'Page mise à jour.');
    }

    public function destroy(WikiPage $wikiPage): RedirectResponse
    {
        $wikiPage->delete();

        return redirect()->route('admin.wiki.index')->with('status', 'Page supprimée.');
    }

    public function toggle(WikiPage $wikiPage): RedirectResponse
    {
        $wikiPage->update(['is_published' => ! $wikiPage->is_published]);

        return back()->with('status', $wikiPage->is_published ? 'Page publiée.' : 'Page dépubliée.');
    }

    /** Endpoint d'upload d'image pour TinyMCE (mêmes règles que les articles). */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:5120'],
        ]);

        $file = $request->file('file');
        $name = Str::random(24) . '.' . strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('wiki', $name, 'public');

        return response()->json(['location' => Storage::disk('public')->url($path)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, ?WikiPage $existing = null): array
    {
        $data = $request->validate([
            'parent_id'    => ['nullable', 'integer', 'exists:wiki_pages,id'],
            'title'        => ['required', 'string', 'max:200'],
            'slug'         => ['nullable', 'string', 'max:220', 'regex:/^[a-z0-9\-]+$/'],
            'excerpt'      => ['nullable', 'string', 'max:300'],
            'body'         => ['required', 'string'],
            'sort_order'   => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $data['is_published'] = $request->boolean('is_published');
        $data['sort_order']   = $data['sort_order'] ?? 100;

        return $data;
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source);
        $slug = $base !== '' ? $base : 'page';
        $i = 2;
        while (WikiPage::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /**
     * Liste des parents possibles (toutes les pages, sauf $excludeId
     * et ses descendants — éviter une boucle). Retourne [id => 'Titre'].
     *
     * @return array<int, string>
     */
    private function parentOptions(?int $excludeId = null): array
    {
        $pages = WikiPage::orderBy('parent_id')->orderBy('sort_order')->orderBy('title')->get();

        $forbidden = [];
        if ($excludeId) {
            $forbidden[$excludeId] = true;
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($pages as $p) {
                    if (isset($forbidden[$p->parent_id]) && ! isset($forbidden[$p->id])) {
                        $forbidden[$p->id] = true;
                        $changed = true;
                    }
                }
            }
        }

        $options = [];
        foreach ($pages as $p) {
            if (isset($forbidden[$p->id])) {
                continue;
            }
            $options[$p->id] = $p->title;
        }

        return $options;
    }
}
