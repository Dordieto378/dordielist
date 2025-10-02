<?php
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
        $request->validate([
            'email'    => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = \App\Models\User::where('email', $request->email)->first();

        if ($user && $user->status !== 'active') {
            return back()
                ->with('status', 'Your account is not active yet. Please wait for confirmation')
                ->with('status_color', 'red')
                ->withInput();
        }

        if (Auth::attempt($request->only('email','password'), $request->filled('remember'))) {
            return redirect()->intended('/');
        }

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
        if ($user->two_factor_secret) {
            Auth::logout();
            $request->session()->put('login.id', $user->id);
            return redirect()->route('two_factor.login');
        }
    }
}
