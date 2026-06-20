<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Support\DoujinFolderIndex;
use App\Support\DoujinAuthorLinks;
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
        $media = Media::with(['doujinAuthors'])
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
        $allAuthorRows = DoujinAuthor::query()
            ->orderBy('name')
            ->get(['name', 'twitter_url', 'patreon_url', 'fanbox_url', 'pixiv_url']);
        $allAuthors = $allAuthorRows->pluck('name');
        $allAuthorLinks = $allAuthorRows
            ->mapWithKeys(fn (DoujinAuthor $author) => [
                $author->name => DoujinAuthorLinks::payload($author),
            ]);
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
            'allAuthorLinks' => $allAuthorLinks,
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
            'author_twitter_url' => ['nullable', 'array'],
            'author_twitter_url.*' => ['nullable', 'string', 'max:2048'],
            'author_patreon_url' => ['nullable', 'array'],
            'author_patreon_url.*' => ['nullable', 'string', 'max:2048'],
            'author_fanbox_url' => ['nullable', 'array'],
            'author_fanbox_url.*' => ['nullable', 'string', 'max:2048'],
            'author_pixiv_url' => ['nullable', 'array'],
            'author_pixiv_url.*' => ['nullable', 'string', 'max:2048'],
            'doujin_source' => ['nullable', 'in:official,unofficial'],
            'doujin_language' => ['nullable', 'in:japanese,english'],
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
        $media->doujin_source = $this->trimToNull($data['doujin_source'] ?? null);
        $media->doujin_language = $this->trimToNull($data['doujin_language'] ?? null);
        $media->slug = $this->makeUniqueMediaSlug(
            $media,
            $titleRomaji ?: ($titleEnglish ?: ($titleNative ?: ($media->slug ?: 'doujin-'.$media->id)))
        );
        $media->save();

        $this->metadataSyncer->syncDoujin($media, $authors);
        $this->syncDoujinAuthorLinks($authors, $data);

        return back();
    }

    public function download(Media $media)
    {
        abort_unless($media->type === 'doujin', 404);

        $chapters = Chapter::with(['pages' => fn ($query) => $query->orderBy('page_number')])
            ->where('item_type', 'doujin')
            ->where('media_fk', $media->id)
            ->orderBy('chapter_number')
            ->get();

        if ($chapters->isEmpty()) {
            return back()->with('status', 'No chapters are available to download.');
        }

        $token = (string) Str::uuid();
        $stageRoot = storage_path('app/tmp/doujin-download-'.$token);
        $zipPath = storage_path('app/tmp/doujin-download-'.$token.'.zip');
        $downloadName = $this->doujinDownloadFilename($media);
        $disk = Storage::disk('public');
        $copied = 0;

        try {
            File::ensureDirectoryExists($stageRoot);

            foreach ($chapters as $chapterIndex => $chapter) {
                if ($chapter->pages->isEmpty()) {
                    continue;
                }

                $chapterFolder = sprintf(
                    '%03d-%s',
                    $chapterIndex + 1,
                    $this->zipSafeSegment((string) ($chapter->chapter_title ?: 'chapter-'.$chapter->chapter_number), 'chapter')
                );
                $chapterPath = $stageRoot.DIRECTORY_SEPARATOR.$chapterFolder;
                File::ensureDirectoryExists($chapterPath);

                foreach ($chapter->pages as $pageIndex => $page) {
                    if (!$disk->exists($page->file_path)) {
                        continue;
                    }

                    $sourcePath = $disk->path($page->file_path);
                    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'jpg';
                    $targetPath = $chapterPath.DIRECTORY_SEPARATOR.sprintf('%03d.%s', $pageIndex + 1, $extension);

                    if (!@copy($sourcePath, $targetPath)) {
                        throw new \RuntimeException('Could not prepare the doujin download.');
                    }

                    $copied++;
                }
            }

            if ($copied === 0) {
                File::deleteDirectory($stageRoot);

                return back()->with('status', 'No chapter files are available to download.');
            }

            $this->createZipFromDirectory($stageRoot, $zipPath);
            File::deleteDirectory($stageRoot);

            return response()
                ->download($zipPath, $downloadName)
                ->deleteFileAfterSend(true);
        } catch (Throwable $e) {
            report($e);

            if (File::isDirectory($stageRoot)) {
                File::deleteDirectory($stageRoot);
            }

            if (is_file($zipPath)) {
                @unlink($zipPath);
            }

            return back()->with('status', 'Could not prepare the doujin download.');
        }
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

        return $this->processUploadedArchive($request, $archive);
    }

    public function uploadChunk(Request $request)
    {
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
            $this->chunkDirectory($uploadId),
            $chunk,
            $this->chunkFilename($chunkIndex)
        );

        if (!$storedPath) {
            return response()->json(['message' => 'Chunk upload failed.'], 500);
        }

        return response()->json(['ok' => true, 'chunk_index' => $chunkIndex]);
    }

    public function completeUpload(Request $request)
    {
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
        $chunkDirectory = $this->chunkDirectory($uploadId);
        $tempRelativePath = $chunkDirectory.'/archive.zip';
        $tempAbsolutePath = $disk->path($tempRelativePath);
        $output = @fopen($tempAbsolutePath, 'wb');

        if ($output === false) {
            return response()->json(['message' => 'Upload failed. Check the ZIP structure and try again.'], 500);
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkRelativePath = $chunkDirectory.'/'.$this->chunkFilename($index);
                if (!$disk->exists($chunkRelativePath)) {
                    throw new \RuntimeException('Upload is incomplete. Retry the upload.');
                }

                $input = @fopen($disk->path($chunkRelativePath), 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Upload failed. Check the ZIP structure and try again.');
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
            }

            fclose($output);
            $archive = new UploadedFile($tempAbsolutePath, $originalName, 'application/zip', null, true);
            $response = $this->processUploadedArchive($request, $archive);

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
                'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : 'Upload failed. Check the ZIP structure and try again.',
            ], 500);
        } finally {
            if (is_file($tempAbsolutePath)) {
                @unlink($tempAbsolutePath);
            }
        }
    }

    private function processUploadedArchive(Request $request, UploadedFile $archive)
    {
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $validator = Validator::make($request->all(), [
            'title_english' => ['nullable', 'string', 'max:255'],
            'title_romaji' => ['nullable', 'string', 'max:255'],
            'title_native' => ['nullable', 'string', 'max:255'],
            'existing_author' => ['nullable', 'string', 'max:255'],
            'new_author' => ['nullable', 'string', 'max:255'],
            'author_twitter_url' => ['nullable', 'array'],
            'author_twitter_url.*' => ['nullable', 'string', 'max:2048'],
            'author_patreon_url' => ['nullable', 'array'],
            'author_patreon_url.*' => ['nullable', 'string', 'max:2048'],
            'author_fanbox_url' => ['nullable', 'array'],
            'author_fanbox_url.*' => ['nullable', 'string', 'max:2048'],
            'author_pixiv_url' => ['nullable', 'array'],
            'author_pixiv_url.*' => ['nullable', 'string', 'max:2048'],
            'doujin_source' => ['nullable', 'in:official,unofficial'],
            'doujin_language' => ['nullable', 'in:japanese,english'],
        ]);

        if ($validator->fails()) {
            return $this->redirectUploadFailure($validator->errors()->first(), $request);
        }

        $data = $validator->validated();
        $titleEnglish = $this->trimToNull($data['title_english'] ?? null);
        $titleRomaji = $this->trimToNull($data['title_romaji'] ?? null);
        $titleNative = $this->trimToNull($data['title_native'] ?? null);
        $author = $this->trimToNull($data['new_author'] ?? null)
            ?: $this->trimToNull($data['existing_author'] ?? null);

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
            $media->doujin_source = $this->trimToNull($data['doujin_source'] ?? null);
            $media->doujin_language = $this->trimToNull($data['doujin_language'] ?? null);
            $media->slug = $this->makeUniqueMediaSlug(
                $media,
                $titleRomaji ?: ($titleEnglish ?: ($titleNative ?: 'doujin'))
            );
            $media->cover_url = null;
            $media->chapters_cnt = 0;
            $media->save();

            $this->metadataSyncer->syncDoujin($media, [$author]);
            $this->syncDoujinAuthorLinks([$author], $data, false);

            $disk = Storage::disk('public');
            $targetRel = 'doujin/'.$media->id;
            $targetAbs = $disk->path($targetRel);
            File::ensureDirectoryExists($targetAbs);

            $this->stageExtractedDoujin($importRoot, $targetAbs);
            $this->mirrorDoujin($disk, $targetRel, $media->id);

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'redirect_url' => route('doujins.show', ['media' => $media->id]),
                ]);
            }

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
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()
            ->withInput($request->except('archive'))
            ->with('open_add_doujin_modal', true)
            ->with('doujin_upload_error', $message);
    }

    private function chunkDirectory(string $uploadId): string
    {
        return 'doujin-upload-chunks/'.$uploadId;
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

    private function createZipFromDirectory(string $sourceDirectory, string $zipPath): void
    {
        File::ensureDirectoryExists(dirname($zipPath));

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            if ($opened !== true) {
                throw new \RuntimeException('Could not create the ZIP archive.');
            }

            $this->addDirectoryToZip($zip, $sourceDirectory, $sourceDirectory);
            $zip->close();

            if (!is_file($zipPath) || filesize($zipPath) === 0) {
                throw new \RuntimeException('Could not create the ZIP archive.');
            }

            return;
        }

        $command = sprintf(
            "Compress-Archive -Path '%s\\*' -DestinationPath '%s' -Force",
            str_replace("'", "''", $sourceDirectory),
            str_replace("'", "''", $zipPath)
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
        $process->setTimeout(600);
        $process->run();

        if ($process->isSuccessful() && is_file($zipPath) && filesize($zipPath) > 0) {
            return;
        }

        $tarPath = 'C:\Windows\System32\tar.exe';
        if (is_file($tarPath)) {
            $process = new Process([
                $tarPath,
                '-a',
                '-cf',
                $zipPath,
                '-C',
                $sourceDirectory,
                '*',
            ]);
            $process->setTimeout(600);
            $process->run();

            if ($process->isSuccessful() && is_file($zipPath) && filesize($zipPath) > 0) {
                return;
            }
        }

        $errorOutput = trim($process->getErrorOutput().' '.$process->getOutput());
        throw new \RuntimeException($errorOutput !== '' ? $errorOutput : 'Could not create the ZIP archive.');
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $rootDirectory, string $directory): void
    {
        $files = glob($directory.DIRECTORY_SEPARATOR.'*') ?: [];

        foreach ($files as $file) {
            $relativePath = ltrim(substr($file, strlen($rootDirectory)), DIRECTORY_SEPARATOR);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

            if (is_dir($file)) {
                $zip->addEmptyDir($relativePath);
                $this->addDirectoryToZip($zip, $rootDirectory, $file);
                continue;
            }

            if (is_file($file)) {
                $zip->addFile($file, $relativePath);
            }
        }
    }

    private function doujinDownloadFilename(Media $media): string
    {
        $title = $media->title_english
            ?: ($media->title_romaji ?: ($media->title_native ?: ($media->slug ?: 'doujin-'.$media->id)));
        $base = Str::slug($title);

        return ($base !== '' ? $base : 'doujin-'.$media->id).'.zip';
    }

    private function zipSafeSegment(string $value, string $fallback): string
    {
        $segment = Str::slug($value);

        return $segment !== '' ? $segment : $fallback;
    }

    private function trimToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function syncDoujinAuthorLinks(array $authorNames, array $data, bool $clearMissing = true): void
    {
        $links = [
            'twitter_url' => DoujinAuthorLinks::store($data['author_twitter_url'] ?? null),
            'patreon_url' => DoujinAuthorLinks::store($data['author_patreon_url'] ?? null),
            'fanbox_url' => DoujinAuthorLinks::store($data['author_fanbox_url'] ?? null),
            'pixiv_url' => DoujinAuthorLinks::store($data['author_pixiv_url'] ?? null),
        ];

        if (!$clearMissing) {
            $links = array_filter($links, fn (?string $value) => $value !== null);
        }

        foreach ($authorNames as $authorName) {
            $authorName = $this->trimToNull($authorName);
            if ($authorName === null) {
                continue;
            }

            $author = DoujinAuthor::firstOrCreate(['name' => $authorName]);
            $author->forceFill($links)->save();
        }
    }

    private function parseAuthorNames(?string $value): array
    {
        return $this->parseNames($value);
    }

    private function parseNames(?string $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
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
