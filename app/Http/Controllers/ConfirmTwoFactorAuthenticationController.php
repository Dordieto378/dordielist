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

        return back();
    }
}
