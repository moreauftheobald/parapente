@extends('layouts.admin')
@section('title', 'Éditer — ' . $page->title)

@section('content')
<div>
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <a href="{{ route('admin.wiki.index') }}" class="text-sm text-gray-400 hover:text-white">
                <i class="fa-solid fa-arrow-left mr-1"></i> Retour aux pages
            </a>
            <h1 class="text-2xl font-semibold text-white mt-2">Éditer la page</h1>
        </div>
        <a href="{{ route('wiki.show', $page->slug) }}" target="_blank"
           class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs font-medium rounded-md transition shrink-0">
            <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i> Voir sur le site
        </a>
    </div>
    @include('admin.wiki._form')
</div>
@endsection
