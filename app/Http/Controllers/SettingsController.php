<?php

namespace App\Http\Controllers;

use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Support\DoujinAuthorLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'tmdb_api_token' => ['nullable', 'string'],
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
        $user->tmdb_api_token = $data['tmdb_api_token'] ?: null;
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

    public function doujinAuthors()
    {
        $this->abortIfViewer();

        $authors = DoujinAuthor::query()
            ->where(function ($query) {
                foreach (array_keys(DoujinAuthorLinks::PLATFORMS) as $column) {
                    $query->where(function ($fieldQuery) use ($column) {
                        $fieldQuery
                            ->whereNull($column)
                            ->orWhere($column, '');
                    });
                }
            })
            ->withCount([
                'media as doujin_count' => fn ($query) => $query->where('type', 'doujin'),
            ])
            ->orderBy('name')
            ->get();

        $socialPlatforms = DoujinAuthorLinks::PLATFORMS;

        return view('settings.doujin-authors', compact('authors', 'socialPlatforms'));
    }

    public function updateDoujinAuthor(Request $request, DoujinAuthor $author)
    {
        $this->abortIfViewer();

        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('doujin_authors', 'name')->ignore($author->id),
            ],
        ];

        foreach (array_keys(DoujinAuthorLinks::PLATFORMS) as $column) {
            $rules[$column] = ['nullable', 'string', 'max:12000'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()
                ->route('settings.doujin-authors')
                ->withInput()
                ->with('open_edit_author_id', $author->id)
                ->with('status', $validator->errors()->first())
                ->with('status_color', 'red');
        }

        $data = $validator->validated();
        $name = trim((string) $data['name']);

        if ($name === '') {
            return redirect()
                ->route('settings.doujin-authors')
                ->withInput()
                ->with('open_edit_author_id', $author->id)
                ->with('status', 'Add an artist name.')
                ->with('status_color', 'red');
        }

        $updates = [
            'name' => $name,
            'slug' => $this->makeUniqueDoujinAuthorSlug($author, $name),
        ];

        foreach (array_keys(DoujinAuthorLinks::PLATFORMS) as $column) {
            $updates[$column] = DoujinAuthorLinks::store($data[$column] ?? null);
        }

        $author->forceFill($updates)->save();

        return redirect()
            ->route('settings.doujin-authors')
            ->with('status', 'Artist saved.')
            ->with('status_color', 'green');
    }

    public function attachDoujinAuthor(Request $request, DoujinAuthor $author, Media $media)
    {
        $this->abortIfViewer();
        abort_unless($media->type === 'doujin', 404);
        abort_unless($media->doujinAuthors()->whereKey($author->id)->exists(), 404);

        $validator = Validator::make($request->all(), [
            'target_author_id' => ['nullable', 'integer', 'exists:doujin_authors,id'],
            'new_author' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->redirectDoujinAuthorError($validator->errors()->first());
        }

        $data = $validator->validated();
        $newAuthorName = trim((string) ($data['new_author'] ?? ''));
        $targetAuthorId = $data['target_author_id'] ?? null;

        if ($newAuthorName === '' && !$targetAuthorId) {
            return $this->redirectDoujinAuthorError('Choose an artist to move the doujin to.');
        }

        if ($newAuthorName !== '') {
            $targetAuthor = DoujinAuthor::firstOrCreate(['name' => $newAuthorName]);

            if (!$targetAuthor->slug) {
                $targetAuthor->slug = $this->makeUniqueDoujinAuthorSlug($targetAuthor, $newAuthorName);
                $targetAuthor->save();
            }
        } else {
            $targetAuthor = DoujinAuthor::findOrFail((int) $targetAuthorId);
        }

        if ((int) $targetAuthor->id === (int) $author->id) {
            return $this->redirectDoujinAuthorError('That artist is already attached to this doujin.');
        }

        if ($media->doujinAuthors()->whereKey($targetAuthor->id)->exists()) {
            return $this->redirectDoujinAuthorError('That artist is already attached to this doujin.');
        }

        $media->doujinAuthors()->syncWithoutDetaching([$targetAuthor->id]);

        return redirect()
            ->route('settings.doujin-authors')
            ->with('status', $targetAuthor->name.' added to the doujin.')
            ->with('status_color', 'green');
    }

    public function detachDoujinAuthor(DoujinAuthor $author, Media $media)
    {
        $this->abortIfViewer();
        abort_unless($media->type === 'doujin', 404);
        abort_unless($media->doujinAuthors()->whereKey($author->id)->exists(), 404);

        if ($media->doujinAuthors()->count() <= 1) {
            return $this->redirectDoujinAuthorError('Add another artist before removing the only artist.');
        }

        $media->doujinAuthors()->detach($author->id);

        return redirect()
            ->route('settings.doujin-authors')
            ->with('status', $author->name.' removed from the doujin.')
            ->with('status_color', 'green');
    }

    public function destroyDoujinAuthor(Request $request, DoujinAuthor $author)
    {
        $this->abortIfViewer();

        $validator = Validator::make($request->all(), [
            'confirm_author_id' => ['required', Rule::in([(string) $author->id])],
        ]);

        if ($validator->fails()) {
            return $this->redirectDoujinAuthorError('Could not confirm the artist delete.');
        }

        $doujinCount = $author->media()
            ->where('type', 'doujin')
            ->count();

        if ($doujinCount > 0) {
            return $this->redirectDoujinAuthorError('Remove this artist from all doujins before deleting the artist record.');
        }

        $name = $author->name;
        $author->delete();

        return redirect()
            ->route('settings.doujin-authors')
            ->with('status', $name.' deleted.')
            ->with('status_color', 'green');
    }

    private function redirectDoujinAuthorError(string $message)
    {
        return redirect()
            ->route('settings.doujin-authors')
            ->withInput()
            ->with('status', $message)
            ->with('status_color', 'red');
    }

    private function makeUniqueDoujinAuthorSlug(DoujinAuthor $author, string $name): ?string
    {
        $base = Str::slug($name);

        if ($base === '') {
            return null;
        }

        $slug = $base;
        $index = 2;

        while (
            DoujinAuthor::query()
                ->where('slug', $slug)
                ->when($author->exists, fn ($query) => $query->whereKeyNot($author->getKey()))
                ->exists()
        ) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }

}
