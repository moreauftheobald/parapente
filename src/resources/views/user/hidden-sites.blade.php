<x-app-shell title="Sites masqués" page-title="Sites masqués"
             detail-title="Aide" :left-default="false">

    {{-- ─── Panneau gauche : aide ─────────────────────────────── --}}
    <x-slot:detail>
        <div class="flex flex-col gap-5">
            <div class="flex items-center gap-2 text-xs">
                <a href="{{ route('user.profile') }}"
                   class="text-gray-400 hover:text-sky-300 transition flex items-center gap-1.5">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i> Retour au profil
                </a>
            </div>

            <p class="text-[12px] text-gray-400 leading-relaxed">
                Par défaut, tous les sites sont affichés sur la carte de
                volabilité. Masque ceux qui ne t'intéressent pas : ils
                disparaîtront de la carte quand tu es connecté.
            </p>
            <p class="text-[11px] text-gray-600 leading-relaxed">
                Utilise la barre de filtres (nom, pays, région, département,
                état) pour retrouver rapidement un site dans la liste.
            </p>
        </div>
    </x-slot:detail>

    <div x-data="hiddenSitesScreen()" x-init="boot()" class="px-4 sm:px-6 py-6 max-w-5xl mx-auto">

        {{-- ─── Toolbar haute ─────────────────────────────────── --}}
        <header class="flex flex-wrap items-center justify-between gap-4 mb-5">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold text-white">Sites masqués</h1>
                <p class="text-sm text-gray-500">
                    Choisis les sites à ne pas afficher sur ta carte de volabilité.
                </p>
            </div>

            <div class="flex items-baseline gap-2 text-sm font-mono">
                <span :class="hiddenIds.length > 0 ? 'text-amber-300' : 'text-gray-500'"
                      x-text="hiddenIds.length"></span>
                <span class="text-gray-500">site<span x-show="hiddenIds.length !== 1">s</span> masqué<span x-show="hiddenIds.length !== 1">s</span></span>
            </div>
        </header>

        {{-- ─── Barre de filtrage ─────────────────────────────── --}}
        @php
            $fieldCls = 'px-3 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500';
        @endphp
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-3 mb-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1 flex-1 min-w-[180px]">
                <label class="text-[10px] uppercase tracking-wider text-gray-500">Nom</label>
                <input type="search" placeholder="Nom de site…"
                       x-model.debounce.200="filters.name" class="{{ $fieldCls }} w-full">
            </div>

            <div class="flex flex-col gap-1 min-w-[140px]">
                <label class="text-[10px] uppercase tracking-wider text-gray-500">Pays</label>
                <select x-model="filters.country" @change="onCountryChange()" class="{{ $fieldCls }}">
                    <option value="">Tous les pays</option>
                    <template x-for="c in countryOptions" :key="c">
                        <option :value="c" x-text="c"></option>
                    </template>
                </select>
            </div>

            <div class="flex flex-col gap-1 min-w-[150px]">
                <label class="text-[10px] uppercase tracking-wider text-gray-500">Région</label>
                <select x-model="filters.region" @change="onRegionChange()" class="{{ $fieldCls }}">
                    <option value="">Toutes les régions</option>
                    <template x-for="r in regionOptions" :key="r">
                        <option :value="r" x-text="r"></option>
                    </template>
                </select>
            </div>

            <div class="flex flex-col gap-1 min-w-[150px]">
                <label class="text-[10px] uppercase tracking-wider text-gray-500">Département</label>
                <select x-model="filters.department" class="{{ $fieldCls }}">
                    <option value="">Tous les départements</option>
                    <template x-for="d in departmentOptions" :key="d">
                        <option :value="d" x-text="d"></option>
                    </template>
                </select>
            </div>

            <div class="flex flex-col gap-1 min-w-[130px]">
                <label class="text-[10px] uppercase tracking-wider text-gray-500">État</label>
                <select x-model="filters.scope" class="{{ $fieldCls }}">
                    <option value="all">Tous</option>
                    <option value="hidden">Masqués</option>
                    <option value="visible">Affichés</option>
                </select>
            </div>

            <button type="button" @click="resetFilters()"
                    x-show="hasActiveFilters"
                    class="px-3 py-1.5 rounded-md text-xs text-gray-400 hover:text-white hover:bg-gray-800 border border-gray-700 transition"
                    title="Réinitialiser les filtres">
                <i class="fa-solid fa-xmark mr-1"></i> Réinitialiser
            </button>
        </div>

        {{-- Toast --}}
        <div x-show="message" x-cloak x-transition.opacity
             :class="messageType === 'error' ? 'border-red-500/40 bg-red-500/10 text-red-300' : 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'"
             class="mb-4 px-4 py-2 rounded-md border text-sm flex items-start gap-3">
            <span class="flex-1" x-text="message"></span>
            <button type="button" @click="message = ''" class="opacity-60 hover:opacity-100">×</button>
        </div>

        {{-- ─── Chargement / états vides ──────────────────────── --}}
        <template x-if="loading">
            <div class="text-center py-16 text-gray-500">
                <i class="fa-solid fa-circle-notch fa-spin text-2xl"></i>
                <p class="mt-3 text-sm">Chargement…</p>
            </div>
        </template>

        <template x-if="!loading && sites.length > 0 && filteredSites.length === 0">
            <div class="bg-gray-900 border border-dashed border-gray-700 rounded-2xl px-8 py-12 text-center">
                <i class="fa-solid fa-filter text-3xl text-gray-600"></i>
                <p class="mt-3 text-sm text-gray-400">Aucun site ne correspond aux filtres.</p>
            </div>
        </template>

        {{-- ─── Compteur de résultats ─────────────────────────── --}}
        <p x-show="!loading && filteredSites.length > 0" x-cloak class="text-[11px] text-gray-600 mb-2">
            <span x-text="filteredSites.length"></span> site<span x-show="filteredSites.length !== 1">s</span>
            <span x-show="hasActiveFilters"> · filtré<span x-show="filteredSites.length !== 1">s</span> sur <span x-text="sites.length"></span></span>
        </p>

        {{-- ─── Liste des sites ───────────────────────────────── --}}
        <div x-show="!loading && filteredSites.length > 0" x-cloak
             class="bg-gray-900 border border-gray-800 rounded-2xl divide-y divide-gray-800 overflow-hidden">
            <template x-for="site in filteredSites" :key="site.id">
                <div class="flex items-center gap-4 px-4 sm:px-5 py-3"
                     :class="isHidden(site.id) ? 'bg-amber-500/5' : ''">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-medium text-white truncate"
                                :class="isHidden(site.id) ? 'text-gray-400 line-through decoration-gray-600' : ''"
                                x-text="site.name"></h3>
                            <span x-show="site.level"
                                  class="shrink-0 px-1.5 py-0.5 bg-gray-800 rounded text-[10px] text-gray-400 uppercase tracking-wider"
                                  x-text="site.level"></span>
                        </div>
                        <p class="text-[11px] text-gray-500 truncate flex items-center gap-2 mt-0.5">
                            <span x-show="site.country" x-text="site.country"></span>
                            <span x-show="site.admin_region" class="text-gray-600">·</span>
                            <span x-show="site.admin_region" x-text="site.admin_region"></span>
                            <span x-show="site.department" class="text-gray-600">·</span>
                            <span x-show="site.department" x-text="site.department"></span>
                            <span x-show="!site.country && site.region" x-text="site.region"></span>
                            <span x-show="site.altitude" class="text-gray-600">·</span>
                            <span x-show="site.altitude" x-text="site.altitude + ' m'"></span>
                        </p>
                    </div>

                    {{-- Badge état --}}
                    <span class="shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium"
                          :class="isHidden(site.id)
                              ? 'bg-amber-500/15 text-amber-300 border border-amber-500/30'
                              : 'text-emerald-300/80 border border-emerald-500/20'">
                        <i :class="isHidden(site.id) ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" class="text-[10px]"></i>
                        <span x-text="isHidden(site.id) ? 'Masqué' : 'Affiché'"></span>
                    </span>

                    {{-- Bouton bascule --}}
                    <button type="button" @click="toggle(site)"
                            :disabled="busy.includes(site.id)"
                            class="shrink-0 px-3 h-8 rounded-md text-xs font-medium transition disabled:opacity-50"
                            :class="isHidden(site.id)
                                ? 'bg-gray-800 text-gray-300 hover:bg-emerald-500/25 hover:text-emerald-200'
                                : 'bg-gray-800 text-gray-300 hover:bg-amber-500/25 hover:text-amber-200'"
                            :title="isHidden(site.id) ? 'Réafficher sur la carte' : 'Masquer de la carte'">
                        <template x-if="busy.includes(site.id)">
                            <i class="fa-solid fa-circle-notch fa-spin"></i>
                        </template>
                        <template x-if="!busy.includes(site.id)">
                            <span x-text="isHidden(site.id) ? 'Réafficher' : 'Masquer'"></span>
                        </template>
                    </button>
                </div>
            </template>
        </div>
    </div>

    @push('scripts')
    <script>
        function hiddenSitesScreen() {
            return {
                loading: true,
                sites: [],
                hiddenIds: [],
                busy: [],          // ids en cours de bascule (anti double-clic)
                message: '',
                messageType: 'info',
                filters: { name: '', country: '', region: '', department: '', scope: 'all' },

                async boot() {
                    await Promise.all([this.loadSites(), this.loadHidden()]);
                    this.loading = false;
                },

                async loadSites() {
                    try {
                        const r = await fetch('/api/sites', { credentials: 'same-origin' });
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        this.sites = await r.json();
                    } catch (e) {
                        this.flash('Impossible de charger la liste des sites : ' + e.message, 'error');
                    }
                },

                async loadHidden() {
                    try {
                        const r = await fetch('/api/users/me/hidden-sites', { credentials: 'same-origin' });
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        const data = await r.json();
                        this.hiddenIds = data.hidden_site_ids || [];
                    } catch (e) {
                        this.flash('Impossible de charger les sites masqués : ' + e.message, 'error');
                    }
                },

                isHidden(id) { return this.hiddenIds.includes(id); },

                // ── Options de filtre (en cascade pays → région → dépt) ──
                _sortedUnique(values) {
                    return [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr'));
                },
                get countryOptions() {
                    return this._sortedUnique(this.sites.map(s => s.country));
                },
                get regionOptions() {
                    const c = this.filters.country;
                    return this._sortedUnique(
                        this.sites.filter(s => !c || s.country === c).map(s => s.admin_region)
                    );
                },
                get departmentOptions() {
                    const c = this.filters.country, r = this.filters.region;
                    return this._sortedUnique(
                        this.sites
                            .filter(s => (!c || s.country === c) && (!r || s.admin_region === r))
                            .map(s => s.department)
                    );
                },
                onCountryChange() { this.filters.region = ''; this.filters.department = ''; },
                onRegionChange()  { this.filters.department = ''; },

                get hasActiveFilters() {
                    const f = this.filters;
                    return !!(f.name.trim() || f.country || f.region || f.department || f.scope !== 'all');
                },
                resetFilters() {
                    this.filters = { name: '', country: '', region: '', department: '', scope: 'all' };
                },

                get filteredSites() {
                    const f = this.filters;
                    let list = this.sites.slice();
                    if (f.scope === 'hidden')  list = list.filter(s => this.isHidden(s.id));
                    if (f.scope === 'visible') list = list.filter(s => !this.isHidden(s.id));
                    if (f.country)    list = list.filter(s => s.country === f.country);
                    if (f.region)     list = list.filter(s => s.admin_region === f.region);
                    if (f.department) list = list.filter(s => s.department === f.department);
                    if (f.name.trim()) {
                        const q = f.name.trim().toLowerCase();
                        list = list.filter(s => (s.name || '').toLowerCase().includes(q));
                    }
                    list.sort((a, b) => (a.name || '').localeCompare(b.name || '', 'fr'));
                    return list;
                },

                async toggle(site) {
                    if (this.busy.includes(site.id)) return;
                    this.busy.push(site.id);
                    const wasHidden = this.isHidden(site.id);
                    const method = wasHidden ? 'DELETE' : 'PUT';
                    try {
                        const r = await this.api(`/api/users/me/hidden-sites/${site.id}`, method);
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        if (wasHidden) {
                            this.hiddenIds = this.hiddenIds.filter(id => id !== site.id);
                            this.flash(`« ${site.name} » est de nouveau affiché sur la carte.`, 'info');
                        } else {
                            if (!this.hiddenIds.includes(site.id)) this.hiddenIds.push(site.id);
                            this.flash(`« ${site.name} » ne s'affichera plus sur la carte.`, 'info');
                        }
                    } catch (e) {
                        this.flash('Erreur : ' + e.message, 'error');
                    } finally {
                        this.busy = this.busy.filter(id => id !== site.id);
                    }
                },

                api(url, method) {
                    return fetch(url, {
                        method,
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                    });
                },

                flash(text, type = 'info') {
                    this.message = text;
                    this.messageType = type;
                    setTimeout(() => { if (this.message === text) this.message = ''; }, 5000);
                },
            };
        }
    </script>
    @endpush
</x-app-shell>
