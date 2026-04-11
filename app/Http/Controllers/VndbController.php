<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use App\Models\Media;
use App\Support\MediaMetadataSyncer;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VndbController extends Controller
{
    private array $labelIdsByStatus = [
        'PLAYING' => 1,
        'FINISHED' => 2,
        'STALLED' => 3,
        'DROPPED' => 4,
        'WISHLIST' => 5,
    ];

    private array $labelMapDb = [
        'playing' => 'PLAYING',
        'finished' => 'FINISHED',
        'stalled' => 'STALLED',
        'dropped' => 'DROPPED',
        'wishlist' => 'WISHLIST',
    ];

    public function __construct(private readonly MediaMetadataSyncer $metadataSyncer)
    {
    }

    public function getUserVnList(string $username = '', string $status = '')
    {
        $q = Media::query()
            ->where('type', 'vn')
            ->with(['vnTags:id,name', 'vnLanguages:id,name', 'vnDevelopers:id,name']);

        if ($status && isset($this->labelMapDb[strtolower($status)])) {
            $q->where('list_status', $this->labelMapDb[strtolower($status)]);
        }

        $req = request();

        foreach ($this->csv($req->query('language')) as $language) {
            $q->whereHas('vnLanguages', fn ($query) => $query->where('name', $language));
        }

        foreach ($this->csv($req->query('developers')) as $developer) {
            $q->whereHas('vnDevelopers', fn ($query) => $query->where('name', $developer));
        }

        foreach ($this->csv($req->query('tags')) as $tag) {
            $q->whereHas('vnTags', fn ($query) => $query->where('name', $tag));
        }

        if ($year = $req->query('year')) {
            $q->where('year', (int) $year);
        }

        $titleOrder = $req->query('title_order', 'none');
        $scoreOrder = $req->query('score_order', 'none');
        $yearOrder = $req->query('year_order', 'none');

        if ($titleOrder === 'az') {
            $q->orderByRaw('COALESCE(NULLIF(title_english,""), NULLIF(title_romaji,""), NULLIF(title_native,""), slug) asc');
        } elseif ($titleOrder === 'za') {
            $q->orderByRaw('COALESCE(NULLIF(title_english,""), NULLIF(title_romaji,""), NULLIF(title_native,""), slug) desc');
        }

        if ($scoreOrder === 'avg_desc') {
            $q->orderBy('avg_score', 'desc');
        } elseif ($scoreOrder === 'avg_asc') {
            $q->orderBy('avg_score', 'asc');
        } elseif ($scoreOrder === 'personal_desc') {
            $q->orderBy('user_score', 'desc');
        } elseif ($scoreOrder === 'personal_asc') {
            $q->orderBy('user_score', 'asc');
        }

        if ($yearOrder === 'year_desc') {
            $q->orderBy('year', 'desc');
        } elseif ($yearOrder === 'year_asc') {
            $q->orderBy('year', 'asc');
        }

        $q->orderBy('start_date', 'desc');

        $paginator = $q->paginate(24)->appends($req->query());

        $media = $paginator->getCollection()->map(function (Media $media) {
            $title = $media->title_english ?: ($media->title_romaji ?: ($media->title_native ?: 'No Title'));
            $tags = array_map(fn ($tag) => ['name' => $tag], $media->metadataNamesFrom('vnTags'));
            $developers = array_map(fn ($developer) => ['name' => $developer], $media->metadataNamesFrom('vnDevelopers'));
            $languages = $media->metadataNamesFrom('vnLanguages');

            $hasNoSex = false;
            foreach ($media->metadataNamesFrom('vnTags') as $tag) {
                if (mb_strtolower($tag) === 'no sexual content') {
                    $hasNoSex = true;
                    break;
                }
            }

            return [
                'id' => (int) $media->id,
                'title' => $title,
                'image' => ['url' => $media->cover_url],
                'tags' => $tags,
                'developers' => $developers,
                'languages' => $languages,
                'status' => $media->list_status ? strtolower($media->list_status) : null,
                'score' => (int) ($media->user_score ?? 0),
                'average' => (float) ($media->avg_score ?? 0),
                'year' => (int) ($media->year ?? 0),
                'hasNoSexualContent' => $hasNoSex,
            ];
        });

        $paginator->setCollection($media);

        return $paginator;
    }

    private function csv(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    public function show(string $rawId)
    {
        $id = (int) ltrim($rawId, 'v');

        $vn = $this->fetchVnById($id);
        if (!$vn) {
            abort(404);
        }

        $category = 'visual-novel';

        $isFavorited = Favorite::where([
            ['favoritable_type', $category],
            ['favoritable_id', $id],
        ])->exists();

        $allCollections = Collection::orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();

        $attachedIds = CollectionItem::where('item_type', $category)
            ->where('item_id', $id)
            ->pluck('collection_id')
            ->toArray();

        $media = $vn['media'] ?? null;
        $hasUploadedGame = $media instanceof Media
            ? Storage::disk('local')->exists($this->vnGameStoragePath($media))
            : false;
        $gameDownloadFilename = $media instanceof Media
            ? $this->vnGameDownloadFilename($media)
            : null;

        return view('media.vndb', [
            'item' => $vn,
            'category' => $category,
            'isFavorited' => $isFavorited,
            'allCollections' => $allCollections,
            'attachedIds' => $attachedIds,
            'hasUploadedGame' => $hasUploadedGame,
            'gameDownloadFilename' => $gameDownloadFilename,
        ]);
    }

    public function updateEntry(Request $request, Media $media)
    {
        abort_unless($media->type === 'vn', 404);

        $validator = Validator::make($request->all(), [
            'list_status' => ['required', 'in:PLAYING,FINISHED,STALLED,DROPPED,WISHLIST'],
            'user_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            return back()
                ->withInput()
                ->with('open_vn_edit_modal', true)
                ->with('vn_entry_update_error', $validator->errors()->first());
        }

        $token = Auth::user()?->vndb_api_token;
        if (!$token) {
            return back()
                ->withInput()
                ->with('open_vn_edit_modal', true)
                ->with('vn_entry_update_error', 'Add your VNDB API token in API settings first.');
        }

        $vndbId = (int) ($media->source_id ?: $media->id);
        if ($vndbId <= 0) {
            return back()
                ->withInput()
                ->with('open_vn_edit_modal', true)
                ->with('vn_entry_update_error', 'This entry is not linked to a VNDB record.');
        }

        $listStatus = (string) $request->input('list_status');
        $labelId = $this->labelIdsByStatus[$listStatus] ?? null;
        if (!$labelId) {
            return back()
                ->withInput()
                ->with('open_vn_edit_modal', true)
                ->with('vn_entry_update_error', 'Invalid list status.');
        }

        $scoreInput = $request->input('user_score');
        $scoreRaw = $scoreInput === null || $scoreInput === ''
            ? null
            : (int) $scoreInput;

        $response = $this->vndbClient($token)->patch('ulist/v'.$vndbId, [
            'labels' => [$labelId],
            'vote' => $scoreRaw === null || $scoreRaw === 0 ? null : $scoreRaw,
        ]);

        if (!$response->successful()) {
            $errorMessage = $response->json('message')
                ?: $response->json('errors.0.message')
                ?: $response->json('detail')
                ?: 'VNDB update failed.';

            return back()
                ->withInput()
                ->with('open_vn_edit_modal', true)
                ->with('vn_entry_update_error', $errorMessage);
        }

        $media->list_status = $listStatus;
        $media->user_score = $scoreRaw === 0 ? null : $scoreRaw;
        $media->save();

        return back();
    }

    public function uploadGame(Request $request, Media $media)
    {
        abort_unless($media->type === 'vn', 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $archive = $request->file('game_archive');
        if (!$archive instanceof UploadedFile || !$archive->isValid()) {
            return $this->vnGameUploadError('Upload a ZIP archive.');
        }

        if (strtolower((string) $archive->getClientOriginalExtension()) !== 'zip') {
            return $this->vnGameUploadError('Upload a ZIP archive.');
        }

        $storedPath = Storage::disk('local')->putFileAs(
            $this->vnGameStorageDirectory($media),
            $archive,
            'game.zip'
        );

        if (!$storedPath) {
            return $this->vnGameUploadError('Game upload failed.');
        }

        return back();
    }

    public function uploadGameChunk(Request $request, Media $media)
    {
        abort_unless($media->type === 'vn', 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $uploadId = $this->normalizeGameUploadId($request->input('upload_id'));
        $chunkIndex = filter_var($request->input('chunk_index'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $totalChunks = filter_var($request->input('total_chunks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $chunk = $request->file('game_chunk');

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
            $this->vnGameChunkDirectory($media, $uploadId),
            $chunk,
            $this->vnGameChunkFilename($chunkIndex)
        );

        if (!$storedPath) {
            return response()->json(['message' => 'Chunk upload failed.'], 500);
        }

        return response()->json([
            'ok' => true,
            'chunk_index' => $chunkIndex,
        ]);
    }

    public function completeGameUpload(Request $request, Media $media)
    {
        abort_unless($media->type === 'vn', 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $uploadId = $this->normalizeGameUploadId($request->input('upload_id'));
        $totalChunks = filter_var($request->input('total_chunks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $originalName = trim((string) $request->input('original_name'));

        if (!$uploadId || $totalChunks === false) {
            return response()->json(['message' => 'Invalid upload request.'], 422);
        }

        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            return response()->json(['message' => 'Upload a ZIP archive.'], 422);
        }

        $disk = Storage::disk('local');
        $chunkDirectory = $this->vnGameChunkDirectory($media, $uploadId);
        $targetDirectory = $this->vnGameStorageDirectory($media);
        $temporaryRelativePath = $targetDirectory.'/game.uploading';
        $finalRelativePath = $this->vnGameStoragePath($media);
        $temporaryAbsolutePath = $disk->path($temporaryRelativePath);
        $finalAbsolutePath = $disk->path($finalRelativePath);
        $finalDirectory = dirname($finalAbsolutePath);

        if (!is_dir($finalDirectory) && !mkdir($finalDirectory, 0775, true) && !is_dir($finalDirectory)) {
            return response()->json(['message' => 'Game upload failed.'], 500);
        }

        $output = @fopen($temporaryAbsolutePath, 'wb');
        if ($output === false) {
            return response()->json(['message' => 'Game upload failed.'], 500);
        }

        try {
            for ($index = 0; $index < $totalChunks; $index++) {
                $chunkRelativePath = $chunkDirectory.'/'.$this->vnGameChunkFilename($index);
                if (!$disk->exists($chunkRelativePath)) {
                    throw new \RuntimeException('Upload is incomplete. Retry the upload.');
                }

                $input = @fopen($disk->path($chunkRelativePath), 'rb');
                if ($input === false) {
                    throw new \RuntimeException('Game upload failed.');
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
            }

            fclose($output);

            if (is_file($finalAbsolutePath)) {
                @unlink($finalAbsolutePath);
            }

            if (!@rename($temporaryAbsolutePath, $finalAbsolutePath)) {
                throw new \RuntimeException('Game upload failed.');
            }

            $disk->deleteDirectory($chunkDirectory);

            return response()->json(['ok' => true]);
        } catch (\Throwable $exception) {
            if (is_resource($output)) {
                fclose($output);
            }

            if (is_file($temporaryAbsolutePath)) {
                @unlink($temporaryAbsolutePath);
            }

            return response()->json(['message' => $exception->getMessage() ?: 'Game upload failed.'], 500);
        }
    }

    public function downloadGame(Media $media)
    {
        abort_unless($media->type === 'vn', 404);

        $disk = Storage::disk('local');
        $path = $this->vnGameStoragePath($media);

        abort_unless($disk->exists($path), 404);

        return response()->download(
            $disk->path($path),
            $this->vnGameDownloadFilename($media),
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Transfer-Encoding' => 'binary',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'public',
            ]
        );
    }

    public function deleteGame(Request $request, Media $media)
    {
        abort_unless($media->type === 'vn', 404);
        abort_unless(optional($request->user()?->role)->role === 'Admin', 403);

        $disk = Storage::disk('local');
        $disk->deleteDirectory($this->vnGameStorageDirectory($media));
        $disk->deleteDirectory('vn-games/tmp/'.$media->id);

        return back();
    }

    public function fetchVnById(int $id): ?array
    {
        $media = Media::with(['vnTags:id,name', 'vnLanguages:id,name', 'vnDevelopers:id,name'])
            ->where('type', 'vn')
            ->where('id', $id)
            ->first();

        if (!$media) {
            return null;
        }

        $title = $media->title_english ?: ($media->title_romaji ?: ($media->title_native ?: 'No Title'));
        $tags = array_map(fn ($tag) => ['name' => $tag], $media->metadataNamesFrom('vnTags'));
        $developers = array_map(fn ($developer) => ['name' => $developer], $media->metadataNamesFrom('vnDevelopers'));
        $descHtml = $this->renderVnDescription($media->description ?? '');
        $languages = $media->metadataNamesFrom('vnLanguages');

        $hasNoSex = false;
        foreach ($media->metadataNamesFrom('vnTags') as $tag) {
            if (mb_strtolower($tag) === 'no sexual content') {
                $hasNoSex = true;
                break;
            }
        }

        return [
            'id' => (int) $media->id,
            'title' => $title,
            'title_romaji' => $media->title_romaji,
            'title_native' => $media->title_native,
            'description_html' => $descHtml,
            'image' => ['url' => $media->cover_url],
            'tags' => $tags,
            'developers' => $developers,
            'languages' => $languages,
            'average' => (float) ($media->avg_score ?? 0),
            'released' => $media->year ? sprintf('%04d', (int) $media->year) : null,
            'score' => (int) ($media->user_score ?? 0),
            'hasNoSexualContent' => $hasNoSex,
            'year' => (int) ($media->year ?? 0),
            'media' => $media,
        ];
    }

    public function apiList()
    {
        $paginator = $this->getUserVnList('', request('list_filter', ''));
        return response()->json($paginator->items());
    }

    private function renderVnDescription(string $raw, string $linkClass = 'text-blue-600 hover:underline cursor-pointer'): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        $tokens = [];
        $cls = ' class="'.htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8').'"';

        $text = preg_replace_callback(
            '/\[url=(https?:\/\/[^\]\s]+)\](.*?)\[\/url\]/i',
            function ($matches) use (&$tokens, $cls) {
                $url = filter_var($matches[1], FILTER_SANITIZE_URL);
                if (!preg_match('#^https?://#i', $url)) {
                    return $matches[0];
                }

                $label = $matches[2];
                $token = '__A'.count($tokens).'__';
                $tokens[$token] =
                    '<a'.$cls.' href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener noreferrer">'.
                    htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a>';

                return $token;
            },
            $text
        );

        $text = preg_replace_callback(
            '/\[url\](https?:\/\/.*?)\[\/url\]/i',
            function ($matches) use (&$tokens, $cls) {
                $url = trim($matches[1]);
                $safe = filter_var($url, FILTER_SANITIZE_URL);
                if (!preg_match('#^https?://#i', $safe)) {
                    return $matches[0];
                }

                $token = '__A'.count($tokens).'__';
                $tokens[$token] =
                    '<a'.$cls.' href="'.htmlspecialchars($safe, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener noreferrer">'.
                    htmlspecialchars($safe, ENT_QUOTES, 'UTF-8').'</a>';

                return $token;
            },
            $text
        );

        $text = preg_replace('/\[(spoiler)(?:=[^\]]*)?\]/i', '__SPOILER_OPEN__', $text);
        $text = preg_replace('/\[\/spoiler\]/i', '__SPOILER_CLOSE__', $text);

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = strtr($escaped, $tokens);
        $html = str_replace(
            ['__SPOILER_OPEN__', '__SPOILER_CLOSE__'],
            ['<span class="spoiler" tabindex="0" role="button" aria-expanded="false">', '</span>'],
            $html
        );

        return nl2br($html, false);
    }

    public function syncFromVndb(Request $request)
    {
        $token = Auth::user()?->vndb_api_token;
        $username = Auth::user()?->vndb_username;

        if (!$token || !$username) {
            return back()->with('error', 'Add your VNDB API token and username in API settings first.');
        }

        $userId = $this->vndbLookupUserId($token, $username);
        if (!$userId) {
            return back()->with('error', "Cannot find VNDB user: {$username}");
        }

        $rows = $this->vndbFetchUlist($token, $userId);
        if (!count($rows)) {
            return back()->with('error', 'VNDB returned 0 entries.');
        }

        $created = 0;
        $updated = 0;
        $seen = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $entry) {
                $vn = $entry['vn'] ?? [];

                $vidRaw = $vn['id'] ?? ($entry['id'] ?? null);
                if (!$vidRaw) {
                    continue;
                }

                $vid = is_string($vidRaw) ? (int) ltrim($vidRaw, 'vV') : (int) $vidRaw;
                if ($vid <= 0) {
                    continue;
                }
                $seen[] = $vid;

                $titleEn = null;
                $titleRo = null;
                $titleNative = null;
                $mainTitle = $vn['title'] ?? null;

                if (!empty($vn['titles']) && is_array($vn['titles'])) {
                    foreach ($vn['titles'] as $title) {
                        if (!isset($title['lang'], $title['title'])) {
                            continue;
                        }

                        if ($title['lang'] === 'en') {
                            $titleEn = $title['title'];
                        }
                        if ($title['lang'] === 'ja-latn') {
                            $titleRo = $title['title'];
                        }
                    }

                    $titleNative = $this->pickNativeTitle($vn['titles']);
                }

                if (!$titleRo && $mainTitle) {
                    $titleRo = $mainTitle;
                }
                if (!$titleNative && is_string($mainTitle) && $this->containsNonLatin($mainTitle)) {
                    $titleNative = $mainTitle;
                }

                $titleForSlug = $titleEn ?: $titleRo ?: 'vn';
                $cover = $vn['image']['url'] ?? null;
                $desc = $vn['description'] ?? null;

                $tags = array_values(array_filter(
                    array_map(fn ($tag) => trim((string) ($tag['name'] ?? '')), $vn['tags'] ?? [])
                ));

                $developers = array_values(array_filter(
                    array_map(fn ($developer) => trim((string) ($developer['name'] ?? '')), $vn['developers'] ?? [])
                ));

                $languages = array_values(array_filter(
                    array_map(fn ($language) => trim((string) $language), $vn['languages'] ?? [])
                ));

                $avg = isset($vn['rating']) ? (float) $vn['rating'] : null;
                $vote = isset($entry['vote']) ? (int) $entry['vote'] : null;

                $rawLabel = $entry['labels'][0]['label'] ?? null;
                $labelMapN = [1 => 'PLAYING', 2 => 'FINISHED', 3 => 'STALLED', 4 => 'DROPPED', 5 => 'WISHLIST'];
                $listStatus = is_numeric($rawLabel)
                    ? ($labelMapN[(int) $rawLabel] ?? null)
                    : ($rawLabel ? strtoupper($rawLabel) : null);

                $released = $vn['released'] ?? null;
                $year = (is_string($released) && strlen($released) >= 4 && ctype_digit(substr($released, 0, 4)))
                    ? (int) substr($released, 0, 4)
                    : null;

                $slugBase = Str::slug($titleForSlug.'-v'.$vid);

                $values = ['type' => 'vn'];
                if (\Schema::hasColumn('media', 'title_english')) {
                    $values['title_english'] = $titleEn;
                }
                if (\Schema::hasColumn('media', 'title_romaji')) {
                    $values['title_romaji'] = $titleRo;
                }
                if (\Schema::hasColumn('media', 'title_native')) {
                    $values['title_native'] = $titleNative;
                }
                if (\Schema::hasColumn('media', 'slug')) {
                    $values['slug'] = $slugBase;
                }
                if (\Schema::hasColumn('media', 'cover_url')) {
                    $values['cover_url'] = $cover;
                }
                if (\Schema::hasColumn('media', 'banner_url')) {
                    $values['banner_url'] = null;
                }
                if (\Schema::hasColumn('media', 'description')) {
                    $values['description'] = $desc;
                }
                if (\Schema::hasColumn('media', 'origin')) {
                    $values['origin'] = null;
                }
                if (\Schema::hasColumn('media', 'episodes_cnt')) {
                    $values['episodes_cnt'] = null;
                }
                if (\Schema::hasColumn('media', 'chapters_cnt')) {
                    $values['chapters_cnt'] = null;
                }
                if (\Schema::hasColumn('media', 'volumes_cnt')) {
                    $values['volumes_cnt'] = null;
                }
                if (\Schema::hasColumn('media', 'avg_score')) {
                    $values['avg_score'] = $avg;
                }
                if (\Schema::hasColumn('media', 'user_score')) {
                    $values['user_score'] = $vote;
                }
                if (\Schema::hasColumn('media', 'list_status')) {
                    $values['list_status'] = $listStatus;
                }
                if (\Schema::hasColumn('media', 'year')) {
                    $values['year'] = $year;
                }
                if (\Schema::hasColumn('media', 'release_date')) {
                    $values['release_date'] = $released;
                }

                $model = Media::where('source', 'vndb')->where('source_id', $vid)->first();
                if (!$model) {
                    $model = Media::where('type', 'vn')->where('id', $vid)->first() ?: new Media();
                }

                if (\Schema::hasColumn('media', 'source')) {
                    $model->source = 'vndb';
                }
                if (\Schema::hasColumn('media', 'source_id')) {
                    $model->source_id = $vid;
                }

                if (array_key_exists('slug', $values)) {
                    $try = $values['slug'];
                    $index = 1;
                    while (
                        Media::where('slug', $try)
                            ->when($model->exists, fn ($query) => $query->where('id', '<>', $model->id))
                            ->exists()
                    ) {
                        $try = $slugBase.'-'.$index++;
                    }
                    $values['slug'] = $try;
                }

                $model->forceFill($values);
                $wasNew = !$model->exists;
                $model->save();

                $this->metadataSyncer->syncVn($model, $tags, $languages, $developers);

                if ($wasNew || $model->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $seen = array_values(array_unique($seen));
            if (!empty($seen)) {
                Media::where('source', 'vndb')->whereNotIn('source_id', $seen)->delete();
                Media::whereNull('source')->where('type', 'vn')->whereNotIn('id', $seen)->delete();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'VNDB sync failed: '.$e->getMessage());
        }

        return back();
    }

    private function vndbClient(string $token)
    {
        return Http::baseUrl('https://api.vndb.org/kana/')
            ->withHeaders([
                'Authorization' => 'Token '.$token,
                'Accept' => 'application/json',
            ]);
    }

    private function vndbLookupUserId(string $token, string $username): ?string
    {
        $resp = $this->vndbClient($token)->get('user', ['q' => $username]);
        if (!$resp->successful()) {
            return null;
        }

        $data = $resp->json();
        if (!is_array($data)) {
            return null;
        }

        foreach ($data as $row) {
            if (!empty($row['id'])) {
                return $row['id'];
            }
        }

        return null;
    }

    private function vndbFetchUlist(string $token, string $userId): array
    {
        $fields = implode(',', [
            'vn.id',
            'vn.title',
            'vn.titles.lang',
            'vn.titles.title',
            'vn.description',
            'vn.image.url',
            'vn.tags.name',
            'vn.developers.name',
            'vn.languages',
            'labels.label',
            'vote',
            'vn.rating',
            'vn.released',
        ]);

        $page = 1;
        $all = [];

        do {
            $payload = ['user' => $userId, 'fields' => $fields, 'results' => 100, 'page' => $page];
            $resp = $this->vndbClient($token)->post('ulist', $payload);
            if (!$resp->successful()) {
                break;
            }

            $json = $resp->json();
            $batch = $json['results'] ?? [];
            $all = array_merge($all, $batch);
            $more = !empty($json['more']);
            $page++;
        } while ($more);

        return $all;
    }

    private function pickNativeTitle(array $titles): ?string
    {
        foreach ($titles as $title) {
            $value = trim((string) ($title['title'] ?? ''));
            if ($value !== '' && $this->containsNonLatin($value)) {
                return $value;
            }
        }

        return null;
    }

    private function containsNonLatin(string $value): bool
    {
        return preg_match('/[^\p{Latin}\p{Common}\p{Inherited}\p{Nd}\p{Zs}\p{P}\p{S}]/u', $value) === 1;
    }

    public function markNsfw($mediaId)
    {
        $media = Media::where('type', 'vn')->findOrFail((int) $mediaId);

        if (\Schema::hasColumn('media', 'isNsfw')) {
            $media->isNsfw = 1;
            $media->save();
        }

        return back();
    }

    private function vnGameUploadError(string $message)
    {
        return back()
            ->withInput()
            ->with('open_vn_game_upload_modal', true)
            ->with('vn_game_upload_error', $message);
    }

    private function vnGameStorageDirectory(Media $media): string
    {
        return 'vn-games/'.$media->id;
    }

    private function vnGameChunkDirectory(Media $media, string $uploadId): string
    {
        return 'vn-games/tmp/'.$media->id.'/'.$uploadId;
    }

    private function vnGameChunkFilename(int $chunkIndex): string
    {
        return 'chunk-'.str_pad((string) $chunkIndex, 6, '0', STR_PAD_LEFT).'.part';
    }

    private function vnGameStoragePath(Media $media): string
    {
        return $this->vnGameStorageDirectory($media).'/game.zip';
    }

    private function normalizeGameUploadId(mixed $uploadId): ?string
    {
        $uploadId = trim((string) $uploadId);

        if ($uploadId === '' || !preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $uploadId)) {
            return null;
        }

        return $uploadId;
    }

    private function vnGameDownloadFilename(Media $media): string
    {
        $title = $media->title_english ?: ($media->title_romaji ?: ($media->title_native ?: 'visual-novel-'.$media->id));
        $title = trim(preg_replace('/[\\\\\\/:"*?<>|]+/', '', $title) ?? '');

        if ($title === '') {
            $title = 'visual-novel-'.$media->id;
        }

        return $title.'.zip';
    }
}
