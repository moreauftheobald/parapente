<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * BackOffice — articles / changelog affichés sur la page d'accueil.
 *
 * Le corps de l'article est du HTML produit par TinyMCE ; les images sont
 * uploadées via uploadImage() sur le disque "public" (penser à
 * `php artisan storage:link`).
 */
class ArticleController extends Controller
{
    public function index(): View
    {
        return view('admin.articles.index', [
            'articles' => Article::with('author')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->paginate(30),
        ]);
    }

    public function create(): View
    {
        return view('admin.articles.create', [
            'article' => new Article(['is_published' => true, 'published_at' => now()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['author_id'] = $request->user()?->id;

        Article::create($data);

        return redirect()->route('admin.articles.index')->with('status', 'Article créé.');
    }

    public function edit(Article $article): View
    {
        return view('admin.articles.edit', ['article' => $article]);
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $article->update($this->validateData($request));

        return redirect()->route('admin.articles.index')->with('status', 'Article mis à jour.');
    }

    public function destroy(Article $article): RedirectResponse
    {
        $article->delete();

        return redirect()->route('admin.articles.index')->with('status', 'Article supprimé.');
    }

    public function toggle(Article $article): RedirectResponse
    {
        $article->update(['is_published' => ! $article->is_published]);

        return back()->with('status', $article->is_published ? 'Article publié.' : 'Article dépublié.');
    }

    /**
     * Endpoint d'upload d'image pour TinyMCE. Réponse attendue : { "location": "<url>" }.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp,svg', 'max:5120'],
        ]);

        $file = $request->file('file');
        $name = Str::random(24) . '.' . strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('articles', $name, 'public');

        return response()->json(['location' => Storage::disk('public')->url($path)]);
    }

    /**
     * @return array{title:string, body:string, is_published:bool, published_at:string|null}
     */
    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'title'        => ['required', 'string', 'max:200'],
            'body'         => ['required', 'string'],
            'published_at' => ['nullable', 'date'],
        ]);

        $data['is_published'] = $request->boolean('is_published');
        $data['published_at'] = $data['published_at'] ?: now();

        return $data;
    }
}
