<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Models\Media;
use App\Support\EpisodeThumbnailer;
use App\Support\MediaStoragePath;
use App\Support\UploadedArchive;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class EpisodeController extends Controller
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
        abort_unless(in_array(strtolower((string) $media->type), ['anime', 'hentai'], true), 404);
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
        abort_unless(in_array(strtolower((string) $media->type), ['anime', 'hentai'], true), 404);
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
            return response()->json(['message' => 'Episode ZIP upload failed.'], 500);
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkRelativePath = $chunkDirectory.'/'.$this->chunkFilename($index);
                if (!$disk->exists($chunkRelativePath)) {
                    throw new \RuntimeException('Upload is incomplete. Retry the upload.');
                }

                $input = @fopen($disk->path($chunkRelativePath), 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Episode ZIP upload failed.');
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
                'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : 'Episode ZIP upload failed.',
            ], 500);
        } finally {
            if (is_file($tempAbsolutePath)) {
                @unlink($tempAbsolutePath);
            }
        }
    }

    private function processUploadedArchive(Request $request, Media $media, UploadedArchive $uploadedArchive, UploadedFile $archive)
    {
        abort_unless(in_array(strtolower((string) $media->type), ['anime', 'hentai'], true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);
        $replaceExisting = $request->boolean('replace_existing');

        if (strtolower((string) $archive->getClientOriginalExtension()) !== 'zip') {
            return $this->redirectUploadFailure('Upload a ZIP archive.', $request);
        }

        $disk = Storage::disk('public');
        $targetRelDir = MediaStoragePath::episodeDirectory($media);
        $targetSubtitleDir = MediaStoragePath::subtitleDirectory($media);
        $createdFiles = [];
        $createdThumbs = [];
        $createdSubtitles = [];
        $extractRoot = null;

        try {
            $extractRoot = $uploadedArchive->extractArchiveToTemporaryRoot($archive, 'episode-upload-');
            $contentRoot = $uploadedArchive->resolveContentRoot($extractRoot);
            $videoFiles = $uploadedArchive->listVideoFiles($contentRoot, true);
            $subtitleFiles = $uploadedArchive->listSubtitleFiles($contentRoot, true);
            $subtitleIndex = $this->indexSubtitleFiles($subtitleFiles);

            if ($videoFiles === []) {
                throw new \RuntimeException('ZIP must contain .mp4, .webm, or .mkv files.');
            }

            File::ensureDirectoryExists($disk->path($targetRelDir));
            File::ensureDirectoryExists($disk->path($targetSubtitleDir));

            $existingEpisodes = Episode::where('media_fk', $media->id)
                ->get(['id', 'episode_number', 'file_path', 'thumbnail_path', 'subtitle_path', 'top_subtitle_path', 'center_subtitle_path'])
                ->keyBy(fn (Episode $episode) => (int) $episode->episode_number);

            $usedNumbers = $existingEpisodes->keys()->map(fn ($number) => (int) $number)->all();
            $usedMap = array_fill_keys($usedNumbers, true);
            $plannedNumbers = [];
            $replacedEpisodes = [];
            $nextEpisode = $usedNumbers !== [] ? (max($usedNumbers) + 1) : 1;
            $rows = [];

            foreach ($videoFiles as $videoPath) {
                $basename = basename($videoPath);
                $parsedNumber = $this->parseEpisodeNumber($basename);
                $existingEpisode = null;

                if ($parsedNumber !== null) {
                    $existingEpisode = $existingEpisodes->get($parsedNumber);

                    if ($existingEpisode && !$replaceExisting) {
                        return $this->redirectUploadFailure("Episode {$parsedNumber} already exists.", $request, true, 409);
                    }

                    $episodeNumber = $parsedNumber;
                } else {
                    while (isset($usedMap[$nextEpisode]) || isset($plannedNumbers[$nextEpisode])) {
                        $nextEpisode++;
                    }

                    $episodeNumber = $nextEpisode;
                }

                if (isset($plannedNumbers[$episodeNumber])) {
                    throw new \RuntimeException("Episode {$episodeNumber} appears more than once in the ZIP.");
                }

                $plannedNumbers[$episodeNumber] = true;
                $nextEpisode = max($nextEpisode, $episodeNumber + 1);

                if ($existingEpisode) {
                    $replacedEpisodes[$episodeNumber] = $existingEpisode;
                }

                $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
                $sourceName = pathinfo($basename, PATHINFO_FILENAME);
                $targetFilename = sprintf('eps%d.%s', $episodeNumber, $ext);
                $targetRelPath = $targetRelDir.'/'.$targetFilename;
                $targetBaseName = pathinfo($targetFilename, PATHINFO_FILENAME);

                if ($disk->exists($targetRelPath)) {
                    if ($existingEpisode) {
                        $targetRelPath = $this->makeUniqueTargetPath($disk, $targetRelDir, $targetFilename);
                        $targetBaseName = pathinfo($targetRelPath, PATHINFO_FILENAME);
                    } else {
                        throw new \RuntimeException("File already exists for episode {$episodeNumber}.");
                    }
                }

                $targetAbsPath = $disk->path($targetRelPath);

                if (!@rename($videoPath, $targetAbsPath)) {
                    if (!@copy($videoPath, $targetAbsPath)) {
                        throw new \RuntimeException("Could not store uploaded video '{$basename}'.");
                    }

                    @unlink($videoPath);
                }

                $createdFiles[] = $targetRelPath;
                $subtitlePaths = $this->storeMatchedSubtitles(
                    $disk,
                    $subtitleIndex,
                    $sourceName,
                    $episodeNumber,
                    $targetSubtitleDir,
                    $targetBaseName,
                    $existingEpisode !== null
                );
                $createdSubtitles = array_merge($createdSubtitles, array_filter($subtitlePaths));

                $thumb = EpisodeThumbnailer::generate($media, $episodeNumber, $targetRelPath, $existingEpisode !== null);
                if ($thumb !== null && $disk->exists($thumb)) {
                    $createdThumbs[] = $thumb;
                }

                $rows[] = [
                    'media_fk' => $media->id,
                    'media_type' => strtoupper((string) $media->type),
                    'episode_number' => $episodeNumber,
                    'file_path' => $targetRelPath,
                    'thumbnail_path' => $thumb,
                    'subtitle_path' => $subtitlePaths['default'] ?? null,
                    'top_subtitle_path' => $subtitlePaths['top'] ?? null,
                    'center_subtitle_path' => $subtitlePaths['center'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $replacedEpisodeIds = array_values(array_filter(array_map(
                fn (Episode $episode) => $episode->id ?? null,
                $replacedEpisodes
            )));

            DB::transaction(function () use ($rows, $media, $replacedEpisodeIds) {
                if ($replacedEpisodeIds !== []) {
                    Episode::whereIn('id', $replacedEpisodeIds)->delete();
                }

                Episode::insert($rows);
                if (($media->source ?? null) !== 'anilist') {
                    $media->episodes_cnt = Episode::where('media_fk', $media->id)->count();
                }
                $media->save();
            });

            $newThumbsByEpisode = [];
            foreach ($rows as $row) {
                $newThumbsByEpisode[(int) $row['episode_number']] = $row['thumbnail_path'] ?? null;
            }

            $oldThumbsToDelete = [];
            $oldFilesToDelete = [];
            $oldSubtitlesToDelete = [];
            foreach ($replacedEpisodes as $episodeNumber => $existingEpisode) {
                if (!empty($existingEpisode->file_path)) {
                    $oldFilesToDelete[] = $existingEpisode->file_path;
                }

                foreach (['subtitle_path', 'top_subtitle_path', 'center_subtitle_path'] as $subtitleColumn) {
                    if (!empty($existingEpisode->{$subtitleColumn})) {
                        $oldSubtitlesToDelete[] = $existingEpisode->{$subtitleColumn};
                    }
                }

                $existingThumb = $existingEpisode->thumbnail_path ?? null;
                $newThumb = $newThumbsByEpisode[(int) $episodeNumber] ?? null;
                if ($existingThumb && $existingThumb !== $newThumb) {
                    $oldThumbsToDelete[] = $existingThumb;
                }
            }

            $this->cleanupCreatedFiles($disk, $oldThumbsToDelete);
            $this->cleanupCreatedFiles($disk, $oldSubtitlesToDelete);
            $this->cleanupCreatedFiles($disk, $oldFilesToDelete);

            return $this->uploadSuccessResponse($request);
        } catch (Throwable $e) {
            report($e);
            $this->cleanupCreatedFiles($disk, $createdThumbs);
            $this->cleanupCreatedFiles($disk, $createdSubtitles);
            $this->cleanupCreatedFiles($disk, $createdFiles);
            $this->cleanupEmptyDirectory($disk, $targetRelDir);
            $this->cleanupEmptyDirectory($disk, $targetSubtitleDir);

            $message = trim($e->getMessage()) !== ''
                ? $e->getMessage()
                : 'Episode ZIP upload failed.';

            return $this->redirectUploadFailure($message, $request);
        } finally {
            if ($extractRoot !== null && File::isDirectory($extractRoot)) {
                File::deleteDirectory($extractRoot);
            }
        }
    }

    private function chunkDirectory(Media $media, string $uploadId): string
    {
        return 'media-upload-chunks/episodes/'.$media->id.'/'.$uploadId;
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
        abort_unless(in_array(strtolower((string) $media->type), ['anime', 'hentai'], true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $disk = Storage::disk('public');
        $targetRelDirs = [
            MediaStoragePath::episodeDirectory($media),
            MediaStoragePath::subtitleDirectory($media),
            MediaStoragePath::thumbnailDirectory($media),
        ];

        DB::transaction(function () use ($media) {
            Episode::where('media_fk', $media->id)->delete();
            if (($media->source ?? null) !== 'anilist') {
                $media->episodes_cnt = 0;
            }
            $media->save();
        });

        foreach ($targetRelDirs as $targetRelDir) {
            if ($disk->directoryExists($targetRelDir)) {
                $disk->deleteDirectory($targetRelDir);
            }
        }

        return back();
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

    private function indexSubtitleFiles(array $subtitleFiles): array
    {
        $index = [
            'by_name' => [],
            'by_episode' => [],
        ];

        foreach ($subtitleFiles as $path) {
            $filename = pathinfo($path, PATHINFO_FILENAME);
            $type = 'default';
            $base = $filename;

            if (preg_match('/(?:[.\-_\s]+top)\z/i', $base)) {
                $type = 'top';
                $base = preg_replace('/(?:[.\-_\s]+top)\z/i', '', $base) ?: $base;
            } elseif (preg_match('/(?:[.\-_\s]+center)\z/i', $base)) {
                $type = 'center';
                $base = preg_replace('/(?:[.\-_\s]+center)\z/i', '', $base) ?: $base;
            }

            $entry = ['path' => $path, 'type' => $type, 'base' => $base];
            $nameKey = $this->normalizeSubtitleMatchKey($base);
            if ($nameKey !== '') {
                $index['by_name'][$nameKey][$type] = $entry;
            }

            $episodeNumber = $this->parseEpisodeNumber($base);
            if ($episodeNumber !== null && !isset($index['by_episode'][$episodeNumber][$type])) {
                $index['by_episode'][$episodeNumber][$type] = $entry;
            }
        }

        return $index;
    }

    private function storeMatchedSubtitles($disk, array $subtitleIndex, string $sourceName, int $episodeNumber, string $targetDir, string $targetBaseName, bool $replaceExisting): array
    {
        $matches = $subtitleIndex['by_name'][$this->normalizeSubtitleMatchKey($sourceName)]
            ?? $subtitleIndex['by_episode'][$episodeNumber]
            ?? [];

        $stored = [
            'default' => null,
            'top' => null,
            'center' => null,
        ];

        foreach ([
            'default' => '.vtt',
            'top' => '.top.vtt',
            'center' => '.center.vtt',
        ] as $type => $suffix) {
            if (empty($matches[$type]['path'])) {
                continue;
            }

            $targetPath = $targetDir.'/'.$targetBaseName.$suffix;
            if ($disk->exists($targetPath)) {
                if (!$replaceExisting) {
                    throw new \RuntimeException("Subtitle already exists for episode {$episodeNumber}.");
                }

                $disk->delete($targetPath);
            }

            File::ensureDirectoryExists(dirname($disk->path($targetPath)));
            if (!@copy($matches[$type]['path'], $disk->path($targetPath))) {
                throw new \RuntimeException("Could not store subtitle for episode {$episodeNumber}.");
            }

            $stored[$type] = $targetPath;
        }

        return $stored;
    }

    private function normalizeSubtitleMatchKey(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }

    private function cleanupCreatedFiles($disk, array $paths): void
    {
        foreach (array_reverse($paths) as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
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

    private function makeUniqueTargetPath($disk, string $directory, string $filename): string
    {
        $directory = trim($directory, '/');
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $candidate = $directory.'/'.$filename;
        $suffix = 2;

        while ($disk->exists($candidate)) {
            $candidate = sprintf(
                '%s/%s-%d%s',
                $directory,
                $name,
                $suffix,
                $extension !== '' ? '.'.$extension : ''
            );
            $suffix++;
        }

        return $candidate;
    }

    private function parseEpisodeNumber(string $filename): ?int
    {
        $name = strtolower($filename);

        if (preg_match('/(?:episode|ep|e)[\s\-_]*([0-9]{1,3})/i', $name, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\b([0-9]{1,3})\b/', $name, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function stream(Request $request, Media $media, Episode $episode)
    {
        abort_unless((int) $episode->media_fk === (int) $media->id, 404);
        abort_unless(!empty($episode->file_path), 404);

        $disk = Storage::disk('public');
        abort_unless($disk->exists($episode->file_path), 404);

        $path = $disk->path($episode->file_path);
        $size = filesize($path);
        $mime = File::mimeType($path) ?: 'video/mp4';
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $range = (string) $request->headers->get('Range', '');
        if (preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
            if ($matches[1] !== '') {
                $start = max(0, (int) $matches[1]);
            }

            if ($matches[2] !== '') {
                $end = min($end, (int) $matches[2]);
            }

            if ($matches[1] === '' && $matches[2] !== '') {
                $suffixLength = min((int) $matches[2], $size);
                $start = $size - $suffixLength;
                $end = $size - 1;
            }

            if ($start > $end || $start >= $size) {
                return response('', 416, [
                    'Content-Range' => 'bytes */'.$size,
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        return response()->stream(function () use ($path, $start, $length) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }

            try {
                fseek($handle, $start);
                $remaining = $length;

                while ($remaining > 0 && !feof($handle)) {
                    $chunkSize = min(1024 * 1024, $remaining);
                    $chunk = fread($handle, $chunkSize);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $remaining -= strlen($chunk);

                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                fclose($handle);
            }
        }, $status, $headers);
    }
    public function show($mediaId, $episodeNumber)
    {
        $media = Media::with(['anilistGenres:id,name', 'anilistTags:id,name'])->findOrFail($mediaId);
        $episode = Episode::where('media_fk', $mediaId)
            ->where('episode_number', $episodeNumber)
            ->firstOrFail();

        $descHtml = $media->description ?? '';
        $desc = preg_replace('/<\s*br\s*\/?>/i', "\n", $descHtml);
        $desc = preg_replace('/<\/p>\s*<p>/i', "\n\n", $desc);
        $desc = strip_tags($desc);
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = preg_replace("/\r\n?/", "\n", $desc);
        $desc = preg_replace("/[ \t]+$/m", '', $desc);
        $desc = preg_replace("/\n{3,}/", "\n\n", $desc);
        $desc = trim($desc);

        $item = [
            'id' => $media->id,
            'type' => strtoupper($media->type),
            'title' => [
                'english' => $media->title_english,
                'romaji' => $media->title_romaji,
                'native' => $media->title_native,
            ],
            'coverImage' => ['extraLarge' => $media->cover_url ?: asset('images/no-image.jpg')],
            'description' => $desc,
            'genres' => $media->metadataNamesFrom('anilistGenres'),
            'tags' => $media->metadataNamesFrom('anilistTags'),
            'averageScore' => $media->avg_score,
            'episodes' => null,
            'chapters' => null,
            'volumes' => null,
            'status' => $media->media_status,
            'isAdult' => (bool) $media->is_adult,
            'startDate' => ['year' => $media->year, 'month' => null, 'day' => null],
            'countryOfOrigin' => $media->origin,
            'mediaListEntry' => [
                'score' => $media->user_score,
                'progress' => $media->progress,
                'status' => $media->list_status,
            ],
            'releaseDate' => $media->start_date,
        ];

        $genres = $item['genres'] ?? [];
        $type = $item['type'] ?? '';
        $origin = strtoupper($item['countryOfOrigin'] ?? '');
        if ($type === 'HENTAI' || ($type === 'ANIME' && in_array('Hentai', $genres, true))) {
            $category = 'hentais';
        } elseif ($type === 'ANIME') {
            $category = 'animes';
        } elseif ($type === 'MANGA') {
            $category = ($origin === 'KR') ? 'manhwas' : 'mangas';
        } else {
            $category = 'animes';
        }

        $episodes = Episode::where('media_fk', $mediaId)
            ->orderBy('episode_number')
            ->get(['id', 'episode_number', 'thumbnail_path', 'file_path']);

        return view('episodes.show', [
            'item' => $item,
            'episode' => $episode,
            'category' => $category,
            'episodes' => $episodes,
        ]);
    }
}
