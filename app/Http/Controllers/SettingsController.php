<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class SettingsController extends Controller
{
    public function edit()
    {
        $user = Auth::user();
        return view('settings.account', compact('user'));
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        $rules = [
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user->user_id, 'user_id'),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->user_id, 'user_id'),
            ],
            'password' => [
                'nullable',
                'string',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{15,}$/',
                'confirmed',
            ],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $firstError = $validator->errors()->first();

            return back()
                ->with('status', $firstError)
                ->with('status_color', 'red');
        }

        $data = $validator->validated();

        $user->username = $data['username'];
        $user->email    = $data['email'];

        if (!empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return redirect()
            ->route('settings.profile.edit')
            ->with('status', 'Profile updated successfully.')
            ->with('status_color', 'green');
    }

    public function users()
    {
        $users = User::with('role')->get();

        return view('settings.users', compact('users'));
    }

    public function updateList()
    {
        return view('settings.update');
    }
}
