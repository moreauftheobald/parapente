<?php

use App\Http\Controllers\Admin\ArticleController as AdminArticleController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BaliseController as AdminBaliseController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DataQualityController as AdminDataQualityController;
use App\Http\Controllers\Admin\DataSyncController as AdminSyncController;
use App\Http\Controllers\Admin\LogController as AdminLogController;
use App\Http\Controllers\Admin\ModuleController as AdminModuleController;
use App\Http\Controllers\Admin\ReliabilityCompareController as AdminReliabilityCompareController;
use App\Http\Controllers\ModelGridController;
use App\Http\Controllers\Admin\ReliabilityExportController as AdminReliabilityExportController;
use App\Http\Controllers\Admin\ReliabilityHorizonController as AdminReliabilityHorizonController;
use App\Http\Controllers\Admin\ReliabilityModelsController as AdminReliabilityModelsController;
use App\Http\Controllers\Admin\SectionSettingsController as AdminSectionSettingsController;
use App\Http\Controllers\Admin\SettingsAuditController as AdminSettingsAuditController;
use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Admin\TrafficController as AdminTrafficController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WeatherApiController as AdminApiController;
use App\Http\Controllers\Admin\WeatherModelController as AdminModelController;
use App\Http\Controllers\Admin\WikiPageController as AdminWikiPageController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IconCacheController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\WeatherMapController;
use App\Http\Controllers\WikiController;
use App\Http\Controllers\User\HiddenSitePageController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\ScoringPageController;
use Illuminate\Support\Facades\Route;

// Accueil — page d'atterrissage par défaut (articles / changelog + shell global)
Route::get('/', [HomeController::class, 'index'])->name('home');

// Carte de volabilité (module 1)
Route::get('/carte', [MapController::class, 'index'])->name('map');

// Aide en ligne / pseudo-wiki (public)
Route::get('/aide',          [WikiController::class, 'index'])->name('wiki.index');
Route::get('/aide/{slug}',   [WikiController::class, 'show'])->where('slug', '[a-z0-9\-]+')->name('wiki.show');

// Carte des modèles (module front, admin-only le temps que la couverture
// du panel de balises soit suffisante). Cf. table `modules` access_level.
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/carte-modeles',      [ModelGridController::class, 'index'])->name('model-grid.index');
    Route::get('/carte-modeles/data', [ModelGridController::class, 'data'])->name('model-grid.data');
});

// Carte météo (overlays consensus-grid). Pas de middleware d'auth ici —
// la visibilité dans le menu est gérée par le champ `access_level` du
// module en table `modules` (cf. `Module::isVisibleFor()` + filtre dans
// `App\Support\Navigation::modules()`). Passer le module en `guest` /
// `user` / `admin` côté `/admin/modules` se reflète immédiatement sans
// changement de code.
Route::get('/carte-meteo', [WeatherMapController::class, 'index'])->name('weather-map.index');
Route::get('/carte-meteo/manifest', [WeatherMapController::class, 'manifestIndex'])->name('weather-map.manifest');
Route::get('/carte-meteo/overlay/{variable}/{step}.png', [WeatherMapController::class, 'overlay'])
    ->where(['variable' => '[a-z][a-z0-9_]+', 'step' => '[0-9]+'])
    ->name('weather-map.overlay');
Route::get('/carte-meteo/health',   [WeatherMapController::class, 'health'])->name('weather-map.health');
Route::get('/carte-meteo/progress', [WeatherMapController::class, 'progress'])->name('weather-map.progress');

// Tampon d'icônes SpotAir : Nginx sert les fichiers déjà présents
// dans public/icons-cache/ via try_files. PHP n'est appelé qu'au
// premier hit pour chaque combinaison de paramètres.
Route::get('/icons-cache/site/{p}/{t}/{n}/{o}.svg', [IconCacheController::class, 'site'])
    ->where(['p' => '[0-9]+', 't' => '[0-9]+', 'n' => '[0-9]+', 'o' => '[0-9]+']);
Route::get('/icons-cache/balise/{d}/{v}/{t}/{bg}/{c}.svg', [IconCacheController::class, 'balise'])
    ->where(['d' => '[0-9]+', 'v' => '[0-9]+', 't' => '-?[0-9]+', 'bg' => '[wld]', 'c' => '[gbo]']);

// ───────────────────────────────────────────────────────────────
// Auth front (compte « user »)
// ───────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/inscription',  [RegisterController::class, 'showForm'])->name('register');
    Route::post('/inscription', [RegisterController::class, 'store'])->middleware('throttle:5,1');

    Route::get('/connexion',    [LoginController::class, 'showForm'])->name('login');
    Route::post('/connexion',   [LoginController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion',           [LoginController::class, 'destroy'])->name('logout');

    Route::get('/profil',                 [ProfileController::class, 'show'])->name('user.profile');
    Route::patch('/profil',               [ProfileController::class, 'update'])->name('user.profile.update');
    Route::patch('/profil/mot-de-passe',  [ProfileController::class, 'updatePassword'])->name('user.password.update');
    Route::delete('/profil',              [ProfileController::class, 'destroy'])->name('user.profile.destroy');

    // Page d'écran complet : gestion des scorings perso
    Route::get('/profil/scorings',        [ScoringPageController::class, 'index'])->name('user.scorings');

    // Page d'écran complet : gestion des sites masqués sur la carte
    Route::get('/profil/sites-masques',   [HiddenSitePageController::class, 'index'])->name('user.hidden-sites');
});

// ───────────────────────────────────────────────────────────────
// BackOffice (/admin)
// ───────────────────────────────────────────────────────────────
Route::prefix('admin')->name('admin.')->group(function () {
    // Auth (publiques)
    Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    // Zone protégée : auth + admin
    Route::middleware(['auth', 'admin'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // ── Paramètres par section ───────────────────────────────
        Route::get('/contenu/settings', [AdminSectionSettingsController::class, 'contenu'])->name('contenu.settings');
        Route::get('/meteo/settings',              [AdminSectionSettingsController::class, 'meteo'])->name('meteo.settings');
        Route::post('/meteo/settings/consensus',     [AdminSectionSettingsController::class, 'updateConsensus'])->name('meteo.settings.consensus');
        Route::post('/meteo/settings/orchestration', [AdminSectionSettingsController::class, 'updateOrchestration'])->name('meteo.settings.orchestration');
        Route::post('/meteo/settings/variable-override', [AdminSectionSettingsController::class, 'updateVariableOverride'])->name('meteo.settings.variable-override');
        Route::get('/sites/settings',   [AdminSectionSettingsController::class, 'sites'])->name('sites.settings');
        Route::get('/balises/settings', [AdminSectionSettingsController::class, 'balises'])->name('balises.settings');

        // ── Sites ────────────────────────────────────────────────
        Route::get('/sites',                   [AdminSiteController::class, 'index'])->name('sites.index');
        Route::get('/sites/map',               [AdminSiteController::class, 'map'])->name('sites.map');
        Route::get('/sites/{site}/edit',       [AdminSiteController::class, 'edit'])->name('sites.edit');
        Route::patch('/sites/{site}',          [AdminSiteController::class, 'update'])->name('sites.update');
        Route::delete('/sites/{site}',         [AdminSiteController::class, 'destroy'])->name('sites.destroy');
        Route::post('/sites/{site}/toggle',    [AdminSiteController::class, 'toggleActive'])->name('sites.toggle');

        // ── Utilisateurs ─────────────────────────────────────────
        Route::get('/users',              [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/create',       [AdminUserController::class, 'create'])->name('users.create');
        Route::post('/users',             [AdminUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit',  [AdminUserController::class, 'edit'])->name('users.edit');
        Route::patch('/users/{user}',     [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}',    [AdminUserController::class, 'destroy'])->name('users.destroy');

        // ── Balises ──────────────────────────────────────────────
        Route::get('/balises',                                [AdminBaliseController::class, 'index'])->name('balises.index');
        Route::get('/balises/{balise}',                       [AdminBaliseController::class, 'show'])->name('balises.show');
        Route::post('/balises/{balise}/toggle',               [AdminBaliseController::class, 'toggleActive'])->name('balises.toggle');
        Route::post('/balises/{balise}/toggle-compare-panel', [AdminBaliseController::class, 'togglePanel'])->name('balises.toggle-compare-panel');
        Route::delete('/balises/{balise}',                    [AdminBaliseController::class, 'destroy'])->name('balises.destroy');

        // ── Modèles météo ────────────────────────────────────────
        Route::get('/models',                  [AdminModelController::class, 'index'])->name('models.index');
        Route::get('/models/{model}/edit',     [AdminModelController::class, 'edit'])->name('models.edit');
        Route::patch('/models/{model}',        [AdminModelController::class, 'update'])->name('models.update');
        Route::post('/models/{model}/toggle',  [AdminModelController::class, 'toggleActive'])->name('models.toggle');
        Route::post('/models/{model}/test',    [AdminModelController::class, 'test'])->name('models.test');
        Route::post('/models/{model}/inspect', [AdminModelController::class, 'inspect'])->name('models.inspect');

        // ── APIs météo ───────────────────────────────────────────
        Route::get('/apis',                [AdminApiController::class, 'index'])->name('apis.index');
        Route::get('/apis/{api}/edit',     [AdminApiController::class, 'edit'])->name('apis.edit');
        Route::patch('/apis/{api}',        [AdminApiController::class, 'update'])->name('apis.update');
        Route::post('/apis/{api}/toggle',  [AdminApiController::class, 'toggleActive'])->name('apis.toggle');

        // ── Synchronisation des données (actions POST, vues intégrées aux pages Paramètres) ──
        Route::post('/sync/sites',   [AdminSyncController::class, 'importSites'])->name('sync.sites');
        Route::post('/sync/balises', [AdminSyncController::class, 'discoverBalises'])->name('sync.balises');
        Route::post('/sync/deploy',  [AdminSyncController::class, 'deploy'])->name('sync.deploy');

        // ── Qualité des données (doublons sites / balises) ────────
        Route::get('/data-quality',                            [AdminDataQualityController::class, 'index'])->name('data-quality.index');
        Route::post('/data-quality/ignore',                    [AdminDataQualityController::class, 'ignore'])->name('data-quality.ignore');
        Route::delete('/data-quality/ignore/{ignored}',        [AdminDataQualityController::class, 'unignore'])->name('data-quality.unignore');

        // ── Trafic / fréquentation ────────────────────────────────
        Route::get('/traffic', [AdminTrafficController::class, 'index'])->name('traffic.index');

        // ── Articles / changelog (page d'accueil) ────────────────
        Route::get('/articles',                   [AdminArticleController::class, 'index'])->name('articles.index');
        Route::get('/articles/create',            [AdminArticleController::class, 'create'])->name('articles.create');
        Route::post('/articles',                  [AdminArticleController::class, 'store'])->name('articles.store');
        Route::post('/articles/upload-image',     [AdminArticleController::class, 'uploadImage'])->name('articles.upload');
        Route::get('/articles/{article}/edit',    [AdminArticleController::class, 'edit'])->name('articles.edit');
        Route::patch('/articles/{article}',       [AdminArticleController::class, 'update'])->name('articles.update');
        Route::delete('/articles/{article}',      [AdminArticleController::class, 'destroy'])->name('articles.destroy');
        Route::post('/articles/{article}/toggle', [AdminArticleController::class, 'toggle'])->name('articles.toggle');

        // ── Wiki / aide en ligne ─────────────────────────────────
        Route::get('/wiki',                       [AdminWikiPageController::class, 'index'])->name('wiki.index');
        Route::get('/wiki/create',                [AdminWikiPageController::class, 'create'])->name('wiki.create');
        Route::post('/wiki',                      [AdminWikiPageController::class, 'store'])->name('wiki.store');
        Route::post('/wiki/upload-image',         [AdminWikiPageController::class, 'uploadImage'])->name('wiki.upload');
        Route::get('/wiki/{wikiPage}/edit',       [AdminWikiPageController::class, 'edit'])->name('wiki.edit');
        Route::patch('/wiki/{wikiPage}',          [AdminWikiPageController::class, 'update'])->name('wiki.update');
        Route::delete('/wiki/{wikiPage}',         [AdminWikiPageController::class, 'destroy'])->name('wiki.destroy');
        Route::post('/wiki/{wikiPage}/toggle',    [AdminWikiPageController::class, 'toggle'])->name('wiki.toggle');

        // ── Modules du menu principal ────────────────────────────
        Route::get('/modules',           [AdminModuleController::class, 'index'])->name('modules.index');
        Route::patch('/modules/{module}', [AdminModuleController::class, 'update'])->name('modules.update');

        // ── Logs / monitoring ─────────────────────────────────────
        Route::get('/logs', [AdminLogController::class, 'index'])->name('logs.index');

        // ── Paramètres généraux (seuils de scoring) ───────────────
        Route::get('/settings',       [AdminSettingsController::class, 'index'])->name('settings.index');
        Route::patch('/settings',     [AdminSettingsController::class, 'update'])->name('settings.update');
        Route::get('/settings/audit', [AdminSettingsAuditController::class, 'index'])->name('settings.audit');

        // ── Fiabilité des modèles (phase 2.5 — shadow comparatif) ─
        Route::get('/reliability/compare',          [AdminReliabilityCompareController::class, 'index'])->name('reliability.compare');
        Route::get('/reliability/horizon',          [AdminReliabilityHorizonController::class, 'index'])->name('reliability.horizon');
        Route::get('/reliability/models',           [AdminReliabilityModelsController::class, 'index'])->name('reliability.models');
        Route::post('/reliability/models/recompute',[AdminReliabilityModelsController::class, 'recompute'])->name('reliability.models.recompute');

        // Exports (CSV et JSON complet — cf. RELIABILITY_ANALYSIS_CONTEXT.md)
        Route::get('/reliability/export.json',                          [AdminReliabilityExportController::class, 'json'])->name('reliability.export.json');
        Route::get('/reliability/export/consensus-compare.csv',         [AdminReliabilityExportController::class, 'consensusCompareCsv'])->name('reliability.export.compare-csv');
        Route::get('/reliability/export/model-reliability.csv',         [AdminReliabilityExportController::class, 'modelReliabilityCsv'])->name('reliability.export.reliability-csv');
        Route::get('/reliability/export/horizon-mae.csv',               [AdminReliabilityExportController::class, 'horizonStatsCsv'])->name('reliability.export.horizon-csv');
    });
});
