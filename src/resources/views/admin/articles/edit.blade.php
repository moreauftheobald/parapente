@extends('layouts.admin')
@section('title', 'Édition · ' . $article->title)

@section('content')
<div>
    <div class="mb-5 flex items-baseline justify-between">
        <div>
            <a href="{{ route('admin.articles.index') }}" class="text-xs text-gray-500 hover:text-gray-300 transition">
                <i class="fa-solid fa-arrow-left"></i> Articles
            </a>
            <h1 class="text-2xl font-semibold text-white mt-1">Modifier l'article</h1>
        </div>
        <form method="POST" action="{{ route('admin.articles.destroy', $article) }}"
              onsubmit="return confirm('Supprimer cet article ?');">
            @csrf @method('DELETE')
            <button type="submit" class="text-xs text-gray-500 hover:text-red-400 transition">
                <i class="fa-solid fa-trash"></i> Supprimer
            </button>
        </form>
    </div>

    @include('admin.articles._form')
</div>
@endsection
