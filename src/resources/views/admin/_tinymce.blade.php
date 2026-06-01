{{--
    Partial TinyMCE partagé entre articles et wiki.
    Variables attendues :
      $selector   — ID CSS de la textarea (ex: '#article-body')
      $uploadUrl  — URL de l'endpoint d'upload d'images
--}}
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    tinymce.init({
        selector: @json($selector),
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
                fetch(@json($uploadUrl), {
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
