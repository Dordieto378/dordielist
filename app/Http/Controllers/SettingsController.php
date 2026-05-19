<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use App\Models\DoujinTag;
use App\Models\User;
use App\Models\Role;

class SettingsController extends Controller
{
    private function abortIfViewer(): void
    {
        abort_if(optional(Auth::user()?->role)->role === 'Viewer', 403);
    }

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
                ->withInput()
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
            ->route('settings.profile.edit');
    }

    public function api()
    {
        $this->abortIfViewer();

        $user = Auth::user();

        return view('settings.api', compact('user'));
    }

    public function updateApi(Request $request)
    {
        $this->abortIfViewer();

        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'anilist_access_token' => ['nullable', 'string'],
            'vndb_api_token' => ['nullable', 'string'],
            'vndb_username' => ['nullable', 'string', 'max:255'],
            'vndb_password' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return back()
                ->withInput()
                ->with('status', $validator->errors()->first())
                ->with('status_color', 'red');
        }

        $data = $validator->validated();

        $user->anilist_access_token = $data['anilist_access_token'] ?: null;
        $user->vndb_api_token = $data['vndb_api_token'] ?: null;
        $user->vndb_username = $data['vndb_username'] ?: null;
        $user->vndb_password = $data['vndb_password'] ?: null;
        $user->save();

        return redirect()
            ->route('settings.api');
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
            ->route('settings.users');
    }

    public function doujinTags(Request $request)
    {
        $this->abortIfViewer();

        $query = trim((string) $request->query('q', ''));

        $tags = DoujinTag::query()
            ->withCount('media')
            ->when($query !== '', fn ($builder) => $builder->where('name', 'like', '%'.$query.'%'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('settings.doujin-tags', [
            'tags' => $tags,
            'query' => $query,
        ]);
    }

    public function storeDoujinTag(Request $request)
    {
        $this->abortIfViewer();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('settings.doujin-tags')
                ->withInput()
                ->with('status', $validator->errors()->first())
                ->with('status_color', 'red');
        }

        $names = $this->parseDoujinTagNames($validator->validated()['name']);

        if ($names === []) {
            return redirect()
                ->route('settings.doujin-tags')
                ->withInput()
                ->with('status', 'Add at least one tag name.')
                ->with('status_color', 'red');
        }

        $tooLongName = collect($names)->first(fn (string $name) => mb_strlen($name) > 255);

        if ($tooLongName !== null) {
            return redirect()
                ->route('settings.doujin-tags')
                ->withInput()
                ->with('status', "Tag names must not be longer than 255 characters: {$tooLongName}")
                ->with('status_color', 'red');
        }

        $existingNames = DoujinTag::query()
            ->whereIn('name', $names)
            ->pluck('name')
            ->map(fn (string $name) => mb_strtolower($name))
            ->all();

        $existingLookup = array_flip($existingNames);
        $created = 0;

        foreach ($names as $name) {
            if (isset($existingLookup[mb_strtolower($name)])) {
                continue;
            }

            DoujinTag::create([
                'name' => $name,
                'slug' => $this->makeUniqueDoujinTagSlug($name),
            ]);

            $created++;
        }

        if ($created === 0) {
            return redirect()
                ->route('settings.doujin-tags')
                ->with('status', 'Those doujin tags already exist.')
                ->with('status_color', 'red');
        }

        $message = $created === 1
            ? 'Doujin tag added.'
            : "{$created} doujin tags added.";

        return redirect()
            ->route('settings.doujin-tags')
            ->with('status', $message)
            ->with('status_color', 'green');
    }

    public function updateDoujinTag(Request $request, DoujinTag $doujinTag)
    {
        $this->abortIfViewer();
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('doujin_tags', 'name')->ignore($doujinTag->id),
            ],
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('settings.doujin-tags', $request->only('q', 'page'))
                ->withInput()
                ->with('editing_tag_id', $doujinTag->id)
                ->with('status', $validator->errors()->first())
                ->with('status_color', 'red');
        }

        $name = trim($validator->validated()['name']);

        $doujinTag->name = $name;
        $doujinTag->slug = $this->makeUniqueDoujinTagSlug($name, $doujinTag);
        $doujinTag->save();

        return redirect()
            ->route('settings.doujin-tags', $request->only('q', 'page'))
            ->with('status', 'Doujin tag updated.')
            ->with('status_color', 'green');
    }

    public function destroyDoujinTag(Request $request, DoujinTag $doujinTag)
    {
        $this->abortIfViewer();

        $doujinTag->delete();

        return redirect()
            ->route('settings.doujin-tags', $request->only('q', 'page'))
            ->with('status', 'Doujin tag deleted.')
            ->with('status_color', 'green');
    }

    private function makeUniqueDoujinTagSlug(string $name, ?DoujinTag $tag = null): ?string
    {
        $base = Str::slug($name);
        if ($base === '') {
            return null;
        }

        $slug = $base;
        $index = 2;

        while (
            DoujinTag::query()
                ->where('slug', $slug)
                ->when($tag?->exists, fn ($query) => $query->whereKeyNot($tag->getKey()))
                ->exists()
        ) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }

    private function parseDoujinTagNames(string $value): array
    {
        return collect(explode(',', $value))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values()
            ->all();
    }

}
