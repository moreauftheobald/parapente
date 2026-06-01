@php
    $editing  = $article->exists;
    $action   = $editing ? route('admin.articles.update', $article) : route('admin.articles.store');
    $textareaCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 text-sm focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($editing) @method('PATCH') @endif

    <x-admin.input name="title" label="Titre" :value="old('title', $article->title)" required maxlength="200" />

    <div class="grid sm:grid-cols-2 gap-4">
        <x-admin.input
            name="published_at"
            type="datetime-local"
            label="Date de publication"
            :value="old('published_at', optional($article->published_at)->format('Y-m-d\TH:i'))"
            hint="Détermine l'ordre d'affichage sur l'accueil (plus récent en premier)." />
        <div class="flex flex-col gap-2 sm:items-start sm:justify-end sm:pb-1">
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="hidden" name="is_published" value="0">
                <input type="checkbox" name="is_published" value="1"
                       {{ old('is_published', $article->is_published) ? 'checked' : '' }}
                       class="rounded border-gray-700 bg-gray-950 text-sky-500 focus:ring-sky-500/40">
                Publié (visible sur l'accueil)
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="hidden" name="is_pinned" value="0">
                <input type="checkbox" name="is_pinned" value="1"
                       {{ old('is_pinned', $article->is_pinned) ? 'checked' : '' }}
                       class="rounded border-gray-700 bg-gray-950 text-amber-500 focus:ring-amber-500/40">
                <span>Épingler en tête d'accueil <span class="text-[11px] text-gray-500">(un seul à la fois)</span></span>
            </label>
        </div>
    </div>

    <x-admin.field name="body" label="Contenu">
        <textarea id="article-body" name="body" class="{{ $textareaCls }}">{{ old('body', $article->body) }}</textarea>
    </x-admin.field>

    <div class="flex items-center gap-3 pt-2">
        <x-admin.button type="submit" variant="primary">
            {{ $editing ? 'Enregistrer' : "Créer l'article" }}
        </x-admin.button>
        <a href="{{ route('admin.articles.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
    </div>
</form>

@push('scripts')
    @include('admin._tinymce', ['selector' => '#article-body', 'uploadUrl' => route('admin.articles.upload')])
@endpush
