<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Role;

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
        abort_unless(optional(Auth::user()->role)->role === 'Admin', 403);

        $users = User::with('role')->get();
        $roles = Role::orderBy('role')->get();

        return view('settings.users', compact('users', 'roles'));
    }

    public function updateManagedUser(Request $request, User $user)
    {
        abort_unless(optional(Auth::user()->role)->role === 'Admin', 403);

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
            'role_id' => ['required', 'integer', 'exists:roles,role_id'],
            'status'  => ['required', Rule::in(['active', 'not_active', 'banned'])],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()
                ->route('settings.users')
                ->withInput()
                ->with('open_edit_user_id', $user->user_id)
                ->with('status', $validator->errors()->first())
                ->with('status_color', 'red');
        }

        $data = $validator->validated();

        $user->username = $data['username'];
        $user->email    = $data['email'];
        $user->role_id  = (int) $data['role_id'];
        $user->status   = $data['status'];
        $user->save();

        return redirect()
            ->route('settings.users')
            ->with('status', 'User updated successfully.')
            ->with('status_color', 'green');
    }

    public function updateList()
    {
        return view('settings.update');
    }
}
