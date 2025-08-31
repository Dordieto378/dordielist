<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController as FortifyController;

class TwoFactorDisableController extends Controller
{
    public function destroy(Request $request)
    {
        $user = $request->user();

        // call Fortify’s native logic to clear secret & codes
        app(FortifyController::class)->destroy($request);

        // now reset your custom flag
        $user->forceFill(['two_factor_confirmed' => false])->save();

        return back()->with('status', 'two-factor-authentication-disabled');
    }
}
