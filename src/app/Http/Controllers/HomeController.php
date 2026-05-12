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
        $articles = Schema::hasTable('articles')
            ? Article::published()->with('author')->get()
            : collect();

        return view('home', ['articles' => $articles]);
    }
}
