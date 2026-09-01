<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Media;
use App\Models\ReadingBookmark;
use App\Support\EpubVolumeExtractor;
use App\Support\MediaStoragePath;
use App\Support\UploadedArchive;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ChapterController extends Controller
{
    public function storeUploaded(Request $request, Media $media, UploadedArchive $uploadedArchive)
    {
        $validator = Validator::make($request->all(), [
            'archive' => ['required', 'file', 'max:1048576'],
        ]);

        if ($validator->fails()) {
            return $this->redirectUploadFailure($validator->errors()->first(), $request);
        }

        /** @var UploadedFile $archive */
        $archive = $request->file('archive');

        return $this->processUploadedArchive($request, $media, $uploadedArchive, $archive);
    }

    public function uploadChunk(Request $request, Media $media)
    {
        $mediaType = strtolower((string) $media->type);

        abort_unless(in_array($mediaType, $this->contentUploadMediaTypes(), true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $uploadId = $this->normalizeUploadId($request->input('upload_id'));
        $chunkIndex = filter_var($request->input('chunk_index'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $totalChunks = filter_var($request->input('total_chunks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $chunk = $request->file('archive_chunk');

        if (!$uploadId || $chunkIndex === false || $totalChunks === false) {
            return response()->json(['message' => 'Invalid upload request.'], 422);
        }

        if (!$chunk instanceof UploadedFile || !$chunk->isValid()) {
            return response()->json(['message' => 'Upload chunk is missing.'], 422);
        }

        if ($chunkIndex >= $totalChunks) {
            return response()->json(['message' => 'Invalid chunk index.'], 422);
        }

        $storedPath = Storage::disk('local')->putFileAs(
            $this->chunkDirectory($media, $uploadId),
            $chunk,
            $this->chunkFilename($chunkIndex)
        );

        if (!$storedPath) {
            return response()->json(['message' => 'Chunk upload failed.'], 500);
        }

        return response()->json(['ok' => true, 'chunk_index' => $chunkIndex]);
    }

    public function completeUpload(Request $request, Media $media, UploadedArchive $uploadedArchive)
    {
        $mediaType = strtolower((string) $media->type);
        $unitLabel = $this->contentUnitLabel($mediaType);
        $archiveLabel = $this->contentUploadDisplayName($mediaType);

        abort_unless(in_array($mediaType, $this->contentUploadMediaTypes(), true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $uploadId = $this->normalizeUploadId($request->input('upload_id'));
        $totalChunks = filter_var($request->input('total_chunks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $originalName = trim((string) $request->input('original_name'));

        if (!$uploadId || $totalChunks === false) {
            return response()->json(['message' => 'Invalid upload request.'], 422);
        }

        $requiredExtension = $this->contentUploadExtension($mediaType);
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== $requiredExtension) {
            return response()->json(['message' => $this->contentUploadPrompt($mediaType)], 422);
        }

        $disk = Storage::disk('local');
        $chunkDirectory = $this->chunkDirectory($media, $uploadId);
        $tempRelativePath = $chunkDirectory.'/archive.'.$requiredExtension;
        $tempAbsolutePath = $disk->path($tempRelativePath);
        $output = @fopen($tempAbsolutePath, 'wb');

        if ($output === false) {
            return response()->json(['message' => "{$unitLabel} {$archiveLabel} upload failed."], 500);
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkRelativePath = $chunkDirectory.'/'.$this->chunkFilename($index);
                if (!$disk->exists($chunkRelativePath)) {
                    throw new \RuntimeException('Upload is incomplete. Retry the upload.');
                }

                $input = @fopen($disk->path($chunkRelativePath), 'rb');
                if ($input === false) {
                    throw new \RuntimeException("{$unitLabel} {$archiveLabel} upload failed.");
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
            }

            fclose($output);
            $archive = new UploadedFile($tempAbsolutePath, $originalName, $this->contentUploadMimeType($mediaType), null, true);
            $response = $this->processUploadedArchive($request, $media, $uploadedArchive, $archive);

            if (method_exists($response, 'getStatusCode') && $response->getStatusCode() < 400) {
                $disk->deleteDirectory($chunkDirectory);
            }

            return $response;
        } catch (Throwable $e) {
            if (is_resource($output)) {
                fclose($output);
            }

            report($e);

            return response()->json([
                'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : "{$unitLabel} {$archiveLabel} upload failed.",
            ], 500);
        } finally {
            if (is_file($tempAbsolutePath)) {
                @unlink($tempAbsolutePath);
            }
        }
    }

    private function processUploadedArchive(Request $request, Media $media, UploadedArchive $uploadedArchive, UploadedFile $archive)
    {
        $mediaType = strtolower((string) $media->type);
        $unitLabel = $this->contentUnitLabel($mediaType);
        $unitName = strtolower($unitLabel);
        $archiveLabel = $this->contentUploadDisplayName($mediaType);

        abort_unless(in_array($mediaType, $this->contentUploadMediaTypes(), true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);
        $replaceExisting = $request->boolean('replace_existing');

        if (strtolower((string) $archive->getClientOriginalExtension()) !== $this->contentUploadExtension($mediaType)) {
            return $this->redirectUploadFailure($this->contentUploadPrompt($mediaType), $request);
        }

        $disk = Storage::disk('public');
        $targetRelRoot = MediaStoragePath::chapterDirectory($media);
        $createdFiles = [];
        $createdDirectories = [];
        $extractRoot = null;

        try {
            $extractRoot = $uploadedArchive->extractArchiveToTemporaryRoot(
                $archive,
                $mediaType === 'light_novel' ? 'light-novel-upload-' : 'chapter-upload-'
            );
            $imports = $this->buildContentImports($extractRoot, $archive, $uploadedArchive, $mediaType);

            if ($imports === []) {
                throw new \RuntimeException($this->emptyContentUploadMessage($mediaType, $unitName));
            }

            File::ensureDirectoryExists($disk->path($targetRelRoot));

            $existingChapters = Chapter::with(['pages:id,chapter_id,file_path'])
                ->where('media_fk', $media->id)
                ->get(['id', 'chapter_number', 'chapter_title', 'thumbnail_path']);

            $existingByNumber = [];
            $existingByTitle = [];
            $usedNumbers = [];
            foreach ($existingChapters as $chapter) {
                if ($chapter->chapter_number !== null) {
                    $numberKey = $this->normalizeChapterNumber((float) $chapter->chapter_number);
                    $usedNumbers[$numberKey] = true;
                    $existingByNumber[$numberKey] = $chapter;
                }

                $title = trim((string) ($chapter->chapter_title ?? ''));
                if ($title !== '') {
                    $existingByTitle[mb_strtolower($title)] = $chapter;
                }
            }

            $maxExistingNumber = $existingChapters
                ->pluck('chapter_number')
                ->filter(fn ($number) => $number !== null)
                ->map(fn ($number) => (float) $number)
                ->max();

            $nextFallbackNumber = $maxExistingNumber !== null
                ? ((int) $maxExistingNumber + 1)
                : 1;
            $plannedNumberKeys = [];
            $plannedTitleKeys = [];
            $replacedChapters = [];

            foreach ($imports as $index => $import) {
                $resolvedNumber = $import['number'];
                $replacementChapter = null;

                if ($resolvedNumber !== null) {
                    $numberKey = $this->normalizeChapterNumber($resolvedNumber);
                    $replacementChapter = $existingByNumber[$numberKey] ?? null;

                    if ($replacementChapter && !$replaceExisting) {
                        return $this->redirectUploadFailure("{$unitLabel} {$this->displayChapterNumber($resolvedNumber)} already exists.", $request, true, 409);
                    }
                } else {
                    while (
                        isset($usedNumbers[$this->normalizeChapterNumber((float) $nextFallbackNumber)])
                        || isset($plannedNumberKeys[$this->normalizeChapterNumber((float) $nextFallbackNumber)])
                    ) {
                        $nextFallbackNumber++;
                    }

                    $resolvedNumber = (float) $nextFallbackNumber;
                    $numberKey = $this->normalizeChapterNumber($resolvedNumber);
                }

                if (isset($plannedNumberKeys[$numberKey])) {
                    throw new \RuntimeException("{$unitLabel} {$this->displayChapterNumber($resolvedNumber)} appears more than once in the {$archiveLabel}.");
                }

                if ($replacementChapter) {
                    $replacedChapters[$replacementChapter->id] = $replacementChapter;
                }

                $resolvedTitle = trim((string) ($import['title'] ?? ''));
                if ($resolvedTitle === '') {
                    $resolvedTitle = $this->defaultContentUnitTitle($resolvedNumber, $mediaType);
                }

                $titleKey = mb_strtolower($resolvedTitle);
                $existingTitleChapter = $existingByTitle[$titleKey] ?? null;
                if ($existingTitleChapter && (!$replacementChapter || $existingTitleChapter->id !== $replacementChapter->id)) {
                    throw new \RuntimeException("{$unitLabel} '{$resolvedTitle}' already exists.");
                }

                if (isset($plannedTitleKeys[$titleKey])) {
                    throw new \RuntimeException("{$unitLabel} '{$resolvedTitle}' appears more than once in the {$archiveLabel}.");
                }

                $plannedNumberKeys[$numberKey] = true;
                $plannedTitleKeys[$titleKey] = true;
                $nextFallbackNumber = max($nextFallbackNumber, (int) $resolvedNumber + 1);

                $imports[$index]['resolved_number'] = $resolvedNumber;
                $imports[$index]['resolved_title'] = $resolvedTitle;
            }

            $firstImportedPage = null;
            $firstImportedThumbnail = null;
            $chapterOneCoverPage = null;
            $replacedChapterIds = array_keys($replacedChapters);
            $replacedFiles = [];
            $replacedDirectories = [];
            $coverNeedsRefresh = false;
            $currentCoverPath = is_string($media->cover_url) ? $media->cover_url : null;

            foreach ($replacedChapters as $chapter) {
                if ($chapter->thumbnail_path) {
                    $replacedFiles[] = $chapter->thumbnail_path;
                    $replacedDirectories[preg_replace('#/[^/]+$#', '', $chapter->thumbnail_path) ?: ''] = true;

                    if ($currentCoverPath !== null && $currentCoverPath === $chapter->thumbnail_path) {
                        $coverNeedsRefresh = true;
                    }
                }

                foreach ($chapter->pages as $page) {
                    if (!$page->file_path) {
                        continue;
                    }

                    $replacedFiles[] = $page->file_path;
                    $replacedDirectories[preg_replace('#/[^/]+$#', '', $page->file_path) ?: ''] = true;

                    if ($currentCoverPath !== null && $currentCoverPath === $page->file_path) {
                        $coverNeedsRefresh = true;
                    }
                }
            }

            DB::transaction(function () use (
                $imports,
                $uploadedArchive,
                $disk,
                $targetRelRoot,
                $replacedChapterIds,
                $coverNeedsRefresh,
                $media,
                $mediaType,
                &$createdFiles,
                &$createdDirectories,
                &$firstImportedPage,
                &$chapterOneCoverPage,
                &$firstImportedThumbnail
            ) {
                if ($replacedChapterIds !== []) {
                    ChapterPage::whereIn('chapter_id', $replacedChapterIds)->delete();
                    Chapter::whereIn('id', $replacedChapterIds)->delete();
                }

                foreach ($imports as $import) {
                    $chapterNumber = (float) $import['resolved_number'];
                    $chapterTitle = (string) $import['resolved_title'];
                    $directoryName = $this->makeContentUnitDirectoryName($chapterNumber, $chapterTitle, $uploadedArchive, $mediaType);
                    $targetRelDir = $this->reserveChapterDirectory($disk, $targetRelRoot, $directoryName);
                    $createdDirectories[] = $targetRelDir;

                    $chapter = Chapter::create([
                        'item_type' => $mediaType === 'doujin' ? 'doujin' : strtoupper((string) $media->type),
                        'item_id' => $media->id,
                        'media_fk' => $media->id,
                        'chapter_number' => $chapterNumber,
                        'chapter_title' => $chapterTitle,
                        'thumbnail_path' => null,
                    ]);

                    $pageRows = [];
                    $chapterFirstPage = null;
                    $chapterThumbnail = null;

                    if (!empty($import['thumbnail'])) {
                        $chapterThumbnail = $this->storeImportThumbnail((string) $import['thumbnail'], $targetRelDir, $disk);
                        $createdFiles[] = $chapterThumbnail;

                        if ($firstImportedThumbnail === null) {
                            $firstImportedThumbnail = $chapterThumbnail;
                        }
                    }

                    foreach (array_values($import['pages']) as $pageIndex => $sourcePath) {
                        $pageNumber = $pageIndex + 1;
                        $targetRelPath = $this->storeImportPage((string) $sourcePath, $targetRelDir, $pageNumber, $uploadedArchive, $disk);

                        $createdFiles[] = $targetRelPath;

                        if ($chapterFirstPage === null) {
                            $chapterFirstPage = $targetRelPath;
                        }

                        if ($firstImportedPage === null) {
                            $firstImportedPage = $targetRelPath;
                        }

                        if (
                            $mediaType === 'doujin'
                            && (float) $chapterNumber === 1.0
                            && $pageNumber === 1
                            && $chapterOneCoverPage === null
                        ) {
                            $chapterOneCoverPage = $targetRelPath;
                        }

                        $pageRows[] = [
                            'chapter_id' => $chapter->id,
                            'page_number' => $pageNumber,
                            'file_path' => $targetRelPath,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    ChapterPage::insert($pageRows);

                    if ($chapterThumbnail === null && $chapterFirstPage !== null && $this->isImagePath($chapterFirstPage)) {
                        $chapterThumbnail = $chapterFirstPage;

                        if ($firstImportedThumbnail === null) {
                            $firstImportedThumbnail = $chapterThumbnail;
                        }
                    }

                    if ($chapterThumbnail !== null) {
                        $chapter->thumbnail_path = $chapterThumbnail;
                        $chapter->save();
                    }
                }

                if (($media->source ?? null) !== 'anilist') {
                    if ($this->usesVolumeUnits($mediaType)) {
                        $media->volumes_cnt = Chapter::where('media_fk', $media->id)->count();
                    } else {
                        $media->chapters_cnt = Chapter::where('media_fk', $media->id)->count();
                    }
                }
                if ($mediaType === 'doujin' && $chapterOneCoverPage !== null) {
                    $media->cover_url = $chapterOneCoverPage;
                } elseif ($mediaType === 'light_novel' && ($coverNeedsRefresh || !$media->cover_url) && $firstImportedThumbnail !== null) {
                    $media->cover_url = $firstImportedThumbnail;
                } elseif (($coverNeedsRefresh || !$media->cover_url) && $firstImportedPage !== null) {
                    $media->cover_url = $firstImportedPage;
                }
                $media->save();
            });

            $this->cleanupCreatedFiles($disk, $replacedFiles);
            $this->cleanupCreatedDirectories($disk, array_keys(array_filter($replacedDirectories)));

            return $this->uploadSuccessResponse($request);
        } catch (Throwable $e) {
            report($e);
            $this->cleanupCreatedFiles($disk, $createdFiles);
            $this->cleanupCreatedDirectories($disk, $createdDirectories);
            $this->cleanupEmptyDirectory($disk, $targetRelRoot);

            $message = trim($e->getMessage()) !== ''
                ? $e->getMessage()
                : "{$unitLabel} {$archiveLabel} upload failed.";

            return $this->redirectUploadFailure($message, $request);
        } finally {
            if ($extractRoot !== null && File::isDirectory($extractRoot)) {
                File::deleteDirectory($extractRoot);
            }
        }
    }

    private function chunkDirectory(Media $media, string $uploadId): string
    {
        return 'media-upload-chunks/chapters/'.$media->id.'/'.$uploadId;
    }

    private function chunkFilename(int $chunkIndex): string
    {
        return 'chunk-'.str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT).'.part';
    }

    private function normalizeUploadId(mixed $uploadId): ?string
    {
        $uploadId = trim((string) $uploadId);

        if ($uploadId === '' || !preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $uploadId)) {
            return null;
        }

        return $uploadId;
    }

    public function resetUploaded(Request $request, Media $media)
    {
        $mediaType = strtolower((string) $media->type);

        abort_unless(in_array($mediaType, $this->contentUploadMediaTypes(), true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $disk = Storage::disk('public');
        $targetRelRoot = MediaStoragePath::chapterDirectory($media);
        $preservedCoverPath = null;

        if ($mediaType === 'doujin') {
            $firstChapter = Chapter::where('media_fk', $media->id)
                ->orderBy('chapter_number')
                ->first();

            $firstPagePath = $firstChapter
                ? ChapterPage::where('chapter_id', $firstChapter->id)
                    ->orderBy('page_number')
                    ->value('file_path')
                : null;

            if ($firstPagePath && $disk->exists($firstPagePath)) {
                $ext = strtolower(pathinfo($firstPagePath, PATHINFO_EXTENSION) ?: 'jpg');
                $coverDir = 'doujin-covers/'.$media->id;
                $preservedCoverPath = $coverDir.'/cover.'.$ext;

                if ($disk->exists($coverDir)) {
                    $disk->deleteDirectory($coverDir);
                }

                File::ensureDirectoryExists($disk->path($coverDir));
                $disk->copy($firstPagePath, $preservedCoverPath);
            }
        }

        DB::transaction(function () use ($media, $targetRelRoot, $mediaType, $preservedCoverPath) {
            $chapterIds = Chapter::where('media_fk', $media->id)->pluck('id');

            if ($chapterIds->isNotEmpty()) {
                ChapterPage::whereIn('chapter_id', $chapterIds)->delete();
                Chapter::whereIn('id', $chapterIds)->delete();
            }

            if (($media->source ?? null) !== 'anilist') {
                if ($this->usesVolumeUnits($mediaType)) {
                    $media->volumes_cnt = 0;
                } else {
                    $media->chapters_cnt = 0;
                }
            }

            if ($mediaType === 'doujin' && $preservedCoverPath !== null) {
                $media->cover_url = $preservedCoverPath;
            } elseif (is_string($media->cover_url) && str_starts_with($media->cover_url, $targetRelRoot.'/')) {
                $media->cover_url = null;
            }

            $media->save();
        });

        if ($disk->directoryExists($targetRelRoot)) {
            $disk->deleteDirectory($targetRelRoot);
        }

        return $this->uploadSuccessResponse($request);
    }

    private function buildContentImports(string $extractRoot, UploadedFile $archive, UploadedArchive $uploadedArchive, string $mediaType): array
    {
        if ($mediaType === 'light_novel') {
            return app(EpubVolumeExtractor::class)->buildImportsFromZipRoot($extractRoot);
        }

        $contentRoot = $this->resolveContentRoot($extractRoot, $uploadedArchive, $mediaType);

        return $this->buildChapterImports($contentRoot, $archive, $uploadedArchive, $mediaType);
    }

    private function buildChapterImports(string $contentRoot, UploadedFile $archive, UploadedArchive $uploadedArchive, string $mediaType): array
    {
        $chapterDirs = $uploadedArchive->listDirectories($contentRoot);
        $rootImages = $uploadedArchive->listImageFiles($contentRoot);
        $unitLabel = $this->contentUnitLabel($mediaType);
        $unitName = strtolower($unitLabel);

        if ($chapterDirs !== []) {
            if ($rootImages !== []) {
                throw new \RuntimeException("ZIP should contain {$unitName} folders or a single {$unitName} of images, not both.");
            }

            $imports = [];
            foreach ($chapterDirs as $chapterDir) {
                $images = $uploadedArchive->listImageFiles($chapterDir, true);
                if ($images === []) {
                    throw new \RuntimeException("{$unitLabel} folder '".basename($chapterDir)."' has no images.");
                }

                $title = basename($chapterDir);
                $imports[] = [
                    'title' => $this->displayContentUnitTitle($title, $mediaType),
                    'number' => $this->parseContentUnitNumber($title, $mediaType),
                    'pages' => $images,
                    'thumbnail' => null,
                ];
            }

            return $imports;
        }

        if ($rootImages !== []) {
            $title = trim((string) pathinfo($archive->getClientOriginalName(), PATHINFO_FILENAME));

            return [[
                'title' => $this->displayContentUnitTitle($title, $mediaType),
                'number' => $this->parseContentUnitNumber($title, $mediaType),
                'pages' => $rootImages,
                'thumbnail' => null,
            ]];
        }

        return [];
    }

    private function reserveChapterDirectory($disk, string $targetRelRoot, string $directoryName): string
    {
        $candidate = trim($targetRelRoot.'/'.$directoryName, '/');
        $suffix = 2;

        while ($disk->exists($candidate)) {
            $candidate = trim($targetRelRoot.'/'.$directoryName.'-'.$suffix, '/');
            $suffix++;
        }

        File::ensureDirectoryExists($disk->path($candidate));

        return $candidate;
    }

    private function storeImportPage(string $sourcePath, string $targetRelDir, int $pageNumber, UploadedArchive $uploadedArchive, $disk): string
    {
        $basename = basename($sourcePath);
        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        $sourceName = pathinfo($basename, PATHINFO_FILENAME);
        $safeName = $uploadedArchive->sanitizePathSegment($sourceName, 'page-'.$pageNumber);
        $targetFilename = sprintf('%03d-%s.%s', $pageNumber, $safeName, $ext ?: 'dat');
        $targetRelPath = $targetRelDir.'/'.$targetFilename;
        $targetAbsPath = $disk->path($targetRelPath);

        if (!@rename($sourcePath, $targetAbsPath)) {
            if (!@copy($sourcePath, $targetAbsPath)) {
                throw new \RuntimeException("Could not store uploaded page '{$basename}'.");
            }

            @unlink($sourcePath);
        }

        return $targetRelPath;
    }

    private function storeImportThumbnail(string $sourcePath, string $targetRelDir, $disk): string
    {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($ext === '') {
            $mime = File::mimeType($sourcePath) ?: '';
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                default => 'jpg',
            };
        }

        $targetRelPath = $targetRelDir.'/cover.'.$ext;
        $targetAbsPath = $disk->path($targetRelPath);

        if (!@copy($sourcePath, $targetAbsPath)) {
            throw new \RuntimeException('Could not store EPUB cover image.');
        }

        return $targetRelPath;
    }

    private function resolveContentRoot(string $extractRoot, UploadedArchive $uploadedArchive, string $mediaType): string
    {
        $current = realpath($extractRoot) ?: $extractRoot;

        for ($depth = 0; $depth < 5; $depth++) {
            $childDirectories = $uploadedArchive->listDirectories($current);
            $visibleFiles = $uploadedArchive->listFiles($current);

            if ($visibleFiles !== [] || count($childDirectories) !== 1) {
                break;
            }

            if ($this->usesVolumeUnits($mediaType) && $this->looksLikeVolumeDirectoryName(basename($childDirectories[0]))) {
                break;
            }

            $current = $childDirectories[0];
        }

        return $current;
    }

    private function makeContentUnitDirectoryName(float $chapterNumber, string $chapterTitle, UploadedArchive $uploadedArchive, string $mediaType): string
    {
        if ($this->usesVolumeUnits($mediaType)) {
            return 'Vol.'.$this->displayChapterNumber($chapterNumber);
        }

        $unitSlug = $this->contentUnitSlug($mediaType);
        $numberSegment = str_replace('.', '-', $this->displayChapterNumber($chapterNumber));
        $titleSegment = $uploadedArchive->sanitizePathSegment($chapterTitle, $unitSlug.'-'.$numberSegment);

        return $unitSlug.'-'.$numberSegment.'-'.$titleSegment;
    }

    private function redirectUploadFailure(string $message, Request $request, bool $canReplace = false, int $status = 422)
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

    private function uploadSuccessResponse(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    private function cleanupCreatedFiles($disk, array $paths): void
    {
        foreach (array_reverse($paths) as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }

    private function cleanupCreatedDirectories($disk, array $paths): void
    {
        usort($paths, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($paths as $path) {
            if (!$disk->exists($path)) {
                continue;
            }

            if ($disk->files($path) !== [] || $disk->directories($path) !== []) {
                continue;
            }

            File::deleteDirectory($disk->path($path));
        }
    }

    private function cleanupEmptyDirectory($disk, string $relativePath): void
    {
        if (!$disk->exists($relativePath)) {
            return;
        }

        if ($disk->files($relativePath) !== [] || $disk->directories($relativePath) !== []) {
            return;
        }

        File::deleteDirectory($disk->path($relativePath));
    }

    private function contentUploadMediaTypes(): array
    {
        return ['manga', 'manhwa', 'doujin', 'light_novel'];
    }

    private function contentUploadExtension(string $mediaType): string
    {
        return 'zip';
    }

    private function contentUploadMimeType(string $mediaType): string
    {
        return 'application/zip';
    }

    private function contentUploadDisplayName(string $mediaType): string
    {
        return strtoupper($this->contentUploadExtension($mediaType));
    }

    private function contentUploadPrompt(string $mediaType): string
    {
        return strtolower($mediaType) === 'light_novel'
            ? 'Upload a ZIP archive containing EPUB files.'
            : 'Upload a ZIP archive.';
    }

    private function emptyContentUploadMessage(string $mediaType, string $unitName): string
    {
        return strtolower($mediaType) === 'light_novel'
            ? 'ZIP must contain EPUB files with readable book content.'
            : "ZIP must contain {$unitName} folders or {$unitName} images.";
    }

    private function isImagePath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    private function usesVolumeUnits(string $mediaType): bool
    {
        return in_array(strtolower($mediaType), ['manga', 'light_novel'], true);
    }

    private function contentUnitLabel(string $mediaType): string
    {
        return $this->usesVolumeUnits($mediaType) ? 'Volume' : 'Chapter';
    }

    private function contentUnitSlug(string $mediaType): string
    {
        return strtolower($this->contentUnitLabel($mediaType));
    }

    private function parseContentUnitNumber(string $name, string $mediaType): ?float
    {
        return $this->usesVolumeUnits($mediaType)
            ? $this->parseVolumeNumber($name)
            : $this->parseChapterNumber($name);
    }

    private function displayContentUnitTitle(string $name, string $mediaType): string
    {
        if ($this->usesVolumeUnits($mediaType)) {
            if (preg_match('/\b(?:extra|bonus|special)(?:\s+(?:volume|vol\.?))?\b/i', $name)) {
                return 'Extra Volume';
            }

            $number = $this->parseVolumeNumber($name);
            if ($number !== null && $this->looksLikeVolumeDirectoryName($name)) {
                return 'Volume '.$this->displayChapterNumber($number);
            }
        }

        return $this->displayChapterTitle($name);
    }

    private function defaultContentUnitTitle(float $number, string $mediaType): string
    {
        if ($this->usesVolumeUnits($mediaType)) {
            return 'Volume '.$this->displayChapterNumber($number);
        }

        return $this->contentUnitLabel($mediaType).' '.$this->displayChapterNumber($number);
    }

    private function parseVolumeNumber(string $name): ?float
    {
        $normalized = mb_strtolower($name);

        if (preg_match('/\b(?:volume|vol|v)[\s\._-]*([0-9]+(?:[\._][0-9]+)?)/i', $normalized, $matches)) {
            return (float) strtr($matches[1], ['_' => '.', ',' => '.']);
        }

        if (preg_match('/\b([0-9]+(?:[\._][0-9]+)?)\b/', $normalized, $matches)) {
            return (float) strtr($matches[1], ['_' => '.', ',' => '.']);
        }

        return null;
    }

    private function looksLikeVolumeDirectoryName(string $name): bool
    {
        return preg_match('/\b(?:volume|vol\.?|v)[\s\._-]*[0-9]+(?:[\._][0-9]+)?/i', $name) === 1;
    }

    private function parseChapterNumber(string $name): ?float
    {
        $normalized = mb_strtolower($name);

        if (preg_match('/\b(?:chapter|ch|c)[\s\-_]*([0-9]+(?:\.[0-9]+)?)/i', $normalized, $matches)) {
            return (float) $matches[1];
        }

        if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\b/', $normalized, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    private function displayChapterTitle(string $name): string
    {
        if (preg_match('/\b(?:extra|bonus|special)(?:\s+chapter)?\b/i', $name)) {
            return 'Extra Chapter';
        }

        if (preg_match('/\b(?:chapter|ch|c)?[\s\-_]*([0-9]+(?:[\._][0-9]+)?)\s*&\s*([0-9]+(?:[\._][0-9]+)?)\b/i', $name, $matches)) {
            return $this->displayChapterNumber((float) strtr($matches[1], ['_' => '.', ',' => '.']))
                .' & '
                .$this->displayChapterNumber((float) strtr($matches[2], ['_' => '.', ',' => '.']));
        }

        return $name;
    }

    private function normalizeChapterNumber(float $number): string
    {
        return number_format($number, 2, '.', '');
    }

    private function displayChapterNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    public function readPage(Request $request, int $mediaId, $chapterParam, ?int $pageNumber = null)
    {
        $view = $request->query('view', 'one');

        if (!is_numeric($chapterParam)) {
            $byTitle = Chapter::where('item_id', $mediaId)
                ->where('chapter_title', $chapterParam)
                ->firstOrFail();
            $redirectView = strtolower((string) ($byTitle->item_type ?? '')) === 'light_novel'
                ? 'double'
                : $view;
            $readerBookmark = $this->readerBookmarkFor($request, $mediaId);

            return redirect()->route('chapters.page', [
                'media' => $mediaId,
                'chapter' => $byTitle->chapter_number,
                'page' => $pageNumber ?? $this->bookmarkPageNumber($readerBookmark, $byTitle) ?? 1,
                'view' => $redirectView,
            ]);
        }

        $chapterNumber = (float) $chapterParam;

        $chapter = Chapter::with(['pages' => fn ($query) => $query->orderBy('page_number')])
            ->where('item_id', $mediaId)
            ->where('chapter_number', $chapterNumber)
            ->firstOrFail();

        $readerBookmark = $this->readerBookmarkFor($request, $mediaId);
        if ($pageNumber === null) {
            $pageNumber = $this->bookmarkPageNumber($readerBookmark, $chapter)
                ?? optional($chapter->pages->first())->page_number
                ?? 1;
        }

        $mediaRow = DB::table('media')
            ->where('id', $chapter->media_fk)
            ->select('id', 'title_english', 'title_romaji', 'title_native', 'slug', 'type', 'origin')
            ->first();

        $itemTitle = ($mediaRow->type ?? null) === 'doujin'
            ? ($mediaRow->title_english ?? $mediaRow->slug ?? 'Unknown Item')
            : ($mediaRow->title_english ?? $mediaRow->title_romaji ?? $mediaRow->title_native ?? 'Unknown Item');

        $isManhwa = strtoupper($mediaRow->type ?? '') === 'MANHWA'
            || strtoupper($mediaRow->origin ?? '') === 'KR';
        $isLightNovel = strtolower((string) ($mediaRow->type ?? '')) === 'light_novel';
        if ($isLightNovel) {
            $view = 'double';
        }
        $readerUnitLabel = $this->usesVolumeUnits(strtolower((string) ($mediaRow->type ?? '')))
            ? 'Volume'
            : 'Chapter';

        if (strtolower($mediaRow->type ?? '') === 'doujin') {
            $itemUrl = route('doujins.show', ['media' => $chapter->media_fk]);
        } else {
            $itemUrl = route('media.show', ['id' => $chapter->media_fk]);
        }

        $pages = $chapter->pages->values();
        $page = $pages->firstWhere('page_number', $pageNumber);
        abort_if(!$page, 404);
        $pageUrl = URL::temporarySignedRoute('reader.page.image', now()->addHours(2), ['page' => $page->id]);

        $numbers = $pages->pluck('page_number')->values()->all();
        $index = array_search($pageNumber, $numbers, true);
        $prevPageNum = ($index !== false && $index > 0) ? $numbers[$index - 1] : null;
        $nextPageNum = ($index !== false && $index < count($numbers) - 1) ? $numbers[$index + 1] : null;

        $nextChapter = Chapter::where('item_id', $mediaId)
            ->where('chapter_number', '>', $chapterNumber)
            ->orderBy('chapter_number', 'asc')
            ->first();

        $prevChapter = Chapter::where('item_id', $mediaId)
            ->where('chapter_number', '<', $chapterNumber)
            ->orderBy('chapter_number', 'desc')
            ->first();

        $prevChapterLastPage = null;
        if ($prevChapter) {
            $prevChapterLastPage = ChapterPage::where('chapter_id', $prevChapter->id)->max('page_number') ?? 1;
        }

        $prevLink = null;
        if ($prevPageNum !== null) {
            $prevLink = route('chapters.page', [
                'media' => $mediaId,
                'chapter' => $chapter->chapter_number,
                'page' => $prevPageNum,
                'view' => $view,
            ]);
        } elseif ($prevChapter && $prevChapterLastPage) {
            $prevLink = route('chapters.page', [
                'media' => $mediaId,
                'chapter' => $prevChapter->chapter_number,
                'page' => $prevChapterLastPage,
                'view' => $view,
            ]);
        }

        $nextLink = null;
        if ($nextPageNum !== null) {
            $nextLink = route('chapters.page', [
                'media' => $mediaId,
                'chapter' => $chapter->chapter_number,
                'page' => $nextPageNum,
                'view' => $view,
            ]);
        } elseif ($nextChapter) {
            $nextLink = route('chapters.page', [
                'media' => $mediaId,
                'chapter' => $nextChapter->chapter_number,
                'page' => 1,
                'view' => $view,
            ]);
        } else {
            $nextLink = $itemUrl;
        }

        $prevChapterLink = $prevChapter
            ? route('chapters.page', [
                'media' => $mediaId,
                'chapter' => $prevChapter->chapter_number,
                'page' => $prevChapterLastPage ?: 1,
                'view' => $view,
            ])
            : null;

        $nextChapterLink = $nextChapter
            ? route('chapters.page', [
                'media' => $mediaId,
                'chapter' => $nextChapter->chapter_number,
                'page' => 1,
                'view' => $view,
            ])
            : $itemUrl;

        return view('chapters.read', [
            'chapter' => $chapter,
            'pages' => $pages,
            'pageNumber' => $pageNumber,
            'pageUrl' => $pageUrl,
            'prevLink' => $prevLink,
            'nextLink' => $nextLink,
            'prevChapterLink' => $prevChapterLink,
            'nextChapterLink' => $nextChapterLink,
            'nextPairLink' => null,
            'prevPairLink' => null,
            'itemTitle' => $itemTitle,
            'itemUrl' => $itemUrl,
            'isManhwa' => $isManhwa,
            'isLightNovel' => $isLightNovel,
            'readerView' => $view,
            'readerUnitLabel' => $readerUnitLabel,
            'isCurrentPageBookmarked' => $this->isCurrentPageBookmarked($readerBookmark, $chapter, $page),
        ]);
    }

    public function bookmarkPage(Request $request, Media $media, Chapter $chapter)
    {
        abort_unless($this->chapterBelongsToMedia($chapter, (int) $media->id), 404);

        $validator = Validator::make($request->all(), [
            'page_number' => ['required', 'integer', 'min:1'],
            'view' => ['nullable', 'in:one,double,scroll'],
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $validator->errors()->first()], 422);
            }

            return back()->with('status', $validator->errors()->first())->with('status_color', 'red');
        }

        $data = $validator->validated();
        $page = ChapterPage::where('chapter_id', $chapter->id)
            ->where('page_number', (int) $data['page_number'])
            ->firstOrFail();

        $bookmarkKey = [
            'user_id' => $request->user()->getAuthIdentifier(),
            'media_id' => $media->id,
        ];
        $existingBookmark = ReadingBookmark::where($bookmarkKey)->first();
        $isUnmarkingCurrentPage = $existingBookmark
            && (int) $existingBookmark->chapter_id === (int) $chapter->id
            && (int) $existingBookmark->page_id === (int) $page->id;

        if ($isUnmarkingCurrentPage) {
            $existingBookmark->delete();
        } else {
            ReadingBookmark::updateOrCreate($bookmarkKey, [
                'chapter_id' => $chapter->id,
                'page_id' => $page->id,
                'page_number' => $page->page_number,
            ]);
        }

        $payload = [
            'ok' => true,
            'bookmarked' => !$isUnmarkingCurrentPage,
            'chapter_id' => $chapter->id,
            'page_number' => (int) $page->page_number,
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return redirect()
            ->route('chapters.page', [
                'media' => $media->id,
                'chapter' => $chapter->chapter_number !== null
                    ? $this->displayChapterNumber((float) $chapter->chapter_number)
                    : $chapter->chapter_title,
                'page' => $page->page_number,
                'view' => $data['view'] ?? 'one',
            ])
            ->with('status', $isUnmarkingCurrentPage ? 'Bookmark removed.' : 'Bookmark saved.')
            ->with('status_color', 'green');
    }

    public function readerPageImage(Request $request, ChapterPage $page)
    {
        $page->loadMissing('chapter');

        $chapter = $page->chapter;
        abort_unless($chapter, 404);

        $mediaType = strtolower((string) ($chapter->item_type ?? ''));
        abort_unless(in_array($mediaType, $this->contentUploadMediaTypes(), true), 404);

        $path = ltrim((string) $page->file_path, '/');
        abort_if($path === '' || str_contains($path, '..'), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($path), 404);

        $absolutePath = $disk->path($path);
        $mimeType = File::mimeType($absolutePath) ?: 'application/octet-stream';

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    private function readerBookmarkFor(Request $request, int $mediaId): ?ReadingBookmark
    {
        $userId = $request->user()?->getAuthIdentifier();
        if (!$userId) {
            return null;
        }

        return ReadingBookmark::with(['chapter', 'page'])
            ->where('user_id', $userId)
            ->where('media_id', $mediaId)
            ->first();
    }

    private function bookmarkPageNumber(?ReadingBookmark $bookmark, Chapter $chapter): ?int
    {
        if (!$bookmark || (int) $bookmark->chapter_id !== (int) $chapter->id) {
            return null;
        }

        if (!$bookmark->page || (int) $bookmark->page->chapter_id !== (int) $chapter->id) {
            return null;
        }

        return (int) $bookmark->page->page_number;
    }

    private function isCurrentPageBookmarked(?ReadingBookmark $bookmark, Chapter $chapter, ChapterPage $page): bool
    {
        return $bookmark !== null
            && (int) $bookmark->chapter_id === (int) $chapter->id
            && (int) $bookmark->page_id === (int) $page->id;
    }

    private function chapterBelongsToMedia(Chapter $chapter, int $mediaId): bool
    {
        return (int) ($chapter->media_fk ?: $chapter->item_id) === $mediaId
            || (int) $chapter->item_id === $mediaId;
    }
}
