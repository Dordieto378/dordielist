<?php

namespace App\Http\Controllers;

use App\Models\Episode;
use App\Models\Media;
use App\Support\EpisodeThumbnailer;
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
        abort_unless(in_array(strtolower((string) $media->type), ['anime', 'hentai'], true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);
        $replaceExisting = $request->boolean('replace_existing');

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
        $targetRelDir = strtolower((string) $media->type).'/'.$media->id;
        $createdFiles = [];
        $createdThumbs = [];
        $extractRoot = null;

        try {
            $extractRoot = $uploadedArchive->extractArchiveToTemporaryRoot($archive, 'episode-upload-');
            $contentRoot = $uploadedArchive->resolveContentRoot($extractRoot);
            $videoFiles = $uploadedArchive->listVideoFiles($contentRoot, true);

            if ($videoFiles === []) {
                throw new \RuntimeException('ZIP must contain .mp4 or .webm files.');
            }

            File::ensureDirectoryExists($disk->path($targetRelDir));

            $existingEpisodes = Episode::where('media_fk', $media->id)
                ->get(['id', 'episode_number', 'file_path', 'thumbnail_path'])
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
                $safeName = $uploadedArchive->sanitizePathSegment($sourceName, 'episode-'.$episodeNumber);
                $targetFilename = sprintf('episode-%03d-%s.%s', $episodeNumber, $safeName, $ext);
                $targetRelPath = $targetRelDir.'/'.$targetFilename;

                if ($disk->exists($targetRelPath)) {
                    if ($existingEpisode) {
                        $targetRelPath = $this->makeUniqueTargetPath($disk, $targetRelDir, $targetFilename);
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

                $thumb = EpisodeThumbnailer::generate($media->id, $episodeNumber, $targetRelPath, $existingEpisode !== null);
                if ($thumb !== null && $disk->exists($thumb)) {
                    $createdThumbs[] = $thumb;
                }

                $rows[] = [
                    'media_fk' => $media->id,
                    'media_type' => strtoupper((string) $media->type),
                    'episode_number' => $episodeNumber,
                    'file_path' => $targetRelPath,
                    'thumbnail_path' => $thumb,
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
                $media->episodes_cnt = Episode::where('media_fk', $media->id)->count();
                $media->save();
            });

            $newThumbsByEpisode = [];
            foreach ($rows as $row) {
                $newThumbsByEpisode[(int) $row['episode_number']] = $row['thumbnail_path'] ?? null;
            }

            $oldThumbsToDelete = [];
            $oldFilesToDelete = [];
            foreach ($replacedEpisodes as $episodeNumber => $existingEpisode) {
                if (!empty($existingEpisode->file_path)) {
                    $oldFilesToDelete[] = $existingEpisode->file_path;
                }

                $existingThumb = $existingEpisode->thumbnail_path ?? null;
                $newThumb = $newThumbsByEpisode[(int) $episodeNumber] ?? null;
                if ($existingThumb && $existingThumb !== $newThumb) {
                    $oldThumbsToDelete[] = $existingThumb;
                }
            }

            $this->cleanupCreatedFiles($disk, $oldThumbsToDelete);
            $this->cleanupCreatedFiles($disk, $oldFilesToDelete);

            return $this->uploadSuccessResponse($request);
        } catch (Throwable $e) {
            report($e);
            $this->cleanupCreatedFiles($disk, $createdThumbs);
            $this->cleanupCreatedFiles($disk, $createdFiles);
            $this->cleanupEmptyDirectory($disk, $targetRelDir);

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

    public function resetUploaded(Request $request, Media $media)
    {
        abort_unless(in_array(strtolower((string) $media->type), ['anime', 'hentai'], true), 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $disk = Storage::disk('public');
        $targetRelDir = strtolower((string) $media->type).'/'.$media->id;

        DB::transaction(function () use ($media) {
            Episode::where('media_fk', $media->id)->delete();
            $media->episodes_cnt = 0;
            $media->save();
        });

        if ($disk->exists($targetRelDir)) {
            $disk->deleteDirectory($targetRelDir);
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
            $category = ($origin === 'KR') ? 'manwhas' : 'mangas';
        } else {
            $category = 'animes';
        }

        $episodes = Episode::where('media_fk', $mediaId)
            ->orderBy('episode_number')
            ->get(['episode_number', 'thumbnail_path']);

        return view('episodes.show', [
            'item' => $item,
            'episode' => $episode,
            'category' => $category,
            'episodes' => $episodes,
        ]);
    }
}
