<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\MediaArchive;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaArchiveTest extends TestCase
{
    use DatabaseTransactions;

    private array $temporaryArchives = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryArchives as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_admin_can_store_and_download_an_anime_zip_without_extracting_it(): void
    {
        Storage::fake('local');
        [$admin, $media] = $this->adminAndMedia('anime');

        $response = $this
            ->actingAs($admin)
            ->post(route('media-archives.store', $media), [
                'archive' => $this->zipUpload('anime-videos.zip'),
            ]);

        $response
            ->assertRedirect();
        $this->assertNull(
            session('media_content_upload_error'),
            (string) session('media_content_upload_error')
        );

        $archive = MediaArchive::where('media_id', $media->id)->firstOrFail();
        Storage::disk('local')->assertExists($archive->file_path);
        $this->assertSame('anime-videos.zip', $archive->original_name);
        $this->assertStringStartsWith('media-archives/anime/', $archive->file_path);

        $this
            ->actingAs($admin)
            ->get(route('media.show', $media))
            ->assertOk()
            ->assertSee('Stored video archive')
            ->assertSee('anime-videos.zip')
            ->assertDontSee('<video', false);

        $this
            ->actingAs($admin)
            ->get(route('media-archives.download', $media))
            ->assertOk()
            ->assertDownload('anime-videos.zip');
    }

    public function test_replacing_a_stored_zip_removes_the_previous_file(): void
    {
        Storage::fake('local');
        [$admin, $media] = $this->adminAndMedia('hentai');

        $this
            ->actingAs($admin)
            ->post(route('media-archives.store', $media), [
                'archive' => $this->zipUpload('first.zip'),
            ])
            ->assertRedirect();
        $this->assertNull(
            session('media_content_upload_error'),
            (string) session('media_content_upload_error')
        );

        $firstPath = MediaArchive::where('media_id', $media->id)->value('file_path');

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.store', $media), [
                'archive' => $this->zipUpload('second.zip'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('can_replace', true);

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.store', $media), [
                'archive' => $this->zipUpload('second.zip'),
                'replace_existing' => true,
            ])
            ->assertOk();

        $archive = MediaArchive::where('media_id', $media->id)->firstOrFail();
        $this->assertSame('second.zip', $archive->original_name);
        $this->assertSame(1, MediaArchive::where('media_id', $media->id)->count());
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($archive->file_path);
    }

    public function test_chunked_zip_upload_is_assembled_and_stored(): void
    {
        Storage::fake('local');
        [$admin, $media] = $this->adminAndMedia('anime');
        $uploadId = 'test-upload-session';

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.upload.chunk', $media), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'archive_chunk' => $this->zipUpload('chunk.part'),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.upload.complete', $media), [
                'upload_id' => $uploadId,
                'total_chunks' => 1,
                'original_name' => 'chunked-videos.zip',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $archive = MediaArchive::where('media_id', $media->id)->firstOrFail();
        $this->assertSame('chunked-videos.zip', $archive->original_name);
        Storage::disk('local')->assertExists($archive->file_path);
        Storage::disk('local')->assertMissing(
            'media-upload-chunks/archives/'.$media->id.'/'.$uploadId
        );
    }

    public function test_invalid_chunked_upload_is_rejected_and_cleaned_up(): void
    {
        Storage::fake('local');
        [$admin, $media] = $this->adminAndMedia('anime');
        $uploadId = 'invalid-upload-session';

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.upload.chunk', $media), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'archive_chunk' => UploadedFile::fake()->createWithContent('chunk.part', 'not a zip'),
            ])
            ->assertOk();

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.upload.complete', $media), [
                'upload_id' => $uploadId,
                'total_chunks' => 1,
                'original_name' => 'invalid.zip',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The uploaded file is not a valid ZIP archive.');

        $this->assertDatabaseMissing('media_archives', ['media_id' => $media->id]);
        Storage::disk('local')->assertMissing(
            'media-upload-chunks/archives/'.$media->id.'/'.$uploadId
        );
    }

    public function test_chunked_replacement_keeps_chunks_until_confirmation(): void
    {
        Storage::fake('local');
        [$admin, $media] = $this->adminAndMedia('anime');
        $uploadId = 'replacement-upload-session';

        $this
            ->actingAs($admin)
            ->post(route('media-archives.store', $media), [
                'archive' => $this->zipUpload('current.zip'),
            ])
            ->assertRedirect();

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.upload.chunk', $media), [
                'upload_id' => $uploadId,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'archive_chunk' => $this->zipUpload('chunk.part'),
            ])
            ->assertOk();

        $completePayload = [
            'upload_id' => $uploadId,
            'total_chunks' => 1,
            'original_name' => 'replacement.zip',
        ];

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.upload.complete', $media), $completePayload)
            ->assertStatus(409)
            ->assertJsonPath('can_replace', true);

        Storage::disk('local')->assertExists(
            'media-upload-chunks/archives/'.$media->id.'/'.$uploadId.'/chunk-000000.part'
        );
        $this->assertSame(
            'current.zip',
            MediaArchive::where('media_id', $media->id)->value('original_name')
        );

        $this
            ->actingAs($admin)
            ->postJson(route('media-archives.upload.complete', $media), [
                ...$completePayload,
                'replace_existing' => true,
            ])
            ->assertOk();

        $this->assertSame(
            'replacement.zip',
            MediaArchive::where('media_id', $media->id)->value('original_name')
        );
        Storage::disk('local')->assertMissing(
            'media-upload-chunks/archives/'.$media->id.'/'.$uploadId
        );
    }

    private function adminAndMedia(string $type): array
    {
        $role = Role::where('role', 'Admin')->first()
            ?? Role::create(['role' => 'Admin']);

        $user = User::create([
            'username' => 'archive-test-'.Str::lower(Str::random(10)),
            'email' => Str::lower(Str::random(12)).'@example.test',
            'password' => 'password',
            'status' => 'active',
            'role_id' => $role->role_id,
        ]);

        $media = Media::create([
            'type' => $type,
            'title_english' => 'Archive Test',
            'slug' => 'archive-test-'.Str::lower(Str::random(12)),
        ]);

        return [$user, $media];
    }

    private function zipUpload(string $originalName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'media-archive-test-');
        $this->temporaryArchives[] = $path;

        file_put_contents($path, base64_decode(
            'UEsDBBQAAAAIAD2J91xDUC/NEgAAABAAAAANAAAAZXBpc29kZS0xLm1wNCtJLS5RKMtMSc1XSKosSS0GAFBLAQIUABQAAAAIAD2J91xDUC/NEgAAABAAAAANAAAAAAAAAAAAAAAAAAAAAABlcGlzb2RlLTEubXA0UEsFBgAAAAABAAEAOwAAAD0AAAAAAA=='
        ));

        return new UploadedFile($path, $originalName, 'application/zip', null, true);
    }
}
