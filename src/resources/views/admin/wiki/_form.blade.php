@php
    $editing  = $page->exists;
    $action   = $editing ? route('admin.wiki.update', $page) : route('admin.wiki.store');
    $selectCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 text-sm focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $textareaCls = $selectCls;
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($editing) @method('PATCH') @endif

    <div class="grid sm:grid-cols-2 gap-4">
        <x-admin.input name="title" label="Titre" :value="old('title', $page->title)" required maxlength="200" />
        <x-admin.input
            name="slug"
            label="Slug (facultatif — généré depuis le titre)"
            :value="old('slug', $page->slug)"
            maxlength="220"
            pattern="[a-z0-9\-]+"
            placeholder="ex: comment-fonctionne-le-scoring">
        </x-admin.input>
    </div>
    <p class="text-[11px] text-gray-600 -mt-3">URL publique : <code>/aide/<span id="slug-preview" class="text-gray-400">{{ $page->slug ?: '…' }}</span></code></p>

    <div class="grid sm:grid-cols-2 gap-4">
        <x-admin.field name="parent_id" label="Page parente (facultatif)">
            <select id="parent_id" name="parent_id" class="{{ $selectCls }}">
                <option value="">— Aucune (page racine) —</option>
                @foreach ($parents as $id => $title)
                    <option value="{{ $id }}" {{ (int) old('parent_id', $page->parent_id) === $id ? 'selected' : '' }}>
                        {{ $title }}
                    </option>
                @endforeach
            </select>
        </x-admin.field>
        <x-admin.input
            name="sort_order"
            label="Ordre d'affichage (plus petit = en premier)"
            type="number"
            min="0"
            max="65535"
            :value="old('sort_order', $page->sort_order)" />
    </div>

    <x-admin.input
        name="excerpt"
        label="Résumé (affiché sur l'index de l'aide, 300 caractères max)"
        :value="old('excerpt', $page->excerpt)"
        maxlength="300" />

    <div>
        <label class="flex items-center gap-2 text-sm text-gray-300">
            <input type="hidden" name="is_published" value="0">
            <input type="checkbox" name="is_published" value="1"
                   {{ old('is_published', $page->is_published) ? 'checked' : '' }}
                   class="rounded border-gray-700 bg-gray-950 text-sky-500 focus:ring-sky-500/40">
            Publiée (visible sur /aide)
        </label>
    </div>

    <x-admin.field name="body" label="Contenu">
        <textarea id="wiki-body" name="body" class="{{ $textareaCls }}">{{ old('body', $page->body) }}</textarea>
    </x-admin.field>

    <div class="flex items-center gap-3 pt-2">
        <x-admin.button type="submit" variant="primary">
            {{ $editing ? 'Enregistrer' : 'Créer la page' }}
        </x-admin.button>
        <a href="{{ route('admin.wiki.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
    </div>
</form>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    (function () {
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
