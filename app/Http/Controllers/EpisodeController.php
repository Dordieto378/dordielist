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

            $usedNumbers = Episode::where('media_fk', $media->id)
                ->pluck('episode_number')
                ->map(fn ($number) => (int) $number)
                ->all();

            $usedMap = array_fill_keys($usedNumbers, true);
            $nextEpisode = $usedNumbers !== [] ? (max($usedNumbers) + 1) : 1;
            $rows = [];

            foreach ($videoFiles as $videoPath) {
                $basename = basename($videoPath);
                $parsedNumber = $this->parseEpisodeNumber($basename);

                if ($parsedNumber !== null) {
                    if (isset($usedMap[$parsedNumber])) {
                        throw new \RuntimeException("Episode {$parsedNumber} already exists.");
                    }

                    $episodeNumber = $parsedNumber;
                } else {
                    while (isset($usedMap[$nextEpisode])) {
                        $nextEpisode++;
                    }

                    $episodeNumber = $nextEpisode;
                }

                $usedMap[$episodeNumber] = true;
                $nextEpisode = max($nextEpisode, $episodeNumber + 1);

                $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
                $sourceName = pathinfo($basename, PATHINFO_FILENAME);
                $safeName = $uploadedArchive->sanitizePathSegment($sourceName, 'episode-'.$episodeNumber);
                $targetFilename = sprintf('episode-%03d-%s.%s', $episodeNumber, $safeName, $ext);
                $targetRelPath = $targetRelDir.'/'.$targetFilename;
                $targetAbsPath = $disk->path($targetRelPath);

                if ($disk->exists($targetRelPath)) {
                    throw new \RuntimeException("File already exists for episode {$episodeNumber}.");
                }

                if (!@rename($videoPath, $targetAbsPath)) {
                    if (!@copy($videoPath, $targetAbsPath)) {
                        throw new \RuntimeException("Could not store uploaded video '{$basename}'.");
                    }

                    @unlink($videoPath);
                }

                $createdFiles[] = $targetRelPath;

                $thumb = EpisodeThumbnailer::generate($media->id, $episodeNumber, $targetRelPath);
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

            DB::transaction(function () use ($rows, $media) {
                Episode::insert($rows);
                $media->episodes_cnt = Episode::where('media_fk', $media->id)->count();
                $media->save();
            });

            return back()->with([
                'status' => 'Uploaded '.count($rows).' episode(s).',
                'status_color' => 'green',
            ]);
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

        return back()->with([
            'status' => 'All uploaded episodes were removed.',
            'status_color' => 'green',
        ]);
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
