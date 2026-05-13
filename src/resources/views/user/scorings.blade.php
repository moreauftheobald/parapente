<x-app-shell title="Mes scorings perso" page-title="Mes scorings perso"
             detail-title="Filtres" :left-default="true">

    {{-- ─── Panneau gauche : filtres + résumé ─────────────────── --}}
    <x-slot:detail>
        <div x-data class="flex flex-col gap-5">
            <div class="flex items-center gap-2 text-xs">
                <a href="{{ route('user.profile') }}"
                   class="text-gray-400 hover:text-sky-300 transition flex items-center gap-1.5">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i> Retour au profil
                </a>
            </div>

            <div>
                <p class="text-[11px] uppercase tracking-wider text-gray-500 mb-2">Statut</p>
                <div class="flex flex-col gap-1">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="status-filter" value="all"
                               x-model="$store.scoringFilters.status" class="accent-sky-500">
                        <span class="text-gray-300">Tous</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="status-filter" value="active"
                               x-model="$store.scoringFilters.status" class="accent-emerald-500">
                        <span class="text-gray-300">Actifs uniquement</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="status-filter" value="inactive"
                               x-model="$store.scoringFilters.status" class="accent-gray-400">
                        <span class="text-gray-300">Inactifs uniquement</span>
                    </label>
                </div>
            </div>

            <div>
                <p class="text-[11px] uppercase tracking-wider text-gray-500 mb-2">Recherche</p>
                <input type="search" placeholder="Nom de site…"
                       x-model.debounce.200="$store.scoringFilters.search"
                       class="w-full px-3 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500">
            </div>

            <div>
                <p class="text-[11px] uppercase tracking-wider text-gray-500 mb-2">Tri</p>
                <select x-model="$store.scoringFilters.sort"
                        class="w-full px-3 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500">
                    <option value="recent">Récemment activés</option>
                    <option value="name">Nom du site (A→Z)</option>
                    <option value="oldest">Anciennement activés</option>
                </select>
            </div>
        </div>
    </x-slot:detail>

    @php
        $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
        $labelCls = 'block text-xs font-medium text-gray-400 mb-1';
    @endphp

    <div x-data="scoringsScreen()" x-init="boot()" class="px-4 sm:px-6 py-6 max-w-7xl mx-auto">

        {{-- ─── Toolbar haute ─────────────────────────────────── --}}
        <header class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold text-white">Mes scorings perso</h1>
                <p class="text-sm text-gray-500">
                    Définis tes propres conditions de vol favorables — jusqu'à
                    <span class="text-gray-300 font-mono">{{ $maxActive }}</span> scorings actifs simultanément.
                </p>
            </div>

            <div class="flex items-center gap-4">
                {{-- Compteur actifs / stockés --}}
                <div class="flex items-baseline gap-3 text-sm">
                    <span class="font-mono">
                        <span :class="activeCount >= maxActive ? 'text-amber-300' : 'text-emerald-300'"
                              x-text="activeCount"></span>
                        <span class="text-gray-500">/ <span x-text="maxActive"></span></span>
                        <span class="text-gray-400 ml-1">actifs</span>
                    </span>
                    <span class="text-gray-700">·</span>
                    <span class="font-mono text-gray-500">
                        <span x-text="scorings.length"></span> / <span x-text="maxStored"></span>
                        <span class="ml-1">stockés</span>
                    </span>
                </div>

                <button type="button" @click="openCreate()"
                        class="px-4 py-2 rounded-md bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium transition flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Nouveau scoring
                </button>
            </div>
        </header>

        {{-- Toast --}}
        <div x-show="message" x-cloak x-transition.opacity
             :class="messageType === 'error' ? 'border-red-500/40 bg-red-500/10 text-red-300' : 'border-emerald-500/40 bg-emerald-500/10 text-emerald-300'"
             class="mb-4 px-4 py-2 rounded-md border text-sm flex items-start gap-3">
            <span class="flex-1" x-text="message"></span>
            <button type="button" @click="message = ''" class="opacity-60 hover:opacity-100">×</button>
        </div>

        {{-- ─── État vide / chargement ────────────────────────── --}}
        <template x-if="loading">
            <div class="text-center py-16 text-gray-500">
                <i class="fa-solid fa-circle-notch fa-spin text-2xl"></i>
                <p class="mt-3 text-sm">Chargement…</p>
            </div>
        </template>

        <template x-if="!loading && scorings.length === 0">
            <div class="bg-gray-900 border border-dashed border-gray-700 rounded-2xl px-8 py-16 text-center">
                <i class="fa-solid fa-helmet-safety text-4xl text-gray-600"></i>
                <h2 class="mt-4 text-lg font-medium text-gray-200">Aucun scoring perso</h2>
                <p class="mt-2 text-sm text-gray-500 max-w-md mx-auto">
                    Crée ton premier scoring pour personnaliser les conditions favorables sur tes sites préférés.
                </p>
                <button type="button" @click="openCreate()"
                        class="mt-6 px-4 py-2 rounded-md bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium transition inline-flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Créer mon premier scoring
                </button>
            </div>
        </template>

        <template x-if="!loading && scorings.length > 0 && filteredScorings.length === 0">
            <div class="bg-gray-900 border border-dashed border-gray-700 rounded-2xl px-8 py-12 text-center">
                <i class="fa-solid fa-filter text-3xl text-gray-600"></i>
                <p class="mt-3 text-sm text-gray-400">Aucun scoring ne correspond aux filtres.</p>
            </div>
        </template>

        {{-- ─── Grille de cartes ──────────────────────────────── --}}
        <div x-show="!loading && filteredScorings.length > 0" x-cloak
             class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <template x-for="s in filteredScorings" :key="s.id">
                <article class="bg-gray-900 border border-gray-800 rounded-2xl p-5 flex flex-col gap-4"
                         :class="s.is_active ? 'ring-1 ring-emerald-500/30' : ''">

                    {{-- En-tête : site + statut --}}
                    <header class="flex items-start gap-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-base font-semibold text-white truncate" x-text="s.site.name"></h3>
                            <p class="text-[11px] text-gray-500 truncate flex items-center gap-2 mt-0.5">
                                <span x-show="s.site.level" class="px-1.5 py-0.5 bg-gray-800 rounded text-gray-400 uppercase tracking-wider"
                                      x-text="s.site.level"></span>
                                <span x-show="s.site.region" x-text="s.site.region"></span>
                                <span x-show="s.site.altitude_m"
                                      x-text="s.site.altitude_m + ' m'"></span>
                            </p>
                        </div>
                        <template x-if="s.is_active">
                            <span class="shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_rgba(52,211,153,0.8)]"></span>
                                Actif
                            </span>
                        </template>
                        <template x-if="!s.is_active">
                            <span class="shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium text-gray-500 border border-gray-700">
                                <span class="w-1.5 h-1.5 rounded-full ring-1 ring-gray-500"></span>
                                Inactif
                            </span>
                        </template>
                    </header>

                    {{-- Corps : visuels --}}
                    <div class="grid grid-cols-2 gap-3">
                        {{-- Rose des vents --}}
                        <div class="flex flex-col items-center gap-1 bg-gray-950/50 rounded-lg py-3">
                            <div class="text-[10px] uppercase tracking-wider text-gray-600">Direction favorable</div>
                            <div class="relative w-24 h-24" x-html="windRose(s.wind_dir_min, s.wind_dir_max)"></div>
                            <div class="text-[11px] font-mono text-gray-400">
                                <span x-text="s.wind_dir_min + '° → ' + s.wind_dir_max + '°'"></span>
                            </div>
                        </div>

                        {{-- Plage de vent --}}
                        <div class="flex flex-col gap-2 bg-gray-950/50 rounded-lg p-3">
                            <div class="text-[10px] uppercase tracking-wider text-gray-600">Vent (km/h)</div>
                            <div class="relative h-3 bg-gray-800 rounded-full overflow-hidden">
                                {{-- Plage favorable --}}
                                <div class="absolute inset-y-0 bg-emerald-500/40"
                                     :style="`left: ${(s.wind_speed_min/50)*100}%; right: ${100-(s.wind_speed_max/50)*100}%`"></div>
                                {{-- Marker idéal --}}
                                <div class="absolute top-0 bottom-0 w-0.5 bg-sky-300"
                                     :style="`left: ${(s.wind_speed_ideal/50)*100}%`"></div>
                            </div>
                            <div class="grid grid-cols-3 text-[10px] font-mono">
                                <div class="text-gray-500">
                                    <div class="text-gray-300" x-text="s.wind_speed_min"></div>
                                    <div>min</div>
                                </div>
                                <div class="text-center text-sky-300">
                                    <div x-text="s.wind_speed_ideal"></div>
                                    <div class="text-gray-500">idéal</div>
                                </div>
                                <div class="text-right text-gray-500">
                                    <div class="text-gray-300" x-text="s.wind_speed_max"></div>
                                    <div>max</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Détails secondaires --}}
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[11px]">
                        <div class="flex items-center justify-between">
                            <dt class="text-gray-500">Rafale orange</dt>
                            <dd class="font-mono"
                                :class="s.wind_gust_orange_kmh !== null ? 'text-amber-300' : 'text-gray-600'">
                                <span x-text="s.wind_gust_orange_kmh !== null ? s.wind_gust_orange_kmh + ' km/h' : '— défaut'"></span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-gray-500">Rafale rouge</dt>
                            <dd class="font-mono"
                                :class="s.wind_gust_red_kmh !== null ? 'text-red-300' : 'text-gray-600'">
                                <span x-text="s.wind_gust_red_kmh !== null ? s.wind_gust_red_kmh + ' km/h' : '— défaut'"></span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-gray-500">Plafond min</dt>
                            <dd class="font-mono"
                                :class="s.cloud_base_min_m !== null ? 'text-gray-200' : 'text-gray-600'">
                                <span x-text="s.cloud_base_min_m !== null ? s.cloud_base_min_m + ' m' : '— non défini'"></span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-gray-500">Nuages bas max</dt>
                            <dd class="font-mono"
                                :class="s.cloud_cover_low_max !== null ? 'text-gray-200' : 'text-gray-600'">
                                <span x-text="s.cloud_cover_low_max !== null ? s.cloud_cover_low_max + ' %' : '— non défini'"></span>
                            </dd>
                        </div>
                    </dl>

                    {{-- Notes --}}
                    <template x-if="s.notes">
                        <p class="text-xs text-gray-400 bg-gray-950/50 border-l-2 border-gray-700 pl-3 py-1.5 italic"
                           x-text="s.notes"></p>
                    </template>

                    {{-- Pied : activation + actions --}}
                    <footer class="flex items-center justify-between pt-3 border-t border-gray-800">
                        <span class="text-[11px] text-gray-500">
                            <template x-if="s.is_active && s.activated_at">
                                <span>Activé <span x-text="formatRelative(s.activated_at)"></span></span>
                            </template>
                            <template x-if="!s.is_active">
                                <span>Dormant</span>
                            </template>
                        </span>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="openEdit(s)"
                                    class="w-8 h-8 rounded-md text-gray-400 hover:text-sky-300 hover:bg-gray-800 transition"
                                    title="Éditer">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </button>
                            <template x-if="s.is_active">
                                <button type="button" @click="deactivate(s)"
                                        class="px-3 h-8 rounded-md text-xs font-medium bg-emerald-500/15 text-emerald-300 hover:bg-emerald-500/25 transition">
                                    <i class="fa-solid fa-check mr-1"></i> Actif
                                </button>
                            </template>
                            <template x-if="!s.is_active">
                                <button type="button" @click="activate(s)"
                                        class="px-3 h-8 rounded-md text-xs font-medium bg-gray-800 text-gray-300 hover:bg-sky-500/25 hover:text-sky-200 transition">
                                    Activer
                                </button>
                            </template>
                            <button type="button" @click="destroy(s)"
                                    class="w-8 h-8 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-500/10 transition"
                                    title="Supprimer">
                                <i class="fa-solid fa-trash text-xs"></i>
                            </button>
                        </div>
                    </footer>
                </article>
            </template>
        </div>

        {{-- ─── Modal création / édition ──────────────────────── --}}
        <div x-show="showForm" x-cloak x-transition.opacity
             class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4"
             @keydown.escape.window="closeForm()" @click.self="closeForm()">
            <div class="bg-gray-900 border border-gray-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto"
                 @click.stop>
                <header class="sticky top-0 px-6 py-4 bg-gray-900 border-b border-gray-800 flex items-center justify-between z-10">
                    <h3 class="text-lg font-semibold text-white"
                        x-text="form.id ? 'Éditer le scoring' : 'Nouveau scoring perso'"></h3>
                    <button type="button" @click="closeForm()"
                            class="w-8 h-8 rounded text-gray-500 hover:text-white hover:bg-gray-800">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </header>

                <form @submit.prevent="submit()" class="px-6 py-5 flex flex-col gap-4">
                    <template x-if="!form.id">
                        <div>
                            <label class="{{ $labelCls }}">Site</label>
                            <select x-model.number="form.site_id" required class="{{ $inputCls }}">
                                <option value="" disabled>— Choisir un site —</option>
                                <template x-for="site in availableSites" :key="site.id">
                                    <option :value="site.id"
                                            x-text="site.name + (site.region ? ' · ' + site.region : '')"></option>
                                </template>
                            </select>
                            <p class="text-[11px] text-gray-600 mt-1" x-show="availableSites.length === 0">
                                Tu as déjà un scoring perso sur tous les sites actifs.
                            </p>
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.site_id"></p>
                        </div>
                    </template>
                    <template x-if="form.id">
                        <div class="text-sm text-gray-400">
                            Site : <span class="text-white font-medium" x-text="form.site_name"></span>
                        </div>
                    </template>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="{{ $labelCls }}">Direction min (°)</label>
                            <input type="number" min="0" max="360" required
                                   x-model.number="form.wind_dir_min" class="{{ $inputCls }}">
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.wind_dir_min"></p>
                        </div>
                        <div>
                            <label class="{{ $labelCls }}">Direction max (°)</label>
                            <input type="number" min="0" max="360" required
                                   x-model.number="form.wind_dir_max" class="{{ $inputCls }}">
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.wind_dir_max"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="{{ $labelCls }}">Vent min (km/h)</label>
                            <input type="number" min="0" max="100" required
                                   x-model.number="form.wind_speed_min" class="{{ $inputCls }}">
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.wind_speed_min"></p>
                        </div>
                        <div>
                            <label class="{{ $labelCls }}">Vent idéal</label>
                            <input type="number" min="0" max="100" required
                                   x-model.number="form.wind_speed_ideal" class="{{ $inputCls }}">
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.wind_speed_ideal"></p>
                        </div>
                        <div>
                            <label class="{{ $labelCls }}">Vent max</label>
                            <input type="number" min="0" max="100" required
                                   x-model.number="form.wind_speed_max" class="{{ $inputCls }}">
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.wind_speed_max"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="{{ $labelCls }}">Rafale orange <span class="text-gray-600">(optionnel)</span></label>
                            <input type="number" min="0" max="200" step="0.1"
                                   x-model.number="form.wind_gust_orange_kmh" class="{{ $inputCls }}">
                            <p class="text-[11px] text-gray-600 mt-1">Vide = seuil global.</p>
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.wind_gust_orange_kmh"></p>
                        </div>
                        <div>
                            <label class="{{ $labelCls }}">Rafale rouge <span class="text-gray-600">(optionnel)</span></label>
                            <input type="number" min="0" max="200" step="0.1"
                                   x-model.number="form.wind_gust_red_kmh" class="{{ $inputCls }}">
                            <p class="text-[11px] text-gray-600 mt-1">Vide = seuil global.</p>
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.wind_gust_red_kmh"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="{{ $labelCls }}">Plafond minimal (m)</label>
                            <input type="number" min="0" max="5000"
                                   x-model.number="form.cloud_base_min_m" class="{{ $inputCls }}">
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.cloud_base_min_m"></p>
                        </div>
                        <div>
                            <label class="{{ $labelCls }}">Couverture nuages basse max (%)</label>
                            <input type="number" min="0" max="100"
                                   x-model.number="form.cloud_cover_low_max" class="{{ $inputCls }}">
                            <p class="text-[11px] text-red-400 mt-1" x-text="errors.cloud_cover_low_max"></p>
                        </div>
                    </div>

                    <div>
                        <label class="{{ $labelCls }}">Notes <span class="text-gray-600">(optionnel)</span></label>
                        <textarea rows="2" maxlength="2000" x-model="form.notes"
                                  class="{{ $inputCls }} resize-y"></textarea>
                        <p class="text-[11px] text-red-400 mt-1" x-text="errors.notes"></p>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="closeForm()"
                                class="px-4 py-2 rounded-md bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm transition">
                            Annuler
                        </button>
                        <button type="submit" :disabled="submitting"
                                class="px-4 py-2 rounded-md bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium transition disabled:opacity-50">
                            <span x-show="!submitting">Enregistrer</span>
                            <span x-show="submitting"><i class="fa-solid fa-circle-notch fa-spin"></i> Envoi…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        // Store global pour les filtres (réactifs depuis le panneau gauche).
        document.addEventListener('alpine:init', () => {
            Alpine.store('scoringFilters', {
                status: 'all',     // 'all' | 'active' | 'inactive'
                search: '',        // sous-chaîne sur le nom du site
                sort:   'recent',  // 'recent' | 'name' | 'oldest'
            });
        });

        function scoringsScreen() {
            return {
                loading: true,
                submitting: false,
                scorings: [],
                sites: [],
                maxActive: {{ $maxActive }},
                maxStored: {{ $maxStored }},
                activeCount: {{ $initialActiveCount }},
                message: '',
                messageType: 'info',

                showForm: false,
                form: {
                    id: null, site_id: '', site_name: '',
                    wind_dir_min: 270, wind_dir_max: 360,
                    wind_speed_min: 5, wind_speed_max: 25, wind_speed_ideal: 15,
                    wind_gust_orange_kmh: null, wind_gust_red_kmh: null,
                    cloud_base_min_m: null, cloud_cover_low_max: null,
                    notes: '',
                },
                errors: {},

                emptyForm() {
                    return {
                        id: null, site_id: '', site_name: '',
                        wind_dir_min: 270, wind_dir_max: 360,
                        wind_speed_min: 5, wind_speed_max: 25, wind_speed_ideal: 15,
                        wind_gust_orange_kmh: null, wind_gust_red_kmh: null,
                        cloud_base_min_m: null, cloud_cover_low_max: null,
                        notes: '',
                    };
                },

                async boot() {
                    await Promise.all([this.loadScorings(), this.loadSites()]);
                    this.loading = false;
                },

                async loadScorings() {
                    try {
                        const r = await fetch('/api/users/me/scorings', { credentials: 'same-origin' });
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        const data = await r.json();
                        this.scorings    = data.scorings;
                        this.maxActive   = data.max_active;
                        this.maxStored   = data.max_stored;
                        this.activeCount = data.active_count;
                    } catch (e) {
                        this.flash('Impossible de charger les scorings : ' + e.message, 'error');
                    }
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

                get availableSites() {
                    const taken = new Set(this.scorings.map(s => s.site.id));
                    return this.sites
                        .filter(s => !taken.has(s.id))
                        .sort((a, b) => a.name.localeCompare(b.name, 'fr'));
                },

                get filteredScorings() {
                    const f = Alpine.store('scoringFilters');
                    let list = this.scorings.slice();
                    if (f.status === 'active')   list = list.filter(s => s.is_active);
                    if (f.status === 'inactive') list = list.filter(s => !s.is_active);
                    if (f.search.trim()) {
                        const q = f.search.trim().toLowerCase();
                        list = list.filter(s => s.site.name.toLowerCase().includes(q));
                    }
                    if (f.sort === 'name') {
                        list.sort((a, b) => a.site.name.localeCompare(b.site.name, 'fr'));
                    } else if (f.sort === 'oldest') {
                        list.sort((a, b) => (a.activated_at || '').localeCompare(b.activated_at || ''));
                    } else {
                        // recent : actifs récents puis inactifs
                        list.sort((a, b) => {
                            if (a.is_active !== b.is_active) return a.is_active ? -1 : 1;
                            return (b.activated_at || '').localeCompare(a.activated_at || '');
                        });
                    }
                    return list;
                },

                openCreate() {
                    this.form = this.emptyForm();
                    this.errors = {};
                    this.showForm = true;
                },

                openEdit(s) {
                    this.form = {
                        id: s.id, site_id: s.site.id, site_name: s.site.name,
                        wind_dir_min: s.wind_dir_min, wind_dir_max: s.wind_dir_max,
                        wind_speed_min: s.wind_speed_min, wind_speed_max: s.wind_speed_max,
                        wind_speed_ideal: s.wind_speed_ideal,
                        wind_gust_orange_kmh: s.wind_gust_orange_kmh,
                        wind_gust_red_kmh: s.wind_gust_red_kmh,
                        cloud_base_min_m: s.cloud_base_min_m,
                        cloud_cover_low_max: s.cloud_cover_low_max,
                        notes: s.notes ?? '',
                    };
                    this.errors = {};
                    this.showForm = true;
                },

                closeForm() {
                    this.showForm = false;
                    this.errors = {};
                },

                async submit() {
                    this.submitting = true;
                    this.errors = {};
                    const payload = { ...this.form };
                    delete payload.id;
                    delete payload.site_name;
                    if (this.form.id) delete payload.site_id;
                    ['wind_gust_orange_kmh','wind_gust_red_kmh','cloud_base_min_m','cloud_cover_low_max']
                        .forEach(k => {
                            if (payload[k] === '' || payload[k] === null || Number.isNaN(payload[k])) {
                                payload[k] = null;
                            }
                        });

                    try {
                        const url = this.form.id
                            ? `/api/users/me/scorings/${this.form.id}`
                            : '/api/users/me/scorings';
                        const method = this.form.id ? 'PATCH' : 'POST';
                        const r = await this.api(url, method, payload);
                        if (r.status === 422) {
                            const errBody = await r.json();
                            this.errors = this.flattenErrors(errBody.errors || {});
                            return;
                        }
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        await this.loadScorings();
                        this.closeForm();
                        this.flash(this.form.id ? 'Scoring mis à jour.' : 'Scoring créé.', 'info');
                    } catch (e) {
                        this.flash('Erreur : ' + e.message, 'error');
                    } finally {
                        this.submitting = false;
                    }
                },

                async activate(s) {
                    if (this.activeCount >= this.maxActive && !s.is_active) {
                        if (! confirm(
                            `Tu as déjà ${this.maxActive} scorings actifs. Activer celui-ci va désactiver automatiquement le plus ancien. Continuer ?`
                        )) return;
                    }
                    try {
                        const r = await this.api(`/api/users/me/scorings/${s.id}/activate`, 'POST');
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        const data = await r.json();
                        await this.loadScorings();
                        if (data.demoted) {
                            this.flash(`Scoring activé. Le scoring sur ${data.demoted.site.name} a été désactivé automatiquement (rotation 10 actifs max).`, 'info');
                        } else {
                            this.flash('Scoring activé.', 'info');
                        }
                    } catch (e) {
                        this.flash('Impossible d\'activer : ' + e.message, 'error');
                    }
                },

                async deactivate(s) {
                    try {
                        const r = await this.api(`/api/users/me/scorings/${s.id}/deactivate`, 'POST');
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        await this.loadScorings();
                        this.flash('Scoring désactivé.', 'info');
                    } catch (e) {
                        this.flash('Impossible de désactiver : ' + e.message, 'error');
                    }
                },

                async destroy(s) {
                    if (! confirm(`Supprimer le scoring perso sur « ${s.site.name} » ?`)) return;
                    try {
                        const r = await this.api(`/api/users/me/scorings/${s.id}`, 'DELETE');
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        await this.loadScorings();
                        this.flash('Scoring supprimé.', 'info');
                    } catch (e) {
                        this.flash('Impossible de supprimer : ' + e.message, 'error');
                    }
                },

                api(url, method, body) {
                    const headers = {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    };
                    const opts = { method, headers, credentials: 'same-origin' };
                    if (body !== undefined) {
                        headers['Content-Type'] = 'application/json';
                        opts.body = JSON.stringify(body);
                    }
                    return fetch(url, opts);
                },

                flattenErrors(errs) {
                    const out = {};
                    for (const k in errs) {
                        out[k] = Array.isArray(errs[k]) ? errs[k][0] : errs[k];
                    }
                    return out;
                },

                flash(text, type = 'info') {
                    this.message = text;
                    this.messageType = type;
                    setTimeout(() => { if (this.message === text) this.message = ''; }, 6000);
                },

                formatRelative(iso) {
                    if (!iso) return '';
                    const d = new Date(iso);
                    const diffSec = (Date.now() - d.getTime()) / 1000;
                    if (diffSec < 60)      return 'à l\'instant';
                    if (diffSec < 3600)    return `il y a ${Math.floor(diffSec / 60)} min`;
                    if (diffSec < 86400)   return `il y a ${Math.floor(diffSec / 3600)} h`;
                    if (diffSec < 2592000) return `il y a ${Math.floor(diffSec / 86400)} j`;
                    return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
                },

                /**
                 * SVG d'une rose des vents 96×96 avec l'arc favorable colorisé.
                 * Convention météo (FROM) : Nord = 0° en haut, sens horaire.
                 */
                windRose(dirMin, dirMax) {
                    const cx = 48, cy = 48, r = 38, rText = 44;
                    const polar = (deg, radius) => {
                        const a = (deg - 90) * Math.PI / 180; // 0° = Nord (haut)
                        return [cx + radius * Math.cos(a), cy + radius * Math.sin(a)];
                    };
                    const [x1, y1] = polar(dirMin, r);
                    const [x2, y2] = polar(dirMax, r);
                    let sweep = (dirMax - dirMin + 360) % 360;
                    if (sweep === 0) sweep = 360;
                    const largeArc = sweep > 180 ? 1 : 0;

                    const path = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${largeArc} 1 ${x2} ${y2} Z`;

                    // Cardinal labels
                    const labels = ['N','E','S','O'].map((label, i) => {
                        const [tx, ty] = polar(i * 90, rText);
                        return `<text x="${tx.toFixed(1)}" y="${ty.toFixed(1)}" text-anchor="middle" dominant-baseline="middle" font-size="9" fill="#6b7280">${label}</text>`;
                    }).join('');

                    return `<svg viewBox="0 0 96 96" xmlns="http://www.w3.org/2000/svg" class="w-full h-full">
                        <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="#374151" stroke-width="1"/>
                        <path d="${path}" fill="rgba(56,189,248,0.25)" stroke="rgba(56,189,248,0.7)" stroke-width="1"/>
                        ${labels}
                        <circle cx="${cx}" cy="${cy}" r="2" fill="#9ca3af"/>
                    </svg>`;
                },
            };
        }
    </script>
    @endpush
</x-app-shell>
