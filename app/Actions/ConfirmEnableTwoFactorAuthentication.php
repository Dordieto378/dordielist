<?php

namespace App\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PragmaRX\Google2FA\Google2FA;
use Laravel\Fortify\RecoveryCode;

class ConfirmEnableTwoFactorAuthentication
{
    public function __invoke($user, string $code): bool
    {
        $google2fa = new Google2FA;

        if (! $google2fa->verifyKey(decrypt($user->two_factor_secret), $code)) {
            return false;
        }

        $user->forceFill(['two_factor_confirmed' => true])->save();

        return true;
    }
}
