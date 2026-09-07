<?php

namespace Tests\Feature;

use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoujinEntryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_add_doujin_popup_renders_repeatable_new_author_controls(): void
    {
        $this
            ->actingAs($this->manager())
            ->get(route('category', ['category' => 'doujins']))
            ->assertOk()
            ->assertSee('name="new_author[]"', false)
            ->assertSee('data-add-new-author', false)
            ->assertSee('data-remove-new-author', false);
    }

    public function test_uploaded_doujin_can_attach_multiple_new_authors(): void
    {
        Storage::fake('public');

        $title = 'Upload Multi '.Str::lower(Str::random(8));
        $firstAuthor = 'Upload Artist One '.Str::lower(Str::random(8));
        $secondAuthor = 'Upload Artist Two '.Str::lower(Str::random(8));
        $zipPath = $this->makeDoujinZip();
        $this->beforeApplicationDestroyed(fn () => @unlink($zipPath));

        $this
            ->actingAs($this->manager())
            ->post(route('doujin.upload'), [
                'title_english' => $title,
                'existing_author' => '',
                'new_author' => [$firstAuthor, $secondAuthor, ''],
                'archive' => new UploadedFile($zipPath, 'doujin.zip', 'application/zip', null, true),
            ])
            ->assertStatus(302);

        $media = Media::query()
            ->where('type', 'doujin')
            ->where('title_english', $title)
            ->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [$firstAuthor, $secondAuthor],
            $media->doujinAuthors()->pluck('name')->all()
        );
        Storage::disk('public')->assertExists('doujin/'.$media->id.'/001/001.jpg');
    }

    public function test_doujin_entry_edit_can_rename_selected_author(): void
    {
        $author = $this->author('Entry Rename Source');
        $media = $this->doujin('Entry Rename Doujin');
        $media->doujinAuthors()->attach($author->id);

        $newName = 'Entry Rename Target '.Str::lower(Str::random(8));

        $this
            ->actingAs($this->manager())
            ->patch(route('doujin.entry.update', ['media' => $media]), [
                'title_english' => 'Entry Rename Updated',
                'author' => $author->name,
                'author_name' => $newName,
                'author_twitter_url' => ['https://twitter.example/renamed'],
                'author_patreon_url' => [''],
                'author_fanbox_url' => [''],
                'author_pixiv_url' => [''],
            ])
            ->assertRedirect();

        $author->refresh();
        $media->refresh();

        $this->assertSame($newName, $author->name);
        $this->assertSame(Str::slug($newName), $author->slug);
        $this->assertSame('https://twitter.example/renamed', $author->twitter_url);
        $this->assertSame('Entry Rename Updated', $media->title_english);
        $this->assertDatabaseHas('doujin_item_author', [
            'media_id' => $media->id,
            'author_id' => $author->id,
        ]);
    }

    private function makeDoujinZip(): string
    {
        $directory = storage_path('framework/testing');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/doujin-'.Str::uuid().'.zip';
        $fixture = 'UEsDBBQAAAAIAIlMJ11fBD3FBwAAAAUAAAALAAAAMDAxXDAwMS5qcGfLzE1MTwUAUEsBAhQAFAAAAAgAiUwnXV8EPcUHAAAABQAAAAsAAAAAAAAAAAAAAAAAAAAAADAwMVwwMDEuanBnUEsFBgAAAAABAAEAOQAAADAAAAAAAA==';

        file_put_contents($path, base64_decode($fixture, true));

        return $path;
    }

    private function manager(): User
    {
        $role = Role::where('role', 'Admin')->first()
            ?? Role::create(['role' => 'Admin']);

        return User::create([
            'username' => 'doujin-entry-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);
    }

    private function author(string $name): DoujinAuthor
    {
        $name = $name.' '.Str::lower(Str::random(8));

        return DoujinAuthor::create([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    private function doujin(string $title): Media
    {
        return Media::create([
            'type' => 'doujin',
            'title_english' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
        ]);
    }
}
