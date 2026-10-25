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

    public function test_add_doujin_popup_accepts_author_folders_and_previews_a_batch(): void
    {
        $this
            ->actingAs($this->manager())
            ->get(route('category', ['category' => 'doujins']))
            ->assertOk()
            ->assertSee('id="doujinFolderInput"', false)
            ->assertSee('webkitdirectory', false)
            ->assertSee('id="doujinImportFiles"', false)
            ->assertDontSee('name="new_author[]"', false);
    }

    public function test_uploaded_doujin_rejects_multiple_new_authors(): void
    {
        Storage::fake('public');

        $title = 'Upload Multi '.Str::lower(Str::random(8));
        $firstAuthor = 'Upload Artist One '.Str::lower(Str::random(8));
        $secondAuthor = 'Upload Artist Two '.Str::lower(Str::random(8));
        $zipPath = $this->makeDoujinZip();
        $this->beforeApplicationDestroyed(fn () => @unlink($zipPath));

        $this
            ->actingAs($this->manager())
            ->postJson(route('doujin.upload'), [
                'title_english' => $title,
                'existing_author' => '',
                'new_author' => [$firstAuthor, $secondAuthor, ''],
                'archive' => new UploadedFile($zipPath, 'doujin.zip', 'application/zip', null, true),
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('media', ['type' => 'doujin', 'title_english' => $title]);
    }

    public function test_uploaded_doujin_reuses_one_author_without_splitting_the_name(): void
    {
        Storage::fake('public');
        $author = $this->author('Upload Artist, Partner and Friend');
        $author->update(['twitter_url' => 'https://example.test/original-social']);
        $title = 'Upload Single '.Str::lower(Str::random(8));
        $zipPath = $this->makeDoujinZip();
        $this->beforeApplicationDestroyed(fn () => @unlink($zipPath));

        $this->actingAs($this->manager())
            ->post(route('doujin.upload'), [
                'title_english' => $title,
                'existing_author' => $author->name,
                'author_twitter_url' => ['https://example.test/ignored-upload-social'],
                'archive' => new UploadedFile($zipPath, 'doujin.zip', 'application/zip', null, true),
            ])
            ->assertRedirect();

        $media = Media::query()
            ->where('type', 'doujin')
            ->where('title_english', $title)
            ->firstOrFail();

        $this->assertSame([$author->id], $media->doujinAuthors->modelKeys());
        $this->assertSame('https://example.test/original-social', $author->fresh()->twitter_url);
        Storage::disk('public')->assertExists('doujin/'.$media->id.'/001/001.jpg');
    }

    public function test_doujin_entry_edit_changes_its_author_without_editing_artist_details(): void
    {
        $author = $this->author('Original Entry Artist');
        $target = $this->author('New Entry Artist');
        $target->update(['twitter_url' => 'https://example.test/existing-social']);
        $media = $this->doujin('Edit Doujin');
        $media->doujinAuthors()->attach($author->id);

        $this
            ->actingAs($this->manager())
            ->patch(route('doujin.entry.update', ['media' => $media]), [
                'title_english' => 'Edited Doujin Title',
                'author' => $target->name,
                'author_name' => 'Ignored Artist Rename',
                'author_twitter_url' => ['https://twitter.example/renamed'],
                'author_patreon_url' => [''],
                'author_fanbox_url' => [''],
                'author_pixiv_url' => [''],
            ])
            ->assertRedirect();

        $author->refresh();
        $media->refresh();

        $this->assertSame('Edited Doujin Title', $media->title_english);
        $this->assertSame([$target->id], $media->doujinAuthors->modelKeys());
        $this->assertSame($target->name, $target->fresh()->name);
        $this->assertSame('https://example.test/existing-social', $target->fresh()->twitter_url);
        $this->assertDatabaseMissing('doujin_authors', ['name' => 'Ignored Artist Rename']);
    }

    public function test_doujin_entry_edit_can_add_or_reuse_a_new_author_name(): void
    {
        $this->actingAs($this->manager());
        $media = $this->doujin('Entry With New Author');
        $newName = 'Created From Edit '.Str::random(8);
        $this->patch(route('doujin.entry.update', $media), [
            'title_english' => $media->title_english,
            'new_author' => $newName,
        ])->assertRedirect();

        $author = DoujinAuthor::where('name', $newName)->firstOrFail();
        $this->assertSame([$author->id], $media->fresh()->doujinAuthors->modelKeys());
        $second = $this->doujin('Another Entry With New Author');
        $this->patch(route('doujin.entry.update', $second), [
            'title_english' => $second->title_english,
            'new_author' => $newName,
        ])->assertRedirect();
        $this->assertSame([$author->id], $second->fresh()->doujinAuthors->modelKeys());
        $this->assertSame(1, DoujinAuthor::where('name', $newName)->count());
    }

    public function test_edit_doujin_popup_only_contains_title_and_author_fields(): void
    {
        $author = $this->author('Simple Entry Artist');
        $media = $this->doujin('Simple Entry');
        $media->doujinAuthors()->attach($author);
        $this->actingAs($this->manager())->get(route('doujins.show', $media))
            ->assertOk()
            ->assertSee('name="title_english"', false)
            ->assertSee('name="new_author"', false)
            ->assertSee('name="author"', false)
            ->assertDontSee('name="author_name"', false)
            ->assertDontSee('data-author-link-group', false);
    }

    public function test_doujin_entry_edit_cannot_clear_its_only_author(): void
    {
        $author = $this->author('Required Entry Artist');
        $media = $this->doujin('Required Author Entry');
        $media->doujinAuthors()->attach($author);
        $this->actingAs($this->manager())->patch(route('doujin.entry.update', $media), [
            'title_english' => 'Should Not Save',
            'author' => '',
            'new_author' => '',
        ])->assertRedirect()->assertSessionHas('doujin_update_error');
        $this->assertSame('Required Author Entry', $media->fresh()->title_english);
        $this->assertSame([$author->id], $media->fresh()->doujinAuthors->modelKeys());
    }

    public function test_folder_upload_reuses_one_existing_author_for_multiple_doujins(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs($this->manager());
        $first = $this->author('Folder Artist One');
        $first->update(['twitter_url' => 'https://example.test/artist-one']);
        $soloTitle = 'Solo '.Str::random(8);
        $sharedTitle = 'Shared '.Str::random(8);

        $soloResponse = $this->uploadFolderZip('Collection/'.$first->name.'/'.$soloTitle.'.zip')
            ->assertOk()->assertJsonPath('skipped', false);
        $sharedResponse = $this->uploadFolderZip('Collection/'.$first->name.'/'.$sharedTitle.'.zip')
            ->assertOk()->assertJsonPath('skipped', false);

        $solo = Media::findOrFail($soloResponse->json('media_id'));
        $shared = Media::findOrFail($sharedResponse->json('media_id'));
        $this->assertSame($soloTitle, $solo->title_english);
        $this->assertSame($sharedTitle, $shared->title_english);
        $this->assertSame([$first->id], $solo->doujinAuthors->modelKeys());
        $this->assertSame([$first->id], $shared->doujinAuthors->modelKeys());
        $this->assertSame(1, DoujinAuthor::where('name', $first->name)->count());
        $this->assertSame('https://example.test/artist-one', $first->fresh()->twitter_url);
        $this->assertSame('doujin/'.$shared->id.'/001/001.jpg', $shared->cover_url);
        Storage::disk('public')->assertExists($shared->cover_url);
        $this->assertSame([], Storage::disk('local')->allFiles('doujin-upload-chunks'));
    }

    public function test_reimporting_a_title_reuses_its_single_author_without_duplicating_the_doujin(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs($this->manager());
        $first = $this->author('Existing Folder Artist');
        $title = 'Existing Folder Title '.Str::random(8);
        $existing = $this->doujin($title);
        $existing->doujinAuthors()->attach($first);

        $this->uploadFolderZip('Collection/'.$first->name.'/'.$title.'.zip')
            ->assertOk()
            ->assertJsonPath('skipped', true)
            ->assertJsonPath('media_id', $existing->id);

        $this->assertSame(1, Media::where('type', 'doujin')->where('title_english', $title)->count());
        $this->assertSame([$first->id], $existing->fresh()->doujinAuthors->modelKeys());
    }

    public function test_folder_names_with_and_are_stored_as_one_author(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs($this->manager());
        $separateAuthor = $this->author('Artist One');
        $folderName = $separateAuthor->name.' and Artist Two';
        $title = 'Single Folder Name '.Str::random(8);

        $response = $this->uploadFolderZip('Collection/'.$folderName.'/'.$title.'.zip')->assertOk();
        $media = Media::findOrFail($response->json('media_id'));

        $this->assertSame([$folderName], $media->doujinAuthors->pluck('name')->all());
        $this->assertNotContains($separateAuthor->id, $media->doujinAuthors->modelKeys());
    }

    public function test_folder_upload_rejects_invalid_paths_without_creating_doujins(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs($this->manager());
        $before = Media::where('type', 'doujin')->count();

        $this->uploadFolderZip('Collection/../Unsafe.zip')->assertStatus(422);
        $this->uploadFolderZip('Collection/MissingAuthor.zip')->assertStatus(422);
        $this->uploadFolderZip('Collection/Artist/Extra/Too Deep.zip')->assertStatus(422);

        $this->assertSame($before, Media::where('type', 'doujin')->count());
    }

    public function test_folder_upload_accepts_zip_files_with_pages_directly_inside(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs($this->manager());
        $artist = 'Flat ZIP Artist '.Str::random(8);
        $title = 'Flat ZIP Title '.Str::random(8);

        $response = $this->uploadFolderZip('Collection/'.$artist.'/'.$title.'.zip', true)
            ->assertOk()
            ->assertJsonPath('skipped', false);

        $media = Media::findOrFail($response->json('media_id'));
        $this->assertSame('doujin/'.$media->id.'/001/001.jpg', $media->cover_url);
        $this->assertSame(1, (int) $media->chapters_cnt);
        Storage::disk('public')->assertExists($media->cover_url);
    }

    public function test_import_refreshes_an_existing_cover_using_chapter_and_page_order(): void
    {
        Storage::fake('public');
        $media = $this->doujin('Import Cover Order');
        $root = 'doujin/'.$media->id;
        $firstPage = $root.'/Chapter 1/001.jpg';
        $laterChapterPage = $root.'/Chaper 2.5/001.jpg';

        foreach ([$laterChapterPage, $root.'/Chapter 1/010.jpg', $firstPage] as $path) {
            Storage::disk('public')->put($path, 'image');
        }
        $media->update(['cover_url' => $laterChapterPage]);

        $this->artisan('doujin:import', [
            'mediaId' => $media->id,
            '--path' => $root,
        ])->assertSuccessful();

        $this->assertSame($firstPage, $media->fresh()->cover_url);
        Storage::disk('public')->assertExists($laterChapterPage);
    }

    public function test_sync_refreshes_an_existing_cover_from_chapter_zero(): void
    {
        Storage::fake('public');
        $media = $this->doujin('Sync Cover Order');
        $root = 'doujin/'.$media->id;
        $firstPage = $root.'/Chapter 0/001.jpg';
        $laterChapterPage = $root.'/Chapte 1/01.jpg';

        foreach ([$laterChapterPage, $root.'/Chapter 0/010.jpg', $firstPage] as $path) {
            Storage::disk('public')->put($path, 'image');
        }
        $media->update(['cover_url' => $laterChapterPage]);

        $this->actingAs($this->manager())
            ->post(route('doujin.sync'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Sync complete - created: 0, updated: 1');

        $this->assertSame($firstPage, $media->fresh()->cover_url);
        Storage::disk('public')->assertExists($laterChapterPage);
    }

    private function makeDoujinZip(bool $flat = false): string
    {
        $directory = storage_path('framework/testing');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/doujin-'.Str::uuid().'.zip';
        if ($flat) {
            $zip = new \PharData($path);
            $zip->addFromString('001.jpg', 'image');

            return $path;
        }

        $fixture = 'UEsDBBQAAAAIAIlMJ11fBD3FBwAAAAUAAAALAAAAMDAxXDAwMS5qcGfLzE1MTwUAUEsBAhQAFAAAAAgAiUwnXV8EPcUHAAAABQAAAAsAAAAAAAAAAAAAAAAAAAAAADAwMVwwMDEuanBnUEsFBgAAAAABAAEAOQAAADAAAAAAAA==';

        file_put_contents($path, base64_decode($fixture, true));

        return $path;
    }

    private function uploadFolderZip(string $relativePath, bool $flat = false): \Illuminate\Testing\TestResponse
    {
        $zipPath = $this->makeDoujinZip($flat);
        $this->beforeApplicationDestroyed(fn () => @unlink($zipPath));
        $uploadId = (string) Str::uuid();
        $chunks = str_split(file_get_contents($zipPath), 80);
        foreach ($chunks as $index => $chunk) {
            $this->postJson(route('doujin.upload.chunk'), [
                'upload_id' => $uploadId,
                'chunk_index' => $index,
                'total_chunks' => count($chunks),
                'archive_chunk' => UploadedFile::fake()->createWithContent('part'.$index, $chunk),
            ])->assertOk();
        }

        return $this->postJson(route('doujin.upload.complete'), [
            'upload_id' => $uploadId,
            'total_chunks' => count($chunks),
            'original_name' => basename($relativePath),
            'relative_path' => $relativePath,
        ]);
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
