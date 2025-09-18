<?php
// app/Http/Controllers/AdminController.php

namespace App\Http\Controllers;

use App\Mail\UserActivationNotification;
use App\Mail\AdminNewUserNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;

class AdminController extends Controller
{
    public function confirm(Request $request, User $user)
    {
        if ($user->status !== 'not_active') {
            return view('admin.user_confirmed', [
                'message' => 'This account is already active or was never pending confirmation.'
            ]);
        }

        $user->status = 'active';
        $user->email_verified_at = Carbon::now();
        $user->save();

        Mail::to($user->email)
            ->send(new UserActivationNotification($user));

        return view('admin.user_confirmed', [
            'user' => $user
        ]);

    }
}
