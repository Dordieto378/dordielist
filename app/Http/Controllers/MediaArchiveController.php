<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\MediaArchive;
use App\Support\MediaStoragePath;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class MediaArchiveController extends Controller
{
    public function store(Request $request, Media $media)
    {
        $validator = Validator::make($request->all(), [
            'archive' => ['required', 'file'],
        ]);

        if ($validator->fails()) {
            return $this->uploadFailure($validator->errors()->first(), $request);
        }

        /** @var UploadedFile $archive */
        $archive = $request->file('archive');

        return $this->storeArchive($request, $media, $archive);
    }

    public function uploadChunk(Request $request, Media $media)
    {
        $this->authorizeArchiveManagement($request, $media);

        $uploadId = $this->normalizeUploadId($request->input('upload_id'));
        $chunkIndex = filter_var($request->input('chunk_index'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $totalChunks = filter_var($request->input('total_chunks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $chunk = $request->file('archive_chunk');

        if (! $uploadId || $chunkIndex === false || $totalChunks === false || $chunkIndex >= $totalChunks) {
            return response()->json(['message' => 'Invalid upload request.'], 422);
        }

        if (! $chunk instanceof UploadedFile || ! $chunk->isValid()) {
            return response()->json(['message' => 'Upload chunk is missing.'], 422);
        }

        if ($chunkIndex === 0) {
            $this->pruneStaleChunkUploads($media);
        }

        $storedPath = Storage::disk('local')->putFileAs(
            $this->chunkDirectory($media, $uploadId),
            $chunk,
            $this->chunkFilename($chunkIndex)
        );

        if (! $storedPath) {
            return response()->json(['message' => 'ZIP upload failed.'], 500);
        }

        return response()->json(['ok' => true, 'chunk_index' => $chunkIndex]);
    }

    public function completeUpload(Request $request, Media $media)
    {
        $this->authorizeArchiveManagement($request, $media);

        $uploadId = $this->normalizeUploadId($request->input('upload_id'));
        $totalChunks = filter_var($request->input('total_chunks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $originalName = trim((string) $request->input('original_name'));

        if (! $uploadId || $totalChunks === false) {
            return response()->json(['message' => 'Invalid upload request.'], 422);
        }

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            return response()->json(['message' => 'Upload a ZIP archive.'], 422);
        }

        if (MediaArchive::where('media_id', $media->id)->exists() && ! $request->boolean('replace_existing')) {
            return response()->json([
                'message' => 'A ZIP is already stored for this title.',
                'can_replace' => true,
            ], 409);
        }

        $disk = Storage::disk('local');
        $chunkDirectory = $this->chunkDirectory($media, $uploadId);
        $temporaryPath = $chunkDirectory.'/archive.zip';
        $temporaryAbsolutePath = $disk->path($temporaryPath);
        $output = @fopen($temporaryAbsolutePath, 'wb');

        if ($output === false) {
            return response()->json(['message' => 'ZIP upload failed.'], 500);
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkPath = $chunkDirectory.'/'.$this->chunkFilename($index);

                if (! $disk->exists($chunkPath)) {
                    throw new \RuntimeException('Upload is incomplete. Retry the upload.');
                }

                $input = @fopen($disk->path($chunkPath), 'rb');
                if ($input === false) {
                    throw new \RuntimeException('ZIP upload failed.');
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
            }

            fclose($output);
            $output = null;

            $archive = new UploadedFile(
                $temporaryAbsolutePath,
                $originalName,
                'application/zip',
                null,
                true
            );
            $response = $this->storeArchive($request, $media, $archive, $temporaryPath);
            $statusCode = method_exists($response, 'getStatusCode')
                ? $response->getStatusCode()
                : 500;

            if ($statusCode !== 409) {
                $disk->deleteDirectory($chunkDirectory);
            }

            return $response;
        } catch (Throwable $e) {
            report($e);
            $disk->deleteDirectory($chunkDirectory);

            return response()->json([
                'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : 'ZIP upload failed.',
            ], 500);
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }

            if (is_file($temporaryAbsolutePath)) {
                @unlink($temporaryAbsolutePath);
            }
        }
    }

    public function download(Media $media)
    {
        $this->ensureArchiveMedia($media);

        $archive = MediaArchive::where('media_id', $media->id)->firstOrFail();
        $disk = Storage::disk('local');

        abort_unless($disk->exists($archive->file_path), 404);

        return response()->download(
            $disk->path($archive->file_path),
            $this->safeOriginalName($archive->original_name),
            [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function destroy(Request $request, Media $media)
    {
        $this->authorizeArchiveManagement($request, $media);

        $archive = MediaArchive::where('media_id', $media->id)->first();
        if (! $archive) {
            return back();
        }

        $filePath = $archive->file_path;
        $archive->delete();

        $disk = Storage::disk('local');
        if ($disk->exists($filePath)) {
            $disk->delete($filePath);
        }

        $directory = MediaStoragePath::archiveDirectory($media);
        if ($disk->directoryExists($directory)
            && $disk->files($directory) === []
            && $disk->directories($directory) === []) {
            $disk->deleteDirectory($directory);
        }

        return back();
    }

    private function storeArchive(
        Request $request,
        Media $media,
        UploadedFile $archive,
        ?string $localSourcePath = null
    ) {
        $this->authorizeArchiveManagement($request, $media);

        if (strtolower((string) $archive->getClientOriginalExtension()) !== 'zip') {
            return $this->uploadFailure('Upload a ZIP archive.', $request);
        }

        try {
            $this->assertValidZip($archive);
        } catch (Throwable $e) {
            return $this->uploadFailure($e->getMessage(), $request);
        }

        $existing = MediaArchive::where('media_id', $media->id)->first();
        if ($existing && ! $request->boolean('replace_existing')) {
            return $this->uploadFailure(
                'A ZIP is already stored for this title.',
                $request,
                true,
                409
            );
        }

        $disk = Storage::disk('local');
        $storedPath = MediaStoragePath::archiveDirectory($media).'/'.Str::uuid().'.zip';
        $stored = $localSourcePath !== null
            ? $disk->move($localSourcePath, $storedPath)
            : $disk->putFileAs(
                MediaStoragePath::archiveDirectory($media),
                $archive,
                basename($storedPath)
            );

        if (! $stored) {
            return $this->uploadFailure('Could not store the ZIP archive.', $request, false, 500);
        }

        try {
            $fileSize = $disk->size($storedPath);
            $originalName = $this->safeOriginalName($archive->getClientOriginalName());

            DB::transaction(function () use ($media, $storedPath, $originalName, $fileSize) {
                MediaArchive::updateOrCreate(
                    ['media_id' => $media->id],
                    [
                        'file_path' => $storedPath,
                        'original_name' => $originalName,
                        'file_size' => $fileSize,
                    ]
                );
            });
        } catch (Throwable $e) {
            $disk->delete($storedPath);
            report($e);

            return $this->uploadFailure('Could not save the ZIP archive.', $request, false, 500);
        }

        if ($existing && $existing->file_path !== $storedPath && $disk->exists($existing->file_path)) {
            $disk->delete($existing->file_path);
        }

        return $this->uploadSuccess($request);
    }

    private function assertValidZip(UploadedFile $archive): void
    {
        $path = $archive->getRealPath();
        if (! $path || ! is_file($path) || filesize($path) === 0) {
            throw new \RuntimeException('The ZIP archive is empty or unreadable.');
        }

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive;
            $opened = $zip->open($path, ZipArchive::CHECKCONS);

            if ($opened !== true) {
                throw new \RuntimeException('The uploaded file is not a valid ZIP archive.');
            }

            $numberOfFiles = $zip->numFiles;
            $zip->close();

            if ($numberOfFiles < 1) {
                throw new \RuntimeException('The ZIP archive is empty.');
            }

            return;
        }

        $tarPath = 'C:\Windows\System32\tar.exe';
        if (is_file($tarPath)) {
            $process = new Process([$tarPath, '-tf', $path]);
            $process->setTimeout((int) config('filesystems.archive_extract_timeout', 600));
            $process->run();

            if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
                return;
            }
        }

        throw new \RuntimeException('The uploaded file is not a valid ZIP archive.');
    }

    private function authorizeArchiveManagement(Request $request, Media $media): void
    {
        $this->ensureArchiveMedia($media);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);
    }

    private function ensureArchiveMedia(Media $media): void
    {
        abort_unless(in_array(strtolower((string) $media->type), ['anime', 'hentai'], true), 404);
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $base = pathinfo($name, PATHINFO_FILENAME);

        if ($base === '') {
            return 'videos.zip';
        }

        return mb_substr($base, 0, 240).'.zip';
    }

    private function chunkDirectory(Media $media, string $uploadId): string
    {
        return 'media-upload-chunks/archives/'.$media->id.'/'.$uploadId;
    }

    private function pruneStaleChunkUploads(Media $media): void
    {
        $disk = Storage::disk('local');
        $root = 'media-upload-chunks/archives/'.$media->id;
        $cutoff = now()->subDay()->timestamp;

        foreach ($disk->directories($root) as $directory) {
            $files = $disk->allFiles($directory);
            $lastModified = 0;

            foreach ($files as $file) {
                $lastModified = max($lastModified, $disk->lastModified($file));
            }

            if ($files === [] || $lastModified < $cutoff) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    private function chunkFilename(int $chunkIndex): string
    {
        return 'chunk-'.str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT).'.part';
    }

    private function normalizeUploadId(mixed $uploadId): ?string
    {
        $uploadId = trim((string) $uploadId);

        if ($uploadId === '' || ! preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $uploadId)) {
            return null;
        }

        return $uploadId;
    }

    private function uploadFailure(string $message, Request $request, bool $canReplace = false, int $status = 422)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'can_replace' => $canReplace,
            ], $status);
        }

        return back()
            ->withInput($request->except('archive'))
            ->with('open_media_content_upload_modal', true)
            ->with('media_content_upload_error', $message);
    }

    private function uploadSuccess(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
