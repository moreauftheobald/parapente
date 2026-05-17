<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        if (! Schema::hasTable('articles')) {
            return view('home', ['pinned' => null, 'articles' => collect()]);
        }

        $pinned = Article::pinned()->with('author')->first();

        $articles = Article::published()
            ->with('author')
            ->when($pinned, fn ($q) => $q->where('id', '!=', $pinned->id))
            ->get();

        return view('home', ['pinned' => $pinned, 'articles' => $articles]);
    }
}
