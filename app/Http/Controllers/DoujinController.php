<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Support\DoujinFolderIndex;
use App\Support\MediaMetadataSyncer;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class DoujinController extends Controller
{
    public function __construct(
        private readonly MediaMetadataSyncer $metadataSyncer,
        private readonly DoujinFolderIndex $folderIndex,
    )
    {
    }

    public function show(int $mediaId)
    {
        $media = Media::with('doujinAuthors:id,name')
            ->where('type', 'doujin')
            ->findOrFail($mediaId);

        $chapters = Chapter::where('item_type', 'doujin')
            ->where('media_fk', $media->id)
            ->orderBy('chapter_number')
            ->get(['id', 'chapter_number', 'chapter_title']);

        $chaptersView = $chapters->map(function (Chapter $chapter) {
            $pages = ChapterPage::where('chapter_id', $chapter->id)
                ->orderBy('page_number')
                ->get(['id', 'page_number', 'file_path']);

            return [
                'id' => $chapter->id,
                'number' => $chapter->chapter_number,
                'title' => $chapter->chapter_title,
                'pages' => $pages->map(fn ($page) => [
                    'id' => $page->id,
                    'num' => $page->page_number,
                    'url' => Storage::url($page->file_path),
                    'path' => $page->file_path,
                ])->values()->all(),
            ];
        });

        $coverUrl = $media->cover_url ? Storage::url($media->cover_url) : asset('images/no-image.jpg');

        $isFavorited = \App\Models\Favorite::where([
            ['favoritable_type', 'doujins'],
            ['favoritable_id', $media->id],
        ])->exists();

        $allCollections = \App\Models\Collection::orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();
        $allAuthors = DoujinAuthor::query()
            ->orderBy('name')
            ->pluck('name');

        $attachedIds = \App\Models\CollectionItem::where('item_type', 'doujins')
            ->where('item_id', $media->id)
            ->pluck('collection_id')
            ->toArray();

        return view('media.doujin', [
            'media' => $media,
            'coverUrl' => $coverUrl,
            'chapters' => $chaptersView,
            'isFavorited' => $isFavorited,
            'allCollections' => $allCollections,
            'allAuthors' => $allAuthors,
            'attachedIds' => $attachedIds,
        ]);
    }

    public function updateEntry(Request $request, Media $media)
    {
        abort_unless($media->type === 'doujin', 404);

        $validator = Validator::make($request->all(), [
            'title_english' => ['nullable', 'string', 'max:255'],
            'title_romaji' => ['nullable', 'string', 'max:255'],
            'title_native' => ['nullable', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return back()
                ->withInput()
                ->with('open_edit_doujin_modal', true)
                ->with('doujin_update_error', $validator->errors()->first());
        }

        $data = $validator->validated();
        $titleEnglish = $this->trimToNull($data['title_english'] ?? null);
        $titleRomaji = $this->trimToNull($data['title_romaji'] ?? null);
        $titleNative = $this->trimToNull($data['title_native'] ?? null);
        $authors = $this->parseAuthorNames($data['author'] ?? null);

        if (!$titleEnglish && !$titleRomaji && !$titleNative) {
            return back()
                ->withInput()
                ->with('open_edit_doujin_modal', true)
                ->with('doujin_update_error', 'Add at least one title.');
        }

        $media->title_english = $titleEnglish;
        $media->title_romaji = $titleRomaji;
        $media->title_native = $titleNative;
        $media->slug = $this->makeUniqueMediaSlug(
            $media,
            $titleRomaji ?: ($titleEnglish ?: ($titleNative ?: ($media->slug ?: 'doujin-'.$media->id)))
        );
        $media->save();

        $this->metadataSyncer->syncDoujin($media, $authors);

        return back();
    }

    public function destroy(Media $media)
    {
        abort_unless($media->type === 'doujin', 404);

        $disk = Storage::disk('public');
        $pathsToDelete = [];

        $media->loadMissing('doujinAuthors:id,name');
        $entries = $this->folderIndex->scanDisk($disk, 'doujin');
        $entry = $this->folderIndex->findEntryForMedia($media, $entries);

        if ($entry && !empty($entry['path'])) {
            $pathsToDelete[] = trim((string) $entry['path'], '/');
        }

        $pathsToDelete[] = 'doujin/'.$media->id;
        $pathsToDelete = array_values(array_unique(array_filter($pathsToDelete)));

        $authorPath = null;
        if ($entry && !empty($entry['author'])) {
            $authorPath = 'doujin/'.trim((string) $entry['author'], '/');
        }

        $media->delete();

        foreach ($pathsToDelete as $path) {
            if ($disk->exists($path)) {
                File::deleteDirectory($disk->path($path));
            }
        }

        if ($authorPath && $disk->exists($authorPath)) {
            if ($disk->directories($authorPath) === [] && $disk->files($authorPath) === []) {
                File::deleteDirectory($disk->path($authorPath));
            }
        }

        return redirect()
            ->route('category', ['category' => 'doujins']);
    }

    public function storeUploaded(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title_english' => ['nullable', 'string', 'max:255'],
            'title_romaji' => ['nullable', 'string', 'max:255'],
            'title_native' => ['nullable', 'string', 'max:255'],
            'existing_author' => ['nullable', 'string', 'max:255'],
            'new_author' => ['nullable', 'string', 'max:255'],
            'archive' => ['required', 'file', 'max:1048576'],
        ]);

        if ($validator->fails()) {
            return $this->redirectUploadFailure($validator->errors()->first(), $request);
        }

        /** @var UploadedFile $archive */
        $archive = $request->file('archive');
        $titleEnglish = $this->trimToNull($request->input('title_english'));
        $titleRomaji = $this->trimToNull($request->input('title_romaji'));
        $titleNative = $this->trimToNull($request->input('title_native'));
        $author = $this->trimToNull($request->input('new_author'))
            ?: $this->trimToNull($request->input('existing_author'));

        if (!$titleEnglish && !$titleRomaji && !$titleNative) {
            return $this->redirectUploadFailure('Add at least one title.', $request);
        }

        if (!$author) {
            return $this->redirectUploadFailure('Select an author or add a new author.', $request);
        }

        if (strtolower((string) $archive->getClientOriginalExtension()) !== 'zip') {
            return $this->redirectUploadFailure('Upload a ZIP archive.', $request);
        }

        $media = null;
        $extractRoot = storage_path('app/tmp/doujin-upload-'.Str::uuid());

        try {
            File::ensureDirectoryExists($extractRoot);
            $this->extractDoujinArchive($archive, $extractRoot);

            $importRoot = $this->resolveArchiveImportRoot($extractRoot);
            if ($importRoot === null) {
                throw new \RuntimeException('ZIP must contain chapter folders, optionally inside one top-level folder.');
            }

            $media = new Media();
            $media->type = 'doujin';
            $media->title_english = $titleEnglish;
            $media->title_romaji = $titleRomaji;
            $media->title_native = $titleNative;
            $media->slug = $this->makeUniqueMediaSlug(
                $media,
                $titleRomaji ?: ($titleEnglish ?: ($titleNative ?: 'doujin'))
            );
            $media->cover_url = null;
            $media->chapters_cnt = 0;
            $media->save();

            $this->metadataSyncer->syncDoujin($media, [$author]);

            $disk = Storage::disk('public');
            $targetRel = 'doujin/'.$media->id;
            $targetAbs = $disk->path($targetRel);
            File::ensureDirectoryExists($targetAbs);

            $this->stageExtractedDoujin($importRoot, $targetAbs);
            $this->mirrorDoujin($disk, $targetRel, $media->id);

            return redirect()
                ->route('doujins.show', ['media' => $media->id]);
        } catch (Throwable $e) {
            report($e);

            if ($media?->exists) {
                $targetRel = 'doujin/'.$media->id;
                $targetAbs = Storage::disk('public')->path($targetRel);
                if (File::isDirectory($targetAbs)) {
                    File::deleteDirectory($targetAbs);
                }
                $media->delete();
            }

            $message = trim($e->getMessage()) !== ''
                ? $e->getMessage()
                : 'Upload failed. Check the ZIP structure and try again.';

            return $this->redirectUploadFailure($message, $request);
        } finally {
            if (File::isDirectory($extractRoot)) {
                File::deleteDirectory($extractRoot);
            }
        }
    }

    public function syncAll(Request $request)
    {
        $disk = Storage::disk('public');
        $root = 'doujin';
        if (!$disk->exists($root)) {
            return back()->with('error', "Folder not found: storage/app/public/{$root}");
        }

        $entries = $this->folderIndex->scanDisk($disk, $root);
        if (!$entries) {
            return back()->with('status', 'No doujin folders found.');
        }

        $lookup = $this->folderIndex->buildMediaLookup();
        $entries = $this->folderIndex->deduplicateEntries($entries, $lookup);

        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            $mediaId = $this->folderIndex->resolveMediaId($entry, $lookup);
            $folderTitle = $entry['legacy_title'] ?? null;

            if (!$mediaId) {
                if ($folderTitle === null) {
                    $failed++;
                    continue;
                }

                try {
                    $media = new Media();
                    $media->type = 'doujin';
                    $media->title_romaji = $folderTitle;
                    $media->title_native = $this->containsNonLatin($folderTitle) ? $folderTitle : null;
                    $media->slug = Str::slug($folderTitle);
                    $media->cover_url = null;
                    $media->chapters_cnt = 0;

                    if (\Schema::hasColumn('media', 'isNsfw')) {
                        $media->isNsfw = 1;
                    }

                    $media->save();
                    $this->metadataSyncer->syncDoujin($media, $this->authorsForEntry($entry));

                    $mediaId = $media->id;
                    $lookup = $this->folderIndex->buildMediaLookup();
                    $created++;
                } catch (\Throwable $ex) {
                    $failed++;
                    continue;
                }
            }

            try {
                DB::transaction(function () use ($disk, $entry, $mediaId) {
                    $media = Media::findOrFail($mediaId);
                    $folderTitle = $entry['legacy_title'] ?? null;

                    if ($folderTitle && !$media->title_native && $this->containsNonLatin($folderTitle)) {
                        $media->title_native = $folderTitle;
                        $media->save();
                    }

                    $this->metadataSyncer->syncDoujin($media, $this->authorsForEntry($entry));
                    $this->mirrorDoujin($disk, $entry['path'], $mediaId);
                });
                $updated++;
            } catch (\Throwable $ex) {
                $failed++;
            }
        }

        $msg = "Sync complete - created: {$created}, updated: {$updated}".($failed ? ", failed: {$failed}" : '');
        return back()->with($failed ? 'error' : 'status', $msg);
    }

    private function mirrorDoujin($disk, string $doujinPath, int $mediaId): void
    {
        $chapterDirs = $disk->directories($doujinPath);
        if (!$chapterDirs) {
            $chapterDirs = [$doujinPath];
        }
        usort($chapterDirs, 'strnatcasecmp');

        $desired = [];
        foreach ($chapterDirs as $idx => $chapterPath) {
            $title = basename($chapterPath);
            $number = $this->parseChapterNumber($title) ?? (float) ($idx + 1);
            $numberKey = $this->normNum($number);

            $files = $disk->files($chapterPath);
            $images = array_values(array_filter($files, fn ($file) => $this->isImage($file)));
            usort($images, 'strnatcasecmp');

            $desired[$numberKey] = ['title' => $title, 'images' => $images];
        }

        $existing = Chapter::where('media_fk', $mediaId)
            ->get(['id', 'chapter_number', 'chapter_title'])
            ->keyBy(fn ($chapter) => $this->normNum((float) $chapter->chapter_number));

        $toDropKeys = array_diff(array_keys($existing->all()), array_keys($desired));
        if ($toDropKeys) {
            $dropIds = $existing->only($toDropKeys)->pluck('id')->all();
            ChapterPage::whereIn('chapter_id', $dropIds)->delete();
            Chapter::whereIn('id', $dropIds)->delete();
            foreach ($toDropKeys as $dropKey) {
                unset($existing[$dropKey]);
            }
        }

        $firstCover = null;

        foreach ($desired as $numberKey => $info) {
            if (!isset($existing[$numberKey])) {
                $chapter = Chapter::create([
                    'media_fk' => $mediaId,
                    'item_type' => 'doujin',
                    'item_id' => $mediaId,
                    'chapter_number' => $numberKey,
                    'chapter_title' => $info['title'],
                ]);
                $existing[$numberKey] = $chapter;
            } else {
                $chapter = $existing[$numberKey];
                if (trim($chapter->chapter_title) !== trim($info['title'])) {
                    $chapter->chapter_title = $info['title'];
                    $chapter->save();
                }
            }

            $this->mirrorPages($disk, $chapter->id, $info['images']);

            if ($firstCover === null && !empty($info['images'])) {
                $firstCover = ltrim($info['images'][0], '/');
            }
        }

        $emptyChapterIds = Chapter::where('media_fk', $mediaId)
            ->whereDoesntHave('pages')
            ->pluck('id')
            ->all();
        if ($emptyChapterIds) {
            Chapter::whereIn('id', $emptyChapterIds)->delete();
        }

        $media = Media::find($mediaId);
        if ($media) {
            $coverMissing = !$media->cover_url || !Storage::disk('public')->exists((string) $media->cover_url);
            if ($coverMissing && $firstCover) {
                $media->cover_url = $firstCover;
            }
            $media->chapters_cnt = Chapter::where('media_fk', $mediaId)->count();
            $media->save();
        }

        $chapters = Chapter::where('media_fk', $mediaId)->get(['id', 'chapter_title']);
        foreach ($chapters as $chapter) {
            $pages = ChapterPage::where('chapter_id', $chapter->id)->get(['id', 'file_path']);
            if ($pages->isEmpty()) {
                Chapter::where('id', $chapter->id)->delete();
                continue;
            }

            $allMissing = true;
            foreach ($pages as $page) {
                if (Storage::disk('public')->exists($page->file_path)) {
                    $allMissing = false;
                    break;
                }
            }
            if ($allMissing) {
                ChapterPage::where('chapter_id', $chapter->id)->delete();
                Chapter::where('id', $chapter->id)->delete();
                continue;
            }

            $first = $pages->first()->file_path;
            $dir = preg_replace('#/[^/]+$#', '', $first) ?: '';
            if ($dir !== '' && !Storage::disk('public')->exists($dir)) {
                ChapterPage::where('chapter_id', $chapter->id)->delete();
                Chapter::where('id', $chapter->id)->delete();
                continue;
            }

            $danglingIds = [];
            foreach ($pages as $page) {
                if (!Storage::disk('public')->exists($page->file_path)) {
                    $danglingIds[] = $page->id;
                }
            }
            if ($danglingIds) {
                ChapterPage::whereIn('id', $danglingIds)->delete();
                if (!ChapterPage::where('chapter_id', $chapter->id)->exists()) {
                    Chapter::where('id', $chapter->id)->delete();
                }
            }
        }
    }

    private function mirrorPages($disk, int $chapterId, array $images): void
    {
        $desired = [];
        foreach ($images as $index => $rel) {
            $desired[$index + 1] = ltrim($rel, '/');
        }

        $existing = ChapterPage::where('chapter_id', $chapterId)
            ->get(['id', 'page_number', 'file_path'])
            ->keyBy('page_number');

        $toDeleteNums = array_diff(array_keys($existing->all()), array_keys($desired));
        if ($toDeleteNums) {
            ChapterPage::where('chapter_id', $chapterId)->whereIn('page_number', $toDeleteNums)->delete();
            foreach ($toDeleteNums as $number) {
                unset($existing[$number]);
            }
        }

        $danglingIds = [];
        foreach ($existing as $number => $row) {
            if (!$disk->exists($row->file_path)) {
                $danglingIds[] = $row->id;
                unset($existing[$number]);
            }
        }
        if ($danglingIds) {
            ChapterPage::whereIn('id', $danglingIds)->delete();
        }

        $insert = [];
        foreach ($desired as $number => $path) {
            if (!isset($existing[$number])) {
                $insert[] = [
                    'chapter_id' => $chapterId,
                    'page_number' => $number,
                    'file_path' => $path,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        if ($insert) {
            ChapterPage::insert($insert);
        }

        foreach ($desired as $number => $path) {
            if (isset($existing[$number]) && $existing[$number]->file_path !== $path) {
                ChapterPage::where('id', $existing[$number]->id)
                    ->update(['file_path' => $path, 'updated_at' => now()]);
            }
        }
    }

    private function parseChapterNumber(string $name): ?float
    {
        if (preg_match('/(\d+(?:[\._]\d+)?)/', strtolower($name), $matches)) {
            $number = strtr($matches[1], ['_' => '.', ',' => '.']);
            return (float) $number;
        }

        return null;
    }

    private function normNum(float $number): string
    {
        return number_format($number, 2, '.', '');
    }

    private function redirectUploadFailure(string $message, Request $request)
    {
        return back()
            ->withInput($request->except('archive'))
            ->with('open_add_doujin_modal', true)
            ->with('doujin_upload_error', $message);
    }

    private function extractDoujinArchive(UploadedFile $archive, string $extractRoot): void
    {
        $workingZip = $extractRoot.DIRECTORY_SEPARATOR.'upload.zip';

        if (!@copy((string) $archive->getRealPath(), $workingZip)) {
            throw new \RuntimeException('Could not prepare the uploaded ZIP archive.');
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $opened = $zip->open($workingZip);

            if ($opened !== true) {
                @unlink($workingZip);
                throw new \RuntimeException('Could not open the ZIP archive.');
            }

            if (!$zip->extractTo($extractRoot)) {
                $zip->close();
                @unlink($workingZip);
                throw new \RuntimeException('Could not extract the ZIP archive.');
            }

            $zip->close();
            @unlink($workingZip);
            return;
        }

        $command = sprintf(
            "Expand-Archive -LiteralPath '%s' -DestinationPath '%s' -Force",
            str_replace("'", "''", $workingZip),
            str_replace("'", "''", $extractRoot)
        );

        $process = new Process([
            'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $command,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($workingZip);
            $errorOutput = trim($process->getErrorOutput().' '.$process->getOutput());
            throw new \RuntimeException($errorOutput !== '' ? $errorOutput : 'Could not extract the ZIP archive.');
        }

        @unlink($workingZip);
    }

    private function resolveArchiveImportRoot(string $extractRoot): ?string
    {
        $queue = [realpath($extractRoot) ?: $extractRoot];
        $visited = [];
        $depth = 0;

        while ($queue !== [] && $depth < 5) {
            $nextQueue = [];

            foreach ($queue as $candidate) {
                $realCandidate = realpath($candidate) ?: $candidate;
                if (isset($visited[$realCandidate])) {
                    continue;
                }

                $visited[$realCandidate] = true;

                if ($this->looksLikeChapterRoot($realCandidate)) {
                    return $realCandidate;
                }

                $childDirs = $this->listDirectories($realCandidate);
                foreach ($childDirs as $childDir) {
                    if ($this->looksLikeChapterRoot($childDir)) {
                        return $childDir;
                    }
                }

                if (count($childDirs) === 1) {
                    $nextQueue[] = $childDirs[0];
                }
            }

            $queue = $nextQueue;
            $depth++;
        }

        return null;
    }

    private function stageExtractedDoujin(string $importRoot, string $targetAbs): void
    {
        $chapterDirs = $this->listDirectories($importRoot);
        if ($chapterDirs === []) {
            throw new \RuntimeException('ZIP does not contain any chapter folders.');
        }

        foreach ($chapterDirs as $chapterDir) {
            $chapterName = basename($chapterDir);
            $targetChapterDir = $targetAbs.DIRECTORY_SEPARATOR.$chapterName;
            File::ensureDirectoryExists($targetChapterDir);

            $images = $this->listImages($chapterDir);
            if ($images === []) {
                throw new \RuntimeException("Chapter folder '{$chapterName}' has no images.");
            }

            foreach ($images as $imagePath) {
                $targetPath = $targetChapterDir.DIRECTORY_SEPARATOR.basename($imagePath);

                if (file_exists($targetPath)) {
                    throw new \RuntimeException("Duplicate image name detected in '{$chapterName}'.");
                }

                if (!@rename($imagePath, $targetPath)) {
                    if (!@copy($imagePath, $targetPath)) {
                        throw new \RuntimeException("Could not move extracted image '{$imagePath}'.");
                    }
                    @unlink($imagePath);
                }
            }
        }
    }

    private function looksLikeChapterRoot(string $path): bool
    {
        if ($this->listImages($path) !== []) {
            return false;
        }

        $chapterDirs = $this->listDirectories($path);
        if ($chapterDirs === []) {
            return false;
        }

        foreach ($chapterDirs as $chapterDir) {
            if ($this->listDirectories($chapterDir) !== []) {
                return false;
            }

            if ($this->listImages($chapterDir) === []) {
                return false;
            }
        }

        return true;
    }

    private function listDirectories(string $path): array
    {
        $directories = array_values(array_filter(
            glob($path.DIRECTORY_SEPARATOR.'*') ?: [],
            function ($dir) {
                if (!is_dir($dir)) {
                    return false;
                }

                $name = basename($dir);

                return $name !== '__MACOSX' && !str_starts_with($name, '.');
            }
        ));
        natcasesort($directories);

        return array_values($directories);
    }

    private function listImages(string $path): array
    {
        $files = glob($path.DIRECTORY_SEPARATOR.'*') ?: [];
        $images = array_values(array_filter($files, fn ($file) => is_file($file) && $this->isImage($file)));
        natcasesort($images);

        return array_values($images);
    }

    private function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function parseAuthorNames(?string $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn ($author) => trim((string) $author))
            ->filter()
            ->unique(fn ($author) => mb_strtolower($author))
            ->values()
            ->all();
    }

    private function makeUniqueMediaSlug(Media $media, string $value): string
    {
        $base = Str::slug($value);
        if ($base === '') {
            $base = 'doujin-'.$media->id;
        }

        $slug = $base;
        $index = 2;

        while (
            Media::query()
                ->where('slug', $slug)
                ->whereKeyNot($media->getKey())
                ->exists()
        ) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }

    private function containsNonLatin(string $value): bool
    {
        return preg_match('/[^\p{Latin}\p{Common}\p{Inherited}\p{Nd}\p{Zs}\p{P}\p{S}]/u', $value) === 1;
    }

    private function authorsForEntry(array $entry): ?array
    {
        $author = trim((string) ($entry['author'] ?? ''));

        return $author === '' ? null : [$author];
    }

    private function isImage(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }
}
