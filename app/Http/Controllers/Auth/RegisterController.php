<?php
// app/Http/Controllers/Auth/RegisterController.php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AdminNewUserNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class RegisterController extends Controller
{
    /**
     * Show the registration form.
     */
    public function show()
    {
        return view('auth.register');
    }

    /**
     * Handle the form submission.
     * Create the user with status = 'not_active' and email the admin.
     */
    public function register(Request $request)
    {
        // 1) Define validation rules
        $rules = [
            'email'                 => ['required', 'email', 'max:255', 'unique:users,email'],
            'username'              => ['required', 'string', 'max:50', 'unique:users,username'],
            'password'              => [
                'required',
                'string',
                // at least 15 chars, 1 uppercase, 1 lowercase, 1 digit, 1 special
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{15,}$/',
                'confirmed',
            ],
            'password_confirmation' => ['required', 'string'], // confirm must be present
            'terms'                 => ['accepted'],
        ];

        // 2) Run validator
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            // Grab the first validation error and redirect back with one red flash
            $firstError = $validator->errors()->first();

            return back()
                ->with('status', $firstError)
                ->with('status_color', 'red')
                ->withInput();
        }

        // 3) At this point, validation passed ↓
        $data = $validator->validated();

        // Double-check that none of the keys are missing:
        if (
            ! isset($data['email']) ||
            ! isset($data['username']) ||
            ! isset($data['password']) ||
            ! isset($data['password_confirmation']) ||
            ! isset($data['terms'])
        ) {
            // If anything is missing, bail out gracefully
            return back()
                ->with('status', 'Registration failed: missing required fields.')
                ->with('status_color', 'red')
                ->withInput();
        }

        // 4) Create the user as not_active
        $user = User::create([
            'email'     => $data['email'],
            'username'  => $data['username'],
            'password'  => $data['password'], // Model mutator bcrypts this
            'role_id'   => 2,
            'status'    => 'not_active',
        ]);

        // 5) Generate a signed URL for *you* (the admin) to click
        $confirmUrl = URL::temporarySignedRoute(
            'admin.confirm',            // name of the route
            now()->addDays(7),          // valid for 7 days
            ['user' => $user->user_id]  // route parameter
        );

        // 6) Send email TO YOU (admin) only
        Mail::to('joshua.esser378@gmail.com')
            ->send(new AdminNewUserNotification($user, $confirmUrl));

        // 7) Redirect the new user to “pending” page with a green flash
        return redirect()
            ->route('register.pending')
            ->with('status', 'Registration received! An administrator will activate your account soon.')
            ->with('status_color', 'green');
    }
}
