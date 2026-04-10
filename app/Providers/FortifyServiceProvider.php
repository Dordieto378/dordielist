<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        // Fortify::ignoreRoutes();
        Fortify::loginView(fn() => view('auth.login'));
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->email)->first();

            if ($user && $user->status === 'banned') {
                throw ValidationException::withMessages([
                    Fortify::username() => 'This account has been banned.',
                ]);
            }

            if ($user && $user->status !== 'active') {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Your account is not active yet. Please wait for confirmation.',
                ]);
            }

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return null;
            }

            return $user;
        });
        Fortify::twoFactorChallengeView(fn() => view('auth.two_factor_challenge'));
        Fortify::twoFactorChallengeView(function () {
            return view('auth.two_factor_challenge');
        });
        Fortify::confirmPasswordView(function () {
            return view('auth.confirm_password');
         });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two_factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
