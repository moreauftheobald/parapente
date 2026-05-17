@php
    $editing  = $page->exists;
    $action   = $editing ? route('admin.wiki.update', $page) : route('admin.wiki.store');
    $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($editing) @method('PATCH') @endif

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label for="title" class="block text-xs font-medium text-gray-400 mb-1">Titre</label>
            <input id="title" name="title" type="text" required maxlength="200"
                   value="{{ old('title', $page->title) }}" class="{{ $inputCls }}">
            @error('title') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="slug" class="block text-xs font-medium text-gray-400 mb-1">
                Slug <span class="text-gray-600">(facultatif — généré depuis le titre)</span>
            </label>
            <input id="slug" name="slug" type="text" maxlength="220" pattern="[a-z0-9\-]+"
                   value="{{ old('slug', $page->slug) }}" class="{{ $inputCls }}"
                   placeholder="ex: comment-fonctionne-le-scoring">
            <p class="text-[11px] text-gray-600 mt-1">URL publique : <code>/aide/<span id="slug-preview" class="text-gray-400">{{ $page->slug ?: '…' }}</span></code></p>
            @error('slug') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label for="parent_id" class="block text-xs font-medium text-gray-400 mb-1">
                Page parente <span class="text-gray-600">(facultatif)</span>
            </label>
            <select id="parent_id" name="parent_id" class="{{ $inputCls }}">
                <option value="">— Aucune (page racine) —</option>
                @foreach ($parents as $id => $title)
                    <option value="{{ $id }}" {{ (int) old('parent_id', $page->parent_id) === $id ? 'selected' : '' }}>
                        {{ $title }}
                    </option>
                @endforeach
            </select>
            @error('parent_id') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="sort_order" class="block text-xs font-medium text-gray-400 mb-1">
                Ordre d'affichage <span class="text-gray-600">(plus petit = en premier)</span>
            </label>
            <input id="sort_order" name="sort_order" type="number" min="0" max="65535"
                   value="{{ old('sort_order', $page->sort_order) }}" class="{{ $inputCls }}">
            @error('sort_order') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label for="excerpt" class="block text-xs font-medium text-gray-400 mb-1">
            Résumé <span class="text-gray-600">(affiché sur l'index de l'aide, 300 caractères max)</span>
        </label>
        <input id="excerpt" name="excerpt" type="text" maxlength="300"
               value="{{ old('excerpt', $page->excerpt) }}" class="{{ $inputCls }}">
        @error('excerpt') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="flex items-center gap-2 text-sm text-gray-300">
            <input type="hidden" name="is_published" value="0">
            <input type="checkbox" name="is_published" value="1"
                   {{ old('is_published', $page->is_published) ? 'checked' : '' }}
                   class="rounded border-gray-700 bg-gray-950 text-sky-500 focus:ring-sky-500/40">
            Publiée (visible sur /aide)
        </label>
    </div>

    <div>
        <label for="wiki-body" class="block text-xs font-medium text-gray-400 mb-1">Contenu</label>
        <textarea id="wiki-body" name="body">{{ old('body', $page->body) }}</textarea>
        @error('body') <p class="text-red-400 text-xs mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="flex items-center gap-3 pt-2">
        <button type="submit" class="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium rounded-md transition">
            {{ $editing ? 'Enregistrer' : 'Créer la page' }}
        </button>
        <a href="{{ route('admin.wiki.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
    </div>
</form>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    (function () {
        // Prévisualisation du slug
        var titleInput = document.getElementById('title');
        var slugInput  = document.getElementById('slug');
        var slugPrev   = document.getElementById('slug-preview');
        function slugify(s) {
            return s.toLowerCase()
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }
        function updatePreview() {
            var v = slugInput.value || slugify(titleInput.value);
            slugPrev.textContent = v || '…';
        }
        titleInput.addEventListener('input', updatePreview);
        slugInput.addEventListener('input', updatePreview);

        tinymce.init({
            selector: '#wiki-body',
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
                    fetch(@json(route('admin.wiki.upload')), {
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
    })();
</script>
@endpush
