<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Media;
use App\Support\UploadedArchive;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ChapterController extends Controller
{
    public function storeUploaded(Request $request, Media $media, UploadedArchive $uploadedArchive)
    {
        abort_unless(in_array(strtolower((string) $media->type), ['manga', 'manwha'], true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $validator = Validator::make($request->all(), [
            'archive' => ['required', 'file', 'max:1048576'],
        ]);

        if ($validator->fails()) {
            return $this->redirectUploadFailure($validator->errors()->first(), $request);
        }

        /** @var UploadedFile $archive */
        $archive = $request->file('archive');

        if (strtolower((string) $archive->getClientOriginalExtension()) !== 'zip') {
            return $this->redirectUploadFailure('Upload a ZIP archive.', $request);
        }

        $disk = Storage::disk('public');
        $targetRelRoot = strtolower((string) $media->type).'/'.$media->id;
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

            $existingChapters = Chapter::where('media_fk', $media->id)
                ->get(['chapter_number', 'chapter_title']);

            $usedNumbers = [];
            foreach ($existingChapters as $chapter) {
                if ($chapter->chapter_number !== null) {
                    $usedNumbers[$this->normalizeChapterNumber((float) $chapter->chapter_number)] = true;
                }
            }

            $usedTitles = $existingChapters
                ->pluck('chapter_title')
                ->filter()
                ->mapWithKeys(fn ($title) => [mb_strtolower(trim((string) $title)) => true])
                ->all();

            $maxExistingNumber = $existingChapters
                ->pluck('chapter_number')
                ->filter(fn ($number) => $number !== null)
                ->map(fn ($number) => (float) $number)
                ->max();

            $nextFallbackNumber = $maxExistingNumber !== null
                ? ((int) $maxExistingNumber + 1)
                : 1;

            foreach ($imports as $index => $import) {
                $resolvedNumber = $import['number'];

                if ($resolvedNumber !== null) {
                    $numberKey = $this->normalizeChapterNumber($resolvedNumber);
                    if (isset($usedNumbers[$numberKey])) {
                        throw new \RuntimeException("Chapter {$this->displayChapterNumber($resolvedNumber)} already exists.");
                    }
                } else {
                    while (isset($usedNumbers[$this->normalizeChapterNumber((float) $nextFallbackNumber)])) {
                        $nextFallbackNumber++;
                    }

                    $resolvedNumber = (float) $nextFallbackNumber;
                }

                $resolvedTitle = trim((string) ($import['title'] ?? ''));
                if ($resolvedTitle === '') {
                    $resolvedTitle = 'Chapter '.$this->displayChapterNumber($resolvedNumber);
                }

                $titleKey = mb_strtolower($resolvedTitle);
                if (isset($usedTitles[$titleKey])) {
                    throw new \RuntimeException("Chapter '{$resolvedTitle}' already exists.");
                }

                $usedNumbers[$this->normalizeChapterNumber($resolvedNumber)] = true;
                $usedTitles[$titleKey] = true;
                $nextFallbackNumber = max($nextFallbackNumber, (int) $resolvedNumber + 1);

                $imports[$index]['resolved_number'] = $resolvedNumber;
                $imports[$index]['resolved_title'] = $resolvedTitle;
            }

            $firstImportedPage = null;

            DB::transaction(function () use (
                $imports,
                $uploadedArchive,
                $disk,
                $targetRelRoot,
                $media,
                &$createdFiles,
                &$createdDirectories,
                &$firstImportedPage
            ) {
                foreach ($imports as $import) {
                    $chapterNumber = (float) $import['resolved_number'];
                    $chapterTitle = (string) $import['resolved_title'];
                    $directoryName = $this->makeChapterDirectoryName($chapterNumber, $chapterTitle, $uploadedArchive);
                    $targetRelDir = $this->reserveChapterDirectory($disk, $targetRelRoot, $directoryName);
                    $createdDirectories[] = $targetRelDir;

                    $chapter = Chapter::create([
                        'item_type' => strtoupper((string) $media->type),
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

                $media->chapters_cnt = Chapter::where('media_fk', $media->id)->count();
                if (!$media->cover_url && $firstImportedPage !== null) {
                    $media->cover_url = $firstImportedPage;
                }
                $media->save();
            });

            return back()->with([
                'status' => 'Uploaded '.count($imports).' chapter(s).',
                'status_color' => 'green',
            ]);
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

    private function redirectUploadFailure(string $message, Request $request)
    {
        return back()
            ->withInput($request->except('archive'))
            ->with('open_media_content_upload_modal', true)
            ->with('media_content_upload_error', $message);
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

        $isManwha = strtoupper($mediaRow->type ?? '') === 'MANWHA'
            || strtoupper($mediaRow->origin ?? '') === 'KR';

        if (strtolower($mediaRow->type ?? '') === 'doujin') {
            $itemUrl = route('doujins.show', ['media' => $chapter->media_fk]);
        } else {
            $itemUrl = route('media.show', ['id' => $chapter->media_fk]);
        }

        $pages = $chapter->pages->values();
        $page = $pages->firstWhere('page_number', $pageNumber);
        abort_if(!$page, 404);
        $pageUrl = asset('storage/'.$page->file_path);

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
            'isManwha' => $isManwha,
        ]);
    }
}
