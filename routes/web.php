<?php

use Illuminate\Support\Facades\Route;

// Fortify (auth + 2FA)
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;

// App Controllers
use App\Http\Controllers\AnilistController;
use App\Http\Controllers\DoujinController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\VndbController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\ChapterController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ConfirmTwoFactorAuthenticationController;

// -----------------------------
// Public
// -----------------------------

Route::get('/', [AnilistController::class, 'home'])->middleware('auth')->name('home');

Route::get('/home-paginated', [AnilistController::class, 'paginatedMedia'])
    ->middleware('auth')
    ->name('home.paginated');

Route::get('/search', [SearchController::class, 'index'])
    ->middleware('auth')
    ->name('search.index');

// Registration
Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::post('/register', [RegisterController::class, 'register'])->name('register.post');
Route::get('/register/pending', fn () => view('auth.register_pending'))
    ->name('register.pending');

// Admin email confirm
Route::get('/admin/confirm/{user}', [AdminController::class, 'confirm'])
    ->middleware('signed')
    ->name('admin.confirm');

// Login/logout
Route::get('/login',  [AuthenticatedSessionController::class, 'create'])->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store']);
Route::post('/logout',[AuthenticatedSessionController::class, 'destroy'])->name('logout');

// 2FA
Route::get('/two_factor_challenge',  [TwoFactorAuthenticatedSessionController::class, 'create'])
    ->name('two-factor.login');
Route::post('/two_factor_challenge', [TwoFactorAuthenticatedSessionController::class, 'store']);

// Media detail pages
Route::get('/media/{id}', [AnilistController::class, 'show'])->middleware('auth')->name('media.show');
Route::get('/vn/{id}',    [VndbController::class, 'show'])->middleware('auth')->name('vn.show');

// VNDB API endpoints
Route::prefix('vndb')->middleware('auth')->group(function () {
    Route::get('search',   [VndbController::class, 'searchVN']);
    Route::get('vn/{id}',  [VndbController::class, 'getVNDetails']);
    Route::get('api-media',[VndbController::class, 'apiList'])->name('vndb.api.media');
});

// Category browsing
Route::get('/category/{category}/{listFilter?}/{mediaStatus?}/{titleOrder?}/{scoreOrder?}/{dateOrder?}',
    [CategoryController::class, 'show'])
    ->middleware('auth')
    ->where('category', '(?i)(ANIMES|MANGAS|MANWHAS|HENTAIS|DOUJINS|VISUAL-NOVEL)')
    ->name('category');

// Doujin pages
Route::get('/doujin/{media}', [DoujinController::class, 'show'])->middleware('auth')->name('doujins.show');

// -----------------------------
// Authenticated
// -----------------------------
Route::middleware('auth')->group(function () {

    // Fortify 2FA management
    Route::prefix('user')->group(function () {
        // Enable/Disable 2FA
        Route::post('/two_factor_authentication',   [TwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.enable');
        Route::delete('/two_factor_authentication', [TwoFactorAuthenticationController::class, 'destroy'])
            ->name('two-factor.disable');

        // Confirm 2FA
        Route::post('/confirmed-two-factor-authentication', [ConfirmTwoFactorAuthenticationController::class, 'store'])
            ->name('two-factor.confirm');

        // Recovery codes
        Route::post('/two_factor_recovery_codes', [RecoveryCodeController::class, 'store'])
            ->name('two-factor.recovery-codes');
    });

    // Settings area
    Route::prefix('settings')->name('settings.')->group(function () {
        // Profile
        Route::get('/',  [SettingsController::class, 'edit'])->name('profile.edit');
        Route::put('/',  [SettingsController::class, 'update'])->name('profile.update');

        // Update list screen
        Route::get('/update-list', [SettingsController::class, 'updateList'])->name('list');

        // Users
        Route::get('/users', [SettingsController::class, 'users'])->name('users');
        Route::put('/users/{user}', [SettingsController::class, 'updateManagedUser'])->name('users.update');

        // Security / 2FA
        Route::view('/security', 'settings.security')->name('security');
    });

    // Collections
    Route::prefix('collection')->group(function () {
        // Index + create
        Route::get('/',        [CollectionController::class, 'index'])->name('collection.index');
        Route::get('/create',  [CollectionController::class, 'create'])->name('collection.create');
        Route::post('/',       [CollectionController::class, 'store'])->name('collection.store');

        // Show / delete / rename
        Route::get('{collection}',              [CollectionController::class, 'show'])->name('collection.show');
        Route::delete('{collection}',           [CollectionController::class, 'destroy'])->name('collection.destroy');
        Route::patch('{collection}/rename',     [CollectionController::class, 'rename'])->name('collection.rename');

        // Favorite toggle
        Route::post('/favorites/toggle', [FavoriteController::class, 'toggle'])->name('favorites.toggle');

        // Attach/Remove media
        Route::post('/attach-media',                [CollectionController::class, 'attachMedia'])->name('collection.attachMedia');
        Route::post('/{collection}/item/remove',    [CollectionController::class, 'removeItem'])->name('collection.item.remove');

        //  Random item picker
        Route::get('/collections/{collection}/random', [CollectionController::class, 'random'])
            ->name('collection.random');
    });

    Route::patch('/media/{media}/entry', [AnilistController::class, 'updateEntry'])->name('media.entry.update');
    Route::patch('/doujin/{media}/entry', [DoujinController::class, 'updateEntry'])->name('doujin.entry.update');
    // Episode & Chapter management
    Route::post('/media/{media}/episodes', [EpisodeController::class, 'syncFromDisk'])->name('episodes.sync');
    Route::get('/media/{media}/episodes/{episode}', [EpisodeController::class, 'show'])->name('episodes.show');

    Route::post('/media/{media}/chapters/sync', [ChapterController::class, 'syncFromDisk'])->name('chapters.sync');
    Route::get('/media/{media}/chapters/{chapter}/{page?}', [ChapterController::class, 'readPage'])->name('chapters.page');

    // VN tools + NSFW mark
    Route::post('/vn/{media}/nsfw',   [VndbController::class,     'markNsfw'])->name('vn.markNsfw');

    //sync with API
    Route::post('/anilist/sync', [AnilistController::class, 'syncFromAnilist'])->name('anilist.sync');
    Route::post('/vndb/sync',    [VndbController::class,    'syncFromVndb'])->name('vndb.sync');
    Route::post('/doujin/sync', [DoujinController::class, 'syncAll'])->name('doujin.sync');
});
