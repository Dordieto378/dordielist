<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use App\Http\Controllers\ConfirmTwoFactorAuthenticationController;
use App\Models\Doujin;
use App\Http\Controllers\AnilistController;
use App\Http\Controllers\DoujinController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\VndbController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\ChapterController;
use App\Http\Controllers\TwoFactorDisableController;
use App\Http\Controllers\SearchController;

//Auth::routes();

Route::get('/', [AnilistController::class, 'home'])->name('home');

Route::get('/register', [RegisterController::class, 'show'])
     ->name('register');

Route::post('/register', [RegisterController::class, 'register'])
     ->name('register.post');

Route::get('/register/pending', function () {
    return view('auth.register_pending');
})->name('register.pending');

Route::get('/admin/confirm/{user}', [AdminController::class, 'confirm'])
     ->name('admin.confirm')
     ->middleware('signed');

Route::get('/login', [LoginController::class, 'showLoginForm'])
     ->name('login');

// Process login; blocks any user whose status !== 'active'
Route::get('/login',  [AuthenticatedSessionController::class, 'create'])
     ->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store']);
Route::post('/logout',[AuthenticatedSessionController::class, 'destroy'])
     ->name('logout');

Route::get('/two_factor_challenge', [TwoFactorAuthenticatedSessionController::class, 'create'])
     ->name('two-factor.login');
Route::post('/two_factor_challenge', [TwoFactorAuthenticatedSessionController::class, 'store']);

Route::get('/search', [SearchController::class, 'index'])->name('search.index');

Route::middleware('auth')->group(function () {
    // Enable 2FA
    Route::post('/user/two_factor_authentication', [
        TwoFactorAuthenticationController::class, 'store'
    ])->name('two-factor.enable');

    // Disable 2FA
    Route::delete('/user/two_factor_authentication', [
        TwoFactorAuthenticationController::class, 'destroy'
    ])->name('two-factor.disable');

    // Regenerate recovery codes
    Route::post('/user/two_factor_recovery_codes', [
        RecoveryCodeController::class, 'store'
    ])->name('two-factor.recovery-codes');
});

Route::middleware('auth')->delete(
    '/user/two-factor-authentication',
    [TwoFactorDisableController::class, 'destroy']
)->name('two-factor.disable');

Route::middleware('auth')->post(
     '/user/confirmed-two-factor-authentication',
     [ConfirmTwoFactorAuthenticationController::class, 'store']
)->name('two-factor.confirm');

Route::middleware('auth')->group(function () {
    Route::post(
        '/user/two-factor-authentication',
        [TwoFactorAuthenticationController::class, 'store']
    )->name('two-factor.enable');
});

Route::get('/home-paginated', [AnilistController::class, 'paginatedMedia'])->name('home.paginated');

Route::get('/doujin/{media}', [DoujinController::class, 'show'])
    ->name('doujins.show');

Route::get('/category/{category}/{listFilter?}/{mediaStatus?}/{titleOrder?}/{scoreOrder?}/{dateOrder?}', [CategoryController::class, 'show'])
    ->where('category', '(?i)(ANIMES|MANGAS|MANWHAS|HENTAIS|DOUJINS|VISUAL-NOVEL)')
    ->name('category');

Route::get('/media/{id}', [AnilistController::class, 'show'])->name('media.show');

Route::get('/vn/{id}', [VndbController::class, 'show'])->name('vn.show');

Route::get('/api/vndb-media', [VndbController::class, 'apiList']);

Route::prefix('vndb')->group(function () {
    Route::get('search', [VndbController::class, 'searchVN']);
    Route::get('vn/{id}',  [VndbController::class, 'getVNDetails']);
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::view('/settings/security', 'account.security')
         ->name('settings.security');
});

Route::middleware('auth')->prefix('collection')->group(function () {
    // List & home page for all collections (including Favorites)
     Route::get('/', [CollectionController::class, 'index'])
         ->name('collection.index');

    // Show “create new collection” form
     Route::get('create', [CollectionController::class, 'create'])
         ->name('collection.create');

    // Handle form‐submit to actually create it
     Route::post('/', [CollectionController::class, 'store'])
         ->name('collection.store');

    // Show one collection’s contents
     Route::get('{collection}', [CollectionController::class, 'show'])
         ->name('collection.show');

     Route::delete('{collection}', [CollectionController::class, 'destroy'])
         ->name('collection.destroy');

     Route::post('/favorites/toggle', [
     FavoriteController::class, 'toggle',
     ])->name('favorites.toggle');

     Route::post('attach-media', [CollectionController::class,'attachMedia'])
          ->name('collection.attachMedia');

     Route::post(
     'collection/{collection}/item/remove',
     [CollectionController::class, 'removeItem']
     )->name('collection.item.remove');

});

Route::middleware(['auth'])->prefix('settings')->group(function () {
    // 1) Profile (Edit) & Update
    Route::get('/', [SettingsController::class, 'edit'])
         ->name('settings.profile.edit');
    Route::put('/', [SettingsController::class, 'update'])
         ->name('settings.profile.update');

    // 2) Users
    Route::get('users', [SettingsController::class, 'users'])
         ->name('settings.users');

     Route::get('/settings/doujin/add', [SettingsController::class, 'addDoujin'])
          ->name('settings.addDoujin');

     Route::post('/settings/doujin/store', [SettingsController::class, 'storeDoujin'])
          ->name('settings.doujin.store');

     Route::delete('/settings/doujins/{author}/{title}', [SettingsController::class, 'destroy'])
          ->name('doujin.destroy');

     Route::patch('/settings/doujins/{author}/{title}', [SettingsController::class, 'rename'])
          ->name('doujin.rename');
});


Route::middleware(['auth'])->group(function () {

    Route::post('/media/{media}/episodes', [EpisodeController::class, 'syncFromDisk'])
        ->name('episodes.sync');


     Route::get('media/{media}/episodes/{episode}', [EpisodeController::class, 'show'])
     ->name('episodes.show');


    Route::get('/media/{media}/chapters/{chapter}/{page?}', [ChapterController::class, 'readPage'])
        ->name('chapters.page');

    Route::post('/media/{media}/chapters/sync', [ChapterController::class,'syncFromDisk'])
        ->name('chapters.sync');

});

