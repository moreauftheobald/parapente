{{--
    Partial réutilisable — formulaire de settings groupés.

    Variables attendues :
      $settingsGroups  array  — même format que $groups dans SettingsController::index()
                               (chaque entrée : title, icon, keys[])
      $saveAction      string — URL de la route PATCH (ex: route('admin.sites.settings.general'))
      $saveMethod      string — méthode HTTP, défaut 'PATCH'
--}}
@php
    $saveMethod   ??= 'PATCH';
    $inputCls       = 'w-full px-2.5 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 font-mono focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $fieldName      = fn (string $key) => str_replace('.', '__', $key);
@endphp

@if ($errors->any())
    <x-admin.alert type="error">
        <strong>Quelques erreurs :</strong>
        <ul class="list-disc list-inside mt-1">
            @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
        </ul>
    </x-admin.alert>
@endif

<form method="POST" action="{{ $saveAction }}" class="space-y-6">
    @csrf
    @method($saveMethod)

    @foreach ($settingsGroups as $groupKey => $group)
        @if (! empty($group['keys']))
            <section class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider mb-4 flex items-center gap-2">
                    <i class="fa-solid {{ $group['icon'] ?? 'fa-gear' }}"></i> {{ $group['title'] }}
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                    @foreach ($group['keys'] as $key => $meta)
                        @php
                            $name     = $fieldName($key);
                            $type     = $meta['type'] ?? 'float';
                            $isSecret = $type === 'secret';
                            $isString = $type === 'string';
                            $isBool   = $type === 'bool';
                            $colSpan  = ($isSecret || $isString) ? 'md:col-span-2 xl:col-span-3' : '';
                            $tooltip  = trim(($meta['description'] ?? '') . "\n\nclé : {$key}" . (! $isSecret && isset($meta['default']) ? " · défaut : {$meta['default']}" : ''));
                        @endphp
                        <div class="{{ $colSpan }}">
                            <div class="flex items-center gap-2">
                                <label class="shrink-0 text-sm text-gray-400 flex items-center gap-1" for="{{ $name }}">
                                    {{ $meta['label'] ?? $key }}
                                    <x-admin.tooltip :text="$tooltip" />
                                </label>
                                @if ($isBool)
                                    @php $checked = (bool) old($name, $meta['value']); @endphp
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                                        <input type="checkbox"
                                               id="{{ $name }}"
                                               name="{{ $name }}"
                                               value="1"
                                               @checked($checked)
                                               class="h-4 w-4 rounded border-gray-700 bg-gray-800 text-sky-500 focus:ring-sky-500/30">
                                        <span class="text-xs text-gray-500">{{ $checked ? 'Oui' : 'Non' }}</span>
                                    </label>
                                @elseif ($isSecret)
                                    @php
                                        $current  = (string) ($meta['value'] ?? '');
                                        $hasValue = $current !== '';
                                        $tail     = $hasValue ? substr($current, -4) : '';
                                    @endphp
                                    <div x-data="{ shown: false }" class="relative flex-1">
                                        <input :type="shown ? 'text' : 'password'"
                                               id="{{ $name }}"
                                               name="{{ $name }}"
                                               autocomplete="new-password"
                                               value="{{ old($name, $current) }}"
                                               placeholder="{{ $hasValue ? '••••••••••••' . $tail : 'Coller la clé ici…' }}"
                                               class="{{ $inputCls }} pr-10">
                                        <button type="button"
                                                @click="shown = ! shown"
                                                tabindex="-1"
                                                class="absolute inset-y-0 right-2 flex items-center px-2 text-gray-500 hover:text-gray-200 transition"
                                                :title="shown ? 'Masquer' : 'Afficher'">
                                            <i :class="shown ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" class="text-xs"></i>
                                        </button>
                                    </div>
                                @elseif ($isString)
                                    <input type="text"
                                           id="{{ $name }}"
                                           name="{{ $name }}"
                                           value="{{ old($name, $meta['value']) }}"
                                           class="{{ $inputCls }} flex-1">
                                @else
                                    <input type="number"
                                           id="{{ $name }}"
                                           name="{{ $name }}"
                                           step="{{ $type === 'int' ? '1' : 'any' }}"
                                           min="0"
                                           required
                                           value="{{ old($name, $meta['value']) }}"
                                           class="{{ $inputCls }} flex-1">
                                @endif
                            </div>
                            @if ($isSecret && $hasValue)
                                <p class="text-[11px] text-emerald-400 mt-0.5">
                                    <i class="fa-solid fa-circle-check"></i> Clé enregistrée (…{{ $tail }}). Vider pour supprimer.
                                </p>
                            @endif
                            @error($name) <p class="text-red-400 text-xs mt-0.5">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach

    <div class="flex items-center justify-between">
        <x-admin.button type="submit" variant="primary" icon="fa-solid fa-floppy-disk">
            Enregistrer les paramètres
        </x-admin.button>
    </div>
</form>
