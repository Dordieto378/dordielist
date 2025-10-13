<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Actions\ConfirmEnableTwoFactorAuthentication;

class ConfirmTwoFactorAuthenticationController extends Controller
{
    public function store(Request $request, ConfirmEnableTwoFactorAuthentication $confirm)
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        if (! $confirm($request->user(), $request->code)) {
            return back()->withErrors(['code' => __('The provided code is invalid.')]);
        }

        $user = $request->user();
        $user->two_factor_confirmed = true;
        $user->two_factor_confirmed_at = now();
        $user->save();

        return back()->with('status', 'two-factor-authentication-confirmed');
    }
}
