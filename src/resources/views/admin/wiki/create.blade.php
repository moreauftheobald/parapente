@extends('layouts.admin')
@section('title', 'Nouvelle page d\'aide')

@section('content')
<div class="max-w-5xl">
    <div class="mb-6">
        <a href="{{ route('admin.wiki.index') }}" class="text-sm text-gray-400 hover:text-white">
            <i class="fa-solid fa-arrow-left mr-1"></i> Retour aux pages
        </a>
        <h1 class="text-2xl font-semibold text-white mt-2">Nouvelle page d'aide</h1>
    </div>
    @include('admin.wiki._form')
</div>
@endsection
