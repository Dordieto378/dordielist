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
    /**
     * Called when the admin clicks “Confirm & Activate User.”
     */
    public function confirm(Request $request, User $user)
    {
        // 1) If user is not “not_active,” show “already confirmed”
        if ($user->status !== 'not_active') {
            return view('admin.user_confirmed', [
                'message' => 'This account is already active or was never pending confirmation.'
            ]);
        }

        // 2) Activate the user
        $user->status = 'active';
        $user->email_verified_at = Carbon::now();
        $user->save();

        // 3) Send a notification to the user themself
        Mail::to($user->email)
            ->send(new UserActivationNotification($user));

        // 4) Return the “admin confirmation” view
        return view('admin.user_confirmed', [
            'user' => $user
        ]);

    }
}
