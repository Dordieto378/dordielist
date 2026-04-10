<?php
// app/Http/Controllers/Auth/RegisterController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AdminNewUserNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RegisterController extends Controller
{
    public function show()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $rules = [
            'email'                 => ['required', 'email', 'max:255', 'unique:users,email'],
            'username'              => ['required', 'string', 'max:50', 'unique:users,username'],
            'password'              => [
                'required',
                'string',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{15,}$/',
                'confirmed',
            ],
            'password_confirmation' => ['required', 'string'],
            'terms'                 => ['accepted'],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $firstError = $validator->errors()->first();

            return back()
                ->with('status', $firstError)
                ->with('status_color', 'red')
                ->withInput();
        }

        $data = $validator->validated();

        if (
            ! isset($data['email']) ||
            ! isset($data['username']) ||
            ! isset($data['password']) ||
            ! isset($data['password_confirmation']) ||
            ! isset($data['terms'])
        ) {
            return back()
                ->with('status', 'Registration failed: missing required fields.')
                ->with('status_color', 'red')
                ->withInput();
        }

        $user = User::create([
            'email'     => $data['email'],
            'username'  => $data['username'],
            'password'  => $data['password'],
            'role_id'   => 2,
            'status'    => 'not_active',
        ]);

        try {
            $confirmUrl = URL::temporarySignedRoute(
                'admin.confirm',
                now()->addDays(7),
                ['user' => $user->user_id]
            );

            Mail::to('joshua.esser378@gmail.com')
                ->send(new AdminNewUserNotification($user, $confirmUrl));
        } catch (Throwable $exception) {
            Log::error('Registration completed, but admin notification failed.', [
                'user_id' => $user->user_id,
                'email' => $user->email,
                'exception' => $exception,
            ]);
        }

        return view('auth.register_pending', [
            'username' => $user->username,
        ]);
    }
}
