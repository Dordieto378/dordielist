<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Media;
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

        abort_unless(in_array($mediaType, ['manga', 'manhwa', 'doujin'], true), 404);
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

        abort_unless(in_array($mediaType, ['manga', 'manhwa', 'doujin'], true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $uploadId = $this->normalizeUploadId($request->input('upload_id'));
        $totalChunks = filter_var($request->input('total_chunks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $originalName = trim((string) $request->input('original_name'));

        if (!$uploadId || $totalChunks === false) {
            return response()->json(['message' => 'Invalid upload request.'], 422);
        }

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            return response()->json(['message' => 'Upload a ZIP archive.'], 422);
        }

        $disk = Storage::disk('local');
        $chunkDirectory = $this->chunkDirectory($media, $uploadId);
        $tempRelativePath = $chunkDirectory.'/archive.zip';
        $tempAbsolutePath = $disk->path($tempRelativePath);
        $output = @fopen($tempAbsolutePath, 'wb');

        if ($output === false) {
            return response()->json(['message' => 'Chapter ZIP upload failed.'], 500);
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkRelativePath = $chunkDirectory.'/'.$this->chunkFilename($index);
                if (!$disk->exists($chunkRelativePath)) {
                    throw new \RuntimeException('Upload is incomplete. Retry the upload.');
                }

                $input = @fopen($disk->path($chunkRelativePath), 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Chapter ZIP upload failed.');
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
            }

            fclose($output);
            $archive = new UploadedFile($tempAbsolutePath, $originalName, 'application/zip', null, true);
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
                'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : 'Chapter ZIP upload failed.',
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

        abort_unless(in_array($mediaType, ['manga', 'manhwa', 'doujin'], true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);
        $replaceExisting = $request->boolean('replace_existing');

        if (strtolower((string) $archive->getClientOriginalExtension()) !== 'zip') {
            return $this->redirectUploadFailure('Upload a ZIP archive.', $request);
        }

        $disk = Storage::disk('public');
        $targetRelRoot = MediaStoragePath::chapterDirectory($media);
        $createdFiles = [];
        $createdDirectories = [];
        $extractRoot = null;

        try {
            $extractRoot = $uploadedArchive->extractArchiveToTemporaryRoot($archive, 'chapter-upload-');
            $contentRoot = $uploadedArchive->resolveContentRoot($extractRoot);
            $imports = $this->buildChapterImports($contentRoot, $archive, $uploadedArchive);

            if ($imports === []) {
                throw new \RuntimeException('ZIP must contain chapter folders or chapter images.');
            }

            File::ensureDirectoryExists($disk->path($targetRelRoot));

            $existingChapters = Chapter::with(['pages:id,chapter_id,file_path'])
                ->where('media_fk', $media->id)
                ->get(['id', 'chapter_number', 'chapter_title']);

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
                        return $this->redirectUploadFailure("Chapter {$this->displayChapterNumber($resolvedNumber)} already exists.", $request, true, 409);
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
                    throw new \RuntimeException("Chapter {$this->displayChapterNumber($resolvedNumber)} appears more than once in the ZIP.");
                }

                if ($replacementChapter) {
                    $replacedChapters[$replacementChapter->id] = $replacementChapter;
                }

                $resolvedTitle = trim((string) ($import['title'] ?? ''));
                if ($resolvedTitle === '') {
                    $resolvedTitle = 'Chapter '.$this->displayChapterNumber($resolvedNumber);
                }

                $titleKey = mb_strtolower($resolvedTitle);
                $existingTitleChapter = $existingByTitle[$titleKey] ?? null;
                if ($existingTitleChapter && (!$replacementChapter || $existingTitleChapter->id !== $replacementChapter->id)) {
                    throw new \RuntimeException("Chapter '{$resolvedTitle}' already exists.");
                }

                if (isset($plannedTitleKeys[$titleKey])) {
                    throw new \RuntimeException("Chapter '{$resolvedTitle}' appears more than once in the ZIP.");
                }

                $plannedNumberKeys[$numberKey] = true;
                $plannedTitleKeys[$titleKey] = true;
                $nextFallbackNumber = max($nextFallbackNumber, (int) $resolvedNumber + 1);

                $imports[$index]['resolved_number'] = $resolvedNumber;
                $imports[$index]['resolved_title'] = $resolvedTitle;
            }

            $firstImportedPage = null;
            $chapterOneCoverPage = null;
            $replacedChapterIds = array_keys($replacedChapters);
            $replacedFiles = [];
            $replacedDirectories = [];
            $coverNeedsRefresh = false;
            $currentCoverPath = is_string($media->cover_url) ? $media->cover_url : null;

            foreach ($replacedChapters as $chapter) {
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
                &$chapterOneCoverPage
            ) {
                if ($replacedChapterIds !== []) {
                    ChapterPage::whereIn('chapter_id', $replacedChapterIds)->delete();
                    Chapter::whereIn('id', $replacedChapterIds)->delete();
                }

                foreach ($imports as $import) {
                    $chapterNumber = (float) $import['resolved_number'];
                    $chapterTitle = (string) $import['resolved_title'];
                    $directoryName = $this->makeChapterDirectoryName($chapterNumber, $chapterTitle, $uploadedArchive);
                    $targetRelDir = $this->reserveChapterDirectory($disk, $targetRelRoot, $directoryName);
                    $createdDirectories[] = $targetRelDir;

                    $chapter = Chapter::create([
                        'item_type' => $mediaType === 'doujin' ? 'doujin' : strtoupper((string) $media->type),
                        'item_id' => $media->id,
                        'media_fk' => $media->id,
                        'chapter_number' => $chapterNumber,
                        'chapter_title' => $chapterTitle,
                    ]);

                    $pageRows = [];
                    foreach (array_values($import['images']) as $pageIndex => $imagePath) {
                        $pageNumber = $pageIndex + 1;
                        $basename = basename($imagePath);
                        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
                        $sourceName = pathinfo($basename, PATHINFO_FILENAME);
                        $safeName = $uploadedArchive->sanitizePathSegment($sourceName, 'page-'.$pageNumber);
                        $targetFilename = sprintf('%03d-%s.%s', $pageNumber, $safeName, $ext);
                        $targetRelPath = $targetRelDir.'/'.$targetFilename;
                        $targetAbsPath = $disk->path($targetRelPath);

                        if (!@rename($imagePath, $targetAbsPath)) {
                            if (!@copy($imagePath, $targetAbsPath)) {
                                throw new \RuntimeException("Could not store uploaded page '{$basename}'.");
                            }

                            @unlink($imagePath);
                        }

                        $createdFiles[] = $targetRelPath;

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
                }

                if (($media->source ?? null) !== 'anilist') {
                    $media->chapters_cnt = Chapter::where('media_fk', $media->id)->count();
                }
                if ($mediaType === 'doujin' && $chapterOneCoverPage !== null) {
                    $media->cover_url = $chapterOneCoverPage;
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
                : 'Chapter ZIP upload failed.';

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

        abort_unless(in_array($mediaType, ['manga', 'manhwa', 'doujin'], true), 404);
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
                $media->chapters_cnt = 0;
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

    private function buildChapterImports(string $contentRoot, UploadedFile $archive, UploadedArchive $uploadedArchive): array
    {
        $chapterDirs = $uploadedArchive->listDirectories($contentRoot);
        $rootImages = $uploadedArchive->listImageFiles($contentRoot);

        if ($chapterDirs !== []) {
            if ($rootImages !== []) {
                throw new \RuntimeException('ZIP should contain chapter folders or a single chapter of images, not both.');
            }

            $imports = [];
            foreach ($chapterDirs as $chapterDir) {
                $images = $uploadedArchive->listImageFiles($chapterDir, true);
                if ($images === []) {
                    throw new \RuntimeException("Chapter folder '".basename($chapterDir)."' has no images.");
                }

                $title = basename($chapterDir);
                $imports[] = [
                    'title' => $title,
                    'number' => $this->parseChapterNumber($title),
                    'images' => $images,
                ];
            }

            return $imports;
        }

        if ($rootImages !== []) {
            $title = trim((string) pathinfo($archive->getClientOriginalName(), PATHINFO_FILENAME));

            return [[
                'title' => $title,
                'number' => $this->parseChapterNumber($title),
                'images' => $rootImages,
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

    private function makeChapterDirectoryName(float $chapterNumber, string $chapterTitle, UploadedArchive $uploadedArchive): string
    {
        $numberSegment = str_replace('.', '-', $this->displayChapterNumber($chapterNumber));
        $titleSegment = $uploadedArchive->sanitizePathSegment($chapterTitle, 'chapter-'.$numberSegment);

        return 'chapter-'.$numberSegment.'-'.$titleSegment;
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

            return redirect()->route('chapters.page', [
                'media' => $mediaId,
                'chapter' => $byTitle->chapter_number,
                'page' => $pageNumber ?? 1,
                'view' => $view,
            ]);
        }

        $chapterNumber = (float) $chapterParam;

        $chapter = Chapter::with(['pages' => fn ($query) => $query->orderBy('page_number')])
            ->where('item_id', $mediaId)
            ->where('chapter_number', $chapterNumber)
            ->firstOrFail();

        if ($pageNumber === null) {
            $pageNumber = optional($chapter->pages->first())->page_number ?? 1;
        }

        $mediaRow = DB::table('media')
            ->where('id', $chapter->media_fk)
            ->select('id', 'title_english', 'title_romaji', 'title_native', 'slug', 'type', 'origin')
            ->first();

        $itemTitle = $mediaRow->title_english ?? $mediaRow->title_romaji ?? $mediaRow->title_native ?? 'Unknown Item';

        $isManhwa = strtoupper($mediaRow->type ?? '') === 'MANHWA'
            || strtoupper($mediaRow->origin ?? '') === 'KR';

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
        ]);
    }

    public function readerPageImage(Request $request, ChapterPage $page)
    {
        $page->loadMissing('chapter');

        $chapter = $page->chapter;
        abort_unless($chapter, 404);

        $mediaType = strtolower((string) ($chapter->item_type ?? ''));
        abort_unless(in_array($mediaType, ['manga', 'manhwa', 'doujin'], true), 404);

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
}
