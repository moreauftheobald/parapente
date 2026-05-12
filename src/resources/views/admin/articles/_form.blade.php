@php
    $editing  = $article->exists;
    $action   = $editing ? route('admin.articles.update', $article) : route('admin.articles.store');
    $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($editing) @method('PATCH') @endif

    <div>
        <label for="title" class="block text-xs font-medium text-gray-400 mb-1">Titre</label>
        <input id="title" name="title" type="text" required maxlength="200"
               value="{{ old('title', $article->title) }}" class="{{ $inputCls }}">
        @error('title') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label for="published_at" class="block text-xs font-medium text-gray-400 mb-1">Date de publication</label>
            <input id="published_at" name="published_at" type="datetime-local"
                   value="{{ old('published_at', optional($article->published_at)->format('Y-m-d\TH:i')) }}"
                   class="{{ $inputCls }}">
            <p class="text-[11px] text-gray-600 mt-1">Détermine l'ordre d'affichage sur l'accueil (plus récent en premier).</p>
            @error('published_at') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex sm:items-end">
            <label class="flex items-center gap-2 text-sm text-gray-300 sm:pb-2">
                <input type="hidden" name="is_published" value="0">
                <input type="checkbox" name="is_published" value="1"
                       {{ old('is_published', $article->is_published) ? 'checked' : '' }}
                       class="rounded border-gray-700 bg-gray-950 text-sky-500 focus:ring-sky-500/40">
                Publié (visible sur l'accueil)
            </label>
        </div>
    </div>

    <div>
        <label for="article-body" class="block text-xs font-medium text-gray-400 mb-1">Contenu</label>
        <textarea id="article-body" name="body">{{ old('body', $article->body) }}</textarea>
        @error('body') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium rounded-md transition">
            {{ $editing ? 'Enregistrer' : "Créer l'article" }}
        </button>
        <a href="{{ route('admin.articles.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
    </div>
</form>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    tinymce.init({
        selector: '#article-body',
        license_key: 'gpl',
        height: 500,
        menubar: false,
        skin: 'oxide-dark',
        content_css: 'dark',
        plugins: 'lists link image table code autolink',
        toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link image table | alignleft aligncenter alignright | code | removeformat',
        branding: false,
        promotion: false,
        relative_urls: false,
        convert_urls: false,
        image_caption: true,
        images_upload_handler: function (blobInfo) {
            return new Promise(function (resolve, reject) {
                var fd = new FormData();
                fd.append('file', blobInfo.blob(), blobInfo.filename());
                fetch(@json(route('admin.articles.upload')), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: fd
                })
                .then(function (r) { return r.ok ? r.json() : Promise.reject('HTTP ' + r.status); })
                .then(function (d) { (d && d.location) ? resolve(d.location) : reject('Réponse invalide du serveur'); })
                .catch(function (e) { reject("Échec de l'upload : " + e); });
            });
        }
    });
</script>
@endpush
