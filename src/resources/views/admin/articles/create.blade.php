@extends('layouts.admin')
@section('title', 'Nouvel article')

@section('content')
<div class="max-w-4xl">
    <div class="mb-5">
        <a href="{{ route('admin.articles.index') }}" class="text-xs text-gray-500 hover:text-gray-300 transition">
            <i class="fa-solid fa-arrow-left"></i> Articles
        </a>
        <h1 class="text-2xl font-semibold text-white mt-1">Nouvel article</h1>
    </div>

    @include('admin.articles._form')
</div>
@endsection
