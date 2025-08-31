<?php
// app/Http/Controllers/Auth/LoginController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        // 1) Validate required input
        $request->validate([
            'email'    => ['required','email'],
            'password' => ['required','string'],
        ]);

        // 2) Fetch the user by email (if they exist)
        $user = \App\Models\User::where('email', $request->email)->first();

        // 3) If we found a user but status is not 'active', block them:
        if ($user && $user->status !== 'active') {
            return back()
                ->with('status', 'Your account is not active yet. Please wait for confirmation')
                ->with('status_color', 'red')
                ->withInput();
        }

        // 4) Only if status was 'active' (or user didn’t exist at all) do we attempt credentials:
        if (Auth::attempt($request->only('email','password'), $request->filled('remember'))) {
            return redirect()->intended('/');
        }

        // 5) If credentials are wrong, show “Invalid credentials.”
        return back()
            ->with('status', 'Invalid credentials.')
            ->with('status_color', 'red')
            ->withInput();
    }


    public function logout(Request $request)
    {
        Auth::logout();
        return redirect('/');
    }

    protected function authenticated(Request $request, $user)
    {
        // if user has 2FA enabled…
        if ($user->two_factor_secret) {
            // log them out and store their ID for the challenge
            Auth::logout();
            $request->session()->put('login.id', $user->id);
            return redirect()->route('two_factor.login');
        }
    }
}
