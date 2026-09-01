<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Favorite;
use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Support\DoujinAuthorLinks;
use App\Support\MediaMetadataSyncer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AnilistController extends Controller
{
    private ?array $dordieWatchMediaIds = null;

    public function __construct(private readonly MediaMetadataSyncer $metadataSyncer)
    {
    }

    public function home(Request $request)
    {
        $all = Media::query()
            ->with(Media::METADATA_RELATIONS)
            ->get()
            ->map(fn (Media $media) => $this->mapMediaRow($media, $this->dordieWatchMediaIdSet()))
            ->all();

        usort($all, fn ($a, $b) =>
            (sprintf('%04d%02d%02d', $b['startDate']['year'] ?? 0, $b['startDate']['month'] ?? 0, $b['startDate']['day'] ?? 0))
            <=>
            (sprintf('%04d%02d%02d', $a['startDate']['year'] ?? 0, $a['startDate']['month'] ?? 0, $a['startDate']['day'] ?? 0))
        );

        $droppedTypes = ['ANIME', 'MANGA', 'MANHWA', 'LIGHT_NOVEL', 'HENTAI', 'DOUJIN', 'VN'];
        $dropped = array_values(array_filter($all, fn ($media) =>
            ($media['listStatus'] ?? '') === 'DROPPED'
            && in_array($media['type'] ?? null, $droppedTypes, true)
        ));
        usort($dropped, fn ($a, $b) => ($b['averageScore'] ?? 0) <=> ($a['averageScore'] ?? 0));
        $dropped = array_slice($dropped, 0, 12);
        if (count($dropped) < 5) {
            $dropped = [];
        }

        $scored = array_values(array_filter($all, fn ($media) => ($media['userScore'] ?? 0) > 0));
        usort($scored, fn ($a, $b) => ($b['userScore'] ?? 0) <=> ($a['userScore'] ?? 0));

        $wishlikeStatuses = ['WISHLIST', 'PLANNING', 'PLAN TO WATCH', 'PLAN TO READ'];

        $wish = array_values(array_filter($all, function ($media) use ($wishlikeStatuses) {
            $status = strtoupper($media['listStatus'] ?? '');
            $avg = $media['averageScore'] ?? null;

            return in_array($status, $wishlikeStatuses, true)
                && $avg !== null
                && $avg !== ''
                && is_numeric($avg);
        }));

        usort($wish, function ($a, $b) {
            $avgA = (float) ($a['averageScore'] ?? -1);
            $avgB = (float) ($b['averageScore'] ?? -1);

            if ($avgB === $avgA) {
                $cmp = ((int) ($b['userScore'] ?? 0)) <=> ((int) ($a['userScore'] ?? 0));
                if ($cmp !== 0) {
                    return $cmp;
                }

                $dateA = sprintf('%04d%02d%02d', $a['startDate']['year'] ?? 0, $a['startDate']['month'] ?? 0, $a['startDate']['day'] ?? 0);
                $dateB = sprintf('%04d%02d%02d', $b['startDate']['year'] ?? 0, $b['startDate']['month'] ?? 0, $b['startDate']['day'] ?? 0);

                return $dateB <=> $dateA;
            }

            return $avgB <=> $avgA;
        });

        $highestRated4 = array_slice($wish, 0, 4);

        $page = (int) $request->input('page', 1);
        $perPage = 24;
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($all, $offset, $perPage);

        $paginator = new LengthAwarePaginator(
            $slice,
            count($all),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('home', [
            'dropped' => $dropped,
            'highestRated4' => $highestRated4,
            'paginatedMedia' => $paginator,
            'selectedView' => $request->input('view', 'grid'),
        ]);
    }

    public function getAllMedia()
    {
        $all = Media::query()
            ->with(Media::METADATA_RELATIONS)
            ->get()
            ->map(function (Media $media) {
                $canonicalType = strtolower($this->canonicalType($media));

                $doujinListPreviewImage = $canonicalType === 'doujin'
                    ? $this->doujinListPreviewImage($media)
                    : null;

                return [
                    'id' => $media->id,
                    'type' => strtoupper($media->type),
                    'title' => ['english' => $media->title_english, 'romaji' => $media->title_romaji, 'native' => $media->title_native],
                    'coverImage' => ['extraLarge' => $this->externalOrStorage($media->cover_url, $canonicalType === 'doujin')],
                    'listPreviewImage' => $doujinListPreviewImage,
                    'genres' => in_array($canonicalType, ['anime', 'hentai', 'manga', 'manhwa', 'light_novel'], true)
                        ? $media->metadataNamesFrom('anilistGenres')
                        : [],
                    'countryOfOrigin' => $media->origin,
                    'studios' => in_array($canonicalType, ['anime', 'hentai'], true)
                        ? $media->metadataNamesFrom('anilistStudios')
                        : [],
                    'authors' => match ($canonicalType) {
                        'manga', 'manhwa', 'light_novel' => $media->metadataNamesFrom('anilistAuthors'),
                        'doujin' => $media->metadataNamesFrom('doujinAuthors'),
                        default => [],
                    },
                ];
            })
            ->values()
            ->all();

        return response()->json($all);
    }

    public function show($id)
    {
        $media = Media::with(Media::METADATA_RELATIONS)->findOrFail($id);
        $item = $this->mapMediaRow($media);

        $genres = $item['genres'] ?? [];
        $type = strtoupper($item['type'] ?? '');
        $origin = strtoupper($item['countryOfOrigin'] ?? '');

        if ($type === 'ANIME' && in_array('Hentai', $genres, true)) {
            $category = 'hentais';
        } elseif ($type === 'ANIME') {
            $category = 'animes';
        } elseif (in_array($type, ['MANGA', 'MANHWA'], true)) {
            $category = $type === 'MANHWA' || $origin === 'KR' ? 'manhwas' : 'mangas';
        } elseif ($type === 'LIGHT_NOVEL') {
            $category = 'light-novels';
        } else {
            $category = 'animes';
        }

        $isFavorited = Favorite::where([
            ['favoritable_type', $category],
            ['favoritable_id', $media->id],
        ])->exists();

        $allCollections = Collection::orderBy('is_system', 'desc')->orderBy('name')->get();
        $attachedIds = CollectionItem::where('item_type', $category)
            ->where('item_id', $media->id)
            ->pluck('collection_id')
            ->toArray();

        $dordieWatchLaunchUrl = $this->dordieWatchLaunchUrl($media);

        return view('media.anilist', [
            'item' => $item,
            'id' => $media->id,
            'category' => $category,
            'isFavorited' => $isFavorited,
            'allCollections' => $allCollections,
            'attachedIds' => $attachedIds,
            'dordieWatchLaunchUrl' => $dordieWatchLaunchUrl,
        ]);
    }

    public function metadata(Request $request, string $category, string $filter)
    {
        $name = trim((string) $request->query('name', ''));
        abort_if($name === '', 404);

        $config = $this->metadataListingConfig(strtolower($category), strtolower($filter));
        abort_unless($config, 404);

        $selectedView = $request->input('view') === 'list' ? 'list' : 'grid';

        $paginator = Media::query()
            ->with(Media::METADATA_RELATIONS)
            ->where('type', $config['type'])
            ->whereHas($config['relation'], fn ($query) => $query->where('name', $name))
            ->orderByDesc('start_date')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->paginate(24)
            ->appends($request->query());

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(fn (Media $media) => $this->mapMediaRow($media, $this->dordieWatchMediaIdSet()))
                ->values()
        );

        $socialRows = [];
        if ($config['relation'] === 'doujinAuthors') {
            $author = DoujinAuthor::where('name', $name)->first();
            $socialRows = DoujinAuthorLinks::displayRows($author);
        }

        return view('metadata.show', [
            'heading' => $name,
            'kindLabel' => $config['label'],
            'paginatedMedia' => $paginator,
            'selectedView' => $selectedView,
            'socialRows' => $socialRows,
        ]);
    }

    public function updateEntry(Request $request, Media $media)
    {
        abort_unless(in_array($media->type, ['anime', 'hentai', 'manga', 'manhwa', 'light_novel'], true), 404);

        $validator = Validator::make($request->all(), [
            'progress' => ['nullable', 'integer', 'min:0'],
            'list_status' => ['required', 'in:CURRENT,PLANNING,COMPLETED,PAUSED,DROPPED,REPEATING'],
            'user_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'list_start_date' => ['nullable', 'date_format:Y-m-d'],
            'list_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:list_start_date'],
        ]);

        if ($validator->fails()) {
            return back()
                ->withInput()
                ->with('open_edit_entry_modal', true)
                ->with('entry_update_error', $validator->errors()->first());
        }

        $token = Auth::user()?->anilist_access_token;
        if (!$token) {
            return back()
                ->withInput()
                ->with('open_edit_entry_modal', true)
                ->with('entry_update_error', 'Add your AniList access token in API settings first.');
        }

        if (($media->source ?? null) !== 'anilist' || empty($media->source_id)) {
            return back()
                ->withInput()
                ->with('open_edit_entry_modal', true)
                ->with('entry_update_error', 'This entry is not linked to an AniList media record.');
        }

        $data = $validator->validated();
        $progress = $data['progress'] === null || $data['progress'] === ''
            ? null
            : (int) $data['progress'];
        $progressLimit = in_array($media->type, ['anime', 'hentai'], true)
            ? (int) ($media->episodes_cnt ?? 0)
            : (int) ($media->chapters_cnt ?? 0);

        if ($progress !== null && $progressLimit > 0 && $progress > $progressLimit) {
            $unitLabel = in_array($media->type, ['anime', 'hentai'], true) ? 'episodes' : 'chapters';

            return back()
                ->withInput()
                ->with('open_edit_entry_modal', true)
                ->with('entry_update_error', "Progress cannot be higher than {$progressLimit} {$unitLabel}.");
        }

        $scoreRaw = $data['user_score'] === null || $data['user_score'] === ''
            ? null
            : (int) $data['user_score'];
        $listStatus = (string) $data['list_status'];
        $listStartDate = $this->parseSubmittedDate($data['list_start_date'] ?? null);
        $listEndDate = $this->parseSubmittedDate($data['list_end_date'] ?? null);

        $query = <<<'GQL'
mutation ($mediaId: Int, $status: MediaListStatus, $progress: Int, $scoreRaw: Int, $startedAt: FuzzyDateInput, $completedAt: FuzzyDateInput) {
  SaveMediaListEntry(mediaId: $mediaId, status: $status, progress: $progress, scoreRaw: $scoreRaw, startedAt: $startedAt, completedAt: $completedAt) {
    id
    status
    progress
    startedAt { year month day }
    completedAt { year month day }
  }
}
GQL;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://graphql.anilist.co', [
            'query' => $query,
            'variables' => [
                'mediaId' => (int) $media->source_id,
                'status' => $listStatus,
                'progress' => $progress,
                'scoreRaw' => $scoreRaw,
                'startedAt' => $listStartDate['fuzzy'],
                'completedAt' => $listEndDate['fuzzy'],
            ],
        ]);

        $apiError = $response->json('errors.0.message');
        if (!$response->successful() || $apiError) {
            return back()
                ->withInput()
                ->with('open_edit_entry_modal', true)
                ->with('entry_update_error', $apiError ?: 'AniList update failed.');
        }

        $media->progress = $progress;
        $media->list_status = $listStatus;
        $media->user_score = $scoreRaw;
        $media->list_start_date = $listStartDate['date'];
        $media->list_end_date = $listEndDate['date'];

        if (Schema::hasColumn('media', 'list_updated_at')) {
            $media->list_updated_at = now();
        }

        $media->save();

        return back();
    }

    public function destroy(Media $media)
    {
        abort_unless(in_array($media->type, ['anime', 'hentai', 'manga', 'manhwa', 'light_novel'], true), 404);

        $token = Auth::user()?->anilist_access_token;
        if (!$token) {
            return back()->with([
                'status' => 'Add your AniList access token in API settings first.',
                'status_color' => 'red',
            ]);
        }

        if (($media->source ?? null) !== 'anilist' || empty($media->source_id)) {
            return back()->with([
                'status' => 'This entry is not linked to an AniList media record.',
                'status_color' => 'red',
            ]);
        }

        try {
            $entryId = $this->getAniListEntryId($token, (int) $media->source_id);

            if ($entryId !== null) {
                $this->deleteAniListEntry($token, $entryId);
            }

            $category = $this->categorySlugForMedia($media);
            $archivePath = $media->archive()->value('file_path');
            $media->delete();

            if ($archivePath) {
                $archiveDisk = Storage::disk('local');
                if ($archiveDisk->exists($archivePath)) {
                    $archiveDisk->delete($archivePath);
                }
            }

            return redirect()
                ->route('category', ['category' => $category]);
        } catch (\Throwable $e) {
            return back()->with([
                'status' => $e->getMessage() !== '' ? $e->getMessage() : 'AniList delete failed.',
                'status_color' => 'red',
            ]);
        }
    }

    private function canonicalType(Media $media): string
    {
        $type = strtoupper($media->type);

        if ($type === 'MANGA' && strtoupper((string) $media->origin) === 'KR') {
            return 'MANHWA';
        }

        return $type;
    }

    private function categorySlugForMedia(Media $media): string
    {
        $canonicalType = strtolower($this->canonicalType($media));

        return match ($canonicalType) {
            'anime' => 'animes',
            'hentai' => 'hentais',
            'manga' => 'mangas',
            'manhwa' => 'manhwas',
            'light_novel' => 'light-novels',
            default => 'animes',
        };
    }

    private function metadataListingConfig(string $category, string $filter): ?array
    {
        if ($filter === 'studio' && in_array($category, ['animes', 'hentais'], true)) {
            return [
                'type' => $category === 'hentais' ? 'hentai' : 'anime',
                'relation' => 'anilistStudios',
                'label' => 'Studio',
            ];
        }

        if ($filter === 'author' && in_array($category, ['mangas', 'manhwas', 'light-novels'], true)) {
            return [
                'type' => match ($category) {
                    'manhwas' => 'manhwa',
                    'light-novels' => 'light_novel',
                    default => 'manga',
                },
                'relation' => 'anilistAuthors',
                'label' => 'Author',
            ];
        }

        if ($filter === 'author' && $category === 'doujins') {
            return [
                'type' => 'doujin',
                'relation' => 'doujinAuthors',
                'label' => 'Author',
            ];
        }

        if (in_array($filter, ['developers', 'developer'], true) && $category === 'visual-novel') {
            return [
                'type' => 'vn',
                'relation' => 'vnDevelopers',
                'label' => 'Developer',
            ];
        }

        if (in_array($filter, ['publishers', 'publisher'], true) && $category === 'visual-novel') {
            return [
                'type' => 'vn',
                'relation' => 'vnPublishers',
                'label' => 'Publisher',
            ];
        }

        return null;
    }

    private function mapMediaRow(Media $media, ?array $dordieWatchMediaIds = null): array
    {
        $canonicalType = strtolower($this->canonicalType($media));

        $genres = in_array($canonicalType, ['anime', 'hentai', 'manga', 'manhwa', 'light_novel'], true)
            ? $media->metadataNamesFrom('anilistGenres')
            : [];
        $tags = match (true) {
            $canonicalType === 'vn' => $media->metadataNamesFrom('vnTags'),
            in_array($canonicalType, ['anime', 'hentai', 'manga', 'manhwa', 'light_novel'], true) => $media->metadataNamesFrom('anilistTags'),
            default => [],
        };
        $studios = in_array($canonicalType, ['anime', 'hentai'], true)
            ? $media->metadataNamesFrom('anilistStudios')
            : [];
        $authors = match ($canonicalType) {
            'manga', 'manhwa', 'light_novel' => $media->metadataNamesFrom('anilistAuthors'),
            'doujin' => $media->metadataNamesFrom('doujinAuthors'),
            default => [],
        };
        $languages = $canonicalType === 'vn'
            ? $media->metadataNamesFrom('vnLanguages')
            : [];

        $descHtml = $media->description ?? '';
        $desc = preg_replace('/<\s*br\s*\/?>/i', "\n", $descHtml);
        $desc = preg_replace('/<\/p>\s*<p>/i', "\n\n", $desc);
        $desc = strip_tags($desc);
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = preg_replace("/\r\n?/", "\n", $desc);
        $desc = preg_replace("/[ \t]+$/m", '', $desc);
        $desc = preg_replace("/\n{3,}/", "\n\n", $desc);
        $desc = trim($desc);

        $parsedStartDate = $media->start_date ? Carbon::parse($media->start_date) : null;
        $year = optional($parsedStartDate)->year;
        $month = optional($parsedStartDate)->month;
        $day = optional($parsedStartDate)->day;

        return [
            'id' => $media->id,
            'source' => $media->source,
            'sourceId' => $media->source_id,
            'type' => strtoupper($canonicalType),
            'title' => [
                'english' => $media->title_english,
                'romaji' => $media->title_romaji,
                'native' => $media->title_native,
            ],
            'coverImage' => [
                'extraLarge' => $this->externalOrStorage($media->cover_url, $canonicalType === 'doujin'),
            ],
            'bannerImage' => $media->banner_url,
            'listPreviewImage' => $canonicalType === 'doujin'
                ? $this->doujinListPreviewImage($media)
                : null,
            'description' => $desc,
            'genres' => $genres,
            'tags' => $tags,
            'averageScore' => $media->avg_score,
            'episodes' => $media->episodes_cnt ?: null,
            'chapters' => $media->chapters_cnt ?: null,
            'volumes' => $media->volumes_cnt ?: null,
            'format' => null,
            'status' => $media->media_status,
            'startDate' => ['year' => $year, 'month' => $month, 'day' => $day],
            'countryOfOrigin' => $media->origin,
            'dordieWatchLaunchUrl' => $this->dordieWatchLaunchUrl($media, $dordieWatchMediaIds),
            'studios' => $studios,
            'authors' => $authors,
            'mediaListEntry' => [
                'score' => $media->user_score,
                'progress' => $media->progress,
                'status' => $media->list_status,
                'startedAt' => $media->list_start_date,
                'completedAt' => $media->list_end_date,
            ],
            'userScore' => $media->user_score,
            'userProgress' => $media->progress,
            'listStatus' => $media->list_status,
            'listStartDate' => $media->list_start_date,
            'listEndDate' => $media->list_end_date,
            'languages' => $languages,
        ];
    }

    private function dordieWatchLaunchUrl(Media $media, ?array $dordieWatchMediaIds = null): ?string
    {
        if (! in_array(strtolower((string) $media->type), ['anime', 'hentai'], true)) {
            return null;
        }

        if ($dordieWatchMediaIds !== null) {
            $isOnDordieWatch = isset($dordieWatchMediaIds[$media->id]);
        } else {
            $isOnDordieWatch = DB::table('dordiewatch_media')->where('media_id', $media->id)->exists();
        }

        if (! $isOnDordieWatch) {
            return null;
        }

        $manifestUrl = URL::temporarySignedRoute(
            'dordiewatch.media',
            now()->addMinutes(10),
            ['media' => $media->id]
        );

        return 'dordiewatch://open?manifest='.rtrim(strtr(base64_encode($manifestUrl), '+/', '-_'), '=');
    }

    private function dordieWatchMediaIdSet(): array
    {
        return $this->dordieWatchMediaIds ??= DB::table('dordiewatch_media')
            ->pluck('media_id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }

    private function externalOrStorage(?string $path, bool $storagePath = false): string
    {
        if (!$path) {
            return asset('images/no-image.jpg');
        }

        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
            return $path;
        }

        return $storagePath
            ? Storage::url(ltrim($path, '/'))
            : asset($path);
    }

    private function doujinListPreviewImage(Media $media): ?string
    {
        $firstChapter = Chapter::where('item_type', 'doujin')
            ->where('media_fk', $media->id)
            ->orderBy('chapter_number')
            ->first(['id']);

        if (!$firstChapter) {
            return null;
        }

        $pageCount = ChapterPage::where('chapter_id', $firstChapter->id)->count();
        if ($pageCount < 1) {
            return null;
        }

        $startPage = max(1, (int) floor($pageCount * 0.4));
        $endPage = min($pageCount, (int) ceil($pageCount * 0.6));

        $page = ChapterPage::where('chapter_id', $firstChapter->id)
            ->whereBetween('page_number', [$startPage, $endPage])
            ->inRandomOrder()
            ->first(['file_path']);

        if (!$page || !$page->file_path) {
            return null;
        }

        return Storage::url(ltrim((string) $page->file_path, '/'));
    }

    public function syncFromAnilist(Request $request)
    {
        $token = Auth::user()?->anilist_access_token;
        if (!$token) {
            return back()->with('error', 'Add your AniList access token in API settings first.');
        }

        $viewerId = $this->getViewerId($token);
        if (!$viewerId) {
            return back()->with('error', 'Unable to get AniList Viewer ID');
        }

        $allEntries = [];
        foreach (['ANIME', 'MANGA'] as $type) {
            $allEntries = array_merge($allEntries, $this->fetchList($token, $viewerId, $type));
        }

        $seenIds = [];
        $mediaColumns = array_flip(Schema::getColumnListing('media'));
        $created = 0;
        $updated = 0;

        DB::beginTransaction();
        try {
            foreach ($allEntries as $entry) {
                $media = $entry['media'] ?? null;
                if (!$media) {
                    continue;
                }

                $sourceId = (int) $media['id'];
                $seenIds[] = $sourceId;

                $genres = array_values(array_filter(array_map(fn ($genre) => trim((string) $genre), $media['genres'] ?? [])));
                $tagRecords = collect($media['tags'] ?? [])
                    ->map(fn (array $tag) => [
                        'name' => trim((string) ($tag['name'] ?? '')),
                        'source_id' => isset($tag['id']) && is_numeric($tag['id']) ? (int) $tag['id'] : null,
                    ])
                    ->filter(fn (array $tag) => $tag['name'] !== '')
                    ->unique(fn (array $tag) => mb_strtolower($tag['name']))
                    ->values()
                    ->all();
                $tags = $this->metadataSyncer->normalizedNames($tagRecords) ?? [];

                $origin = $media['countryOfOrigin'] ?? null;
                $avgScore = $media['averageScore'] ?? null;
                $mediaStatus = $media['status'] ?? null;
                $listStatus = $entry['status'] ?? null;
                $userScore = isset($entry['score']) ? (int) $entry['score'] : null;
                $progress = isset($entry['progress']) ? (int) $entry['progress'] : null;
                $listStartDate = $this->fuzzyDateToString($entry['startedAt'] ?? null);
                $listEndDate = $this->fuzzyDateToString($entry['completedAt'] ?? null);

                $studioRecords = [];
                if (!empty($media['studios']['edges'])) {
                    $studioRecords = collect($media['studios']['edges'])
                        ->map(function (array $edge) {
                            $node = $edge['node'] ?? [];
                            $name = trim((string) ($node['name'] ?? ''));

                            return [
                                'name' => $name,
                                'source_id' => isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null,
                                'is_main' => !empty($edge['isMain']),
                            ];
                        })
                        ->filter(fn (array $studio) => $studio['name'] !== '' && $studio['is_main'])
                        ->unique(fn (array $studio) => mb_strtolower($studio['name']))
                        ->map(fn (array $studio) => [
                            'name' => $studio['name'],
                            'source_id' => $studio['source_id'],
                        ])
                        ->values()
                        ->all();
                }

                $authorRecords = [];
                if (!empty($media['staff']['edges'])) {
                    $allow = ['story', 'art', 'story & art'];
                    $denySubstrings = ['assistant', 'letter', 'touch', 'editor', 'translation', 'translator', 'publisher'];

                    $authorRecords = collect($media['staff']['edges'])
                        ->map(function (array $edge) use ($allow, $denySubstrings) {
                            $rawRole = strtolower((string) ($edge['role'] ?? ''));
                            $name = trim((string) ($edge['node']['name']['full'] ?? ''));

                            if ($name === '' || $rawRole === '') {
                                return null;
                            }

                            foreach ($denySubstrings as $substring) {
                                if (str_contains($rawRole, $substring)) {
                                    return null;
                                }
                            }

                            $role = preg_replace('/\s*\(.*?\)\s*/', ' ', $rawRole);
                            $role = str_replace([' and ', ',', '/', 'ãƒ»'], ' & ', $role);
                            $role = trim((string) preg_replace('/\s+/', ' ', $role));

                            if (!in_array($role, $allow, true)) {
                                return null;
                            }

                            return [
                                'name' => $name,
                                'source_id' => isset($edge['node']['id']) && is_numeric($edge['node']['id']) ? (int) $edge['node']['id'] : null,
                            ];
                        })
                        ->filter()
                        ->unique(fn (array $author) => mb_strtolower($author['name']))
                        ->values()
                        ->all();
                }

                $year = $media['startDate']['year'] ?? null;
                $month = $media['startDate']['month'] ?? null;
                $day = $media['startDate']['day'] ?? null;
                $startDate = ($year && $month && $day)
                    ? Carbon::createFromDate($year, $month, $day)->toDateString()
                    : null;

                $titleEn = $media['title']['english'] ?? null;
                $titleRo = $media['title']['romaji'] ?? null;
                $titleNative = $media['title']['native'] ?? null;
                $cover = $media['coverImage']['extraLarge'] ?? null;
                $banner = $media['bannerImage'] ?? null;
                $desc = $media['description'] ?? null;
                $episodesCnt = $media['episodes'] ?? null;
                $chaptersCnt = $media['chapters'] ?? null;
                $volumesCnt = $media['volumes'] ?? null;

                $remoteType = $this->guessRemoteType($media, $genres);
                if ($remoteType === 'ANIME') {
                    $localType = in_array('Hentai', $genres, true) ? 'hentai' : 'anime';
                } else {
                    $format = strtoupper((string) ($media['format'] ?? ''));
                    $localType = strtoupper((string) $origin) === 'KR' ? 'manhwa' : 'manga';
                    if ($format === 'NOVEL') {
                        $localType = 'light_novel';
                    }
                }

                $base = $titleRo ?: $titleEn ?: ('media-'.$sourceId);
                $slug = Str::slug($base.'-al'.$sourceId);

                $values = array_intersect_key([
                    'type' => $localType,
                    'title_english' => $titleEn,
                    'title_romaji' => $titleRo,
                    'title_native' => $titleNative,
                    'slug' => $slug,
                    'cover_url' => $cover,
                    'banner_url' => $banner,
                    'description' => $desc,
                    'origin' => $origin,
                    'list_status' => $listStatus,
                    'media_status' => $mediaStatus,
                    'user_score' => $userScore,
                    'avg_score' => $avgScore,
                    'year' => $year,
                    'start_date' => $startDate,
                    'list_start_date' => $listStartDate,
                    'list_end_date' => $listEndDate,
                    'episodes_cnt' => $remoteType === 'ANIME' ? $episodesCnt : null,
                    'chapters_cnt' => $remoteType === 'MANGA' ? $chaptersCnt : null,
                    'volumes_cnt' => $remoteType === 'MANGA' ? $volumesCnt : null,
                    'progress' => $progress,
                ], $mediaColumns);

                $model = Media::updateOrCreate(
                    ['source' => 'anilist', 'source_id' => $sourceId],
                    $values
                );

                $this->metadataSyncer->syncAnilist(
                    $model,
                    $genres,
                    $tagRecords,
                    in_array($localType, ['anime', 'hentai'], true) ? $studioRecords : [],
                    in_array($localType, ['manga', 'manhwa', 'light_novel'], true) ? $authorRecords : []
                );

                if ($model->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $seenIds = array_values(array_unique($seenIds));
            if (!empty($seenIds)) {
                Media::where('source', 'anilist')
                    ->whereNotIn('source_id', $seenIds)
                    ->whereNotIn('id', DB::table('media_archives')->select('media_id'))
                    ->whereNotIn('id', DB::table('chapters')->whereNotNull('media_fk')->select('media_fk'))
                    ->delete();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'AniList sync failed: '.$e->getMessage());
        }

        return back();
    }

    private function getViewerId(string $token): ?int
    {
        $query = '{ Viewer { id } }';
        $resp = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ])->post('https://graphql.anilist.co', ['query' => $query]);

        if (!$resp->successful()) {
            return null;
        }

        return $resp->json('data.Viewer.id');
    }

    private function getAniListEntryId(string $token, int $mediaId): ?int
    {
        $query = <<<'GQL'
query ($mediaId: Int) {
  Media(id: $mediaId) {
    mediaListEntry {
      id
    }
  }
}
GQL;

        $resp = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://graphql.anilist.co', [
            'query' => $query,
            'variables' => ['mediaId' => $mediaId],
        ]);

        $apiError = $resp->json('errors.0.message');
        if (!$resp->successful() || $apiError) {
            throw new \RuntimeException($apiError ?: 'AniList lookup failed.');
        }

        $entryId = $resp->json('data.Media.mediaListEntry.id');

        return is_numeric($entryId) ? (int) $entryId : null;
    }

    private function deleteAniListEntry(string $token, int $entryId): void
    {
        $query = <<<'GQL'
mutation ($id: Int) {
  DeleteMediaListEntry(id: $id) {
    deleted
  }
}
GQL;

        $resp = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://graphql.anilist.co', [
            'query' => $query,
            'variables' => ['id' => $entryId],
        ]);

        $apiError = $resp->json('errors.0.message');
        $deleted = $resp->json('data.DeleteMediaListEntry.deleted');

        if (!$resp->successful() || $apiError || !$deleted) {
            throw new \RuntimeException($apiError ?: 'AniList delete failed.');
        }
    }

    private function fetchList(string $token, int $userId, string $type): array
    {
        if ($type === 'ANIME') {
            $query = <<<'GQL'
    query ($userId:Int, $type:MediaType, $status:MediaListStatus) {
      MediaListCollection(userId:$userId, type:$type, status:$status) {
        lists {
          entries {
            status
            score
            progress
            createdAt
            updatedAt
            startedAt { year month day }
            completedAt { year month day }
            media {
              type
              format
              id
              title { english romaji native }
              coverImage { extraLarge }
              bannerImage
              description
              genres
              startDate { year month day }
              countryOfOrigin
              status
              averageScore
              tags { id name }
              studios { edges { isMain node { id name } } }
              episodes
              chapters
              volumes
            }
          }
        }
      }
    }
    GQL;
        } else {
            $query = <<<'GQL'
    query ($userId:Int, $type:MediaType, $status:MediaListStatus) {
      MediaListCollection(userId:$userId, type:$type, status:$status) {
        lists {
          entries {
            status
            score
            progress
            createdAt
            updatedAt
            startedAt { year month day }
            completedAt { year month day }
            media {
              type
              format
              id
              title { english romaji native }
              coverImage { extraLarge }
              bannerImage
              description
              genres
              startDate { year month day }
              countryOfOrigin
              status
              averageScore
              tags { id name }
              staff { edges { node { id name { full } } role } }
              episodes
              chapters
              volumes
            }
          }
        }
      }
    }
    GQL;
        }

        $out = [];
        $statuses = ['CURRENT', 'PLANNING', 'COMPLETED', 'PAUSED', 'DROPPED', 'REPEATING'];

        foreach ($statuses as $status) {
            $resp = Http::timeout(120)->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post('https://graphql.anilist.co', [
                'query' => $query,
                'variables' => ['userId' => $userId, 'type' => $type, 'status' => $status],
            ]);

            $apiError = $resp->json('errors.0.message');
            if (!$resp->successful() || $apiError) {
                throw new \RuntimeException($apiError ?: "AniList {$type} {$status} list sync failed.");
            }

            $lists = $resp->json('data.MediaListCollection.lists') ?? [];
            foreach ($lists as $list) {
                foreach ($list['entries'] as $entry) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    private function guessRemoteType(array $media, array $genres): string
    {
        $type = strtoupper($media['type'] ?? '');
        if (in_array($type, ['ANIME', 'MANGA'], true)) {
            return $type;
        }

        $format = strtoupper($media['format'] ?? '');
        $animeFormats = ['TV', 'TV_SHORT', 'MOVIE', 'SPECIAL', 'OVA', 'ONA', 'MUSIC'];
        if ($format && in_array($format, $animeFormats, true)) {
            return 'ANIME';
        }

        if (array_key_exists('episodes', $media) && $media['episodes'] !== null) {
            return 'ANIME';
        }
        if (array_key_exists('chapters', $media) && $media['chapters'] !== null) {
            return 'MANGA';
        }

        return 'ANIME';
    }

    private function parseSubmittedDate(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return ['date' => null, 'fuzzy' => null];
        }

        $date = Carbon::createFromFormat('Y-m-d', trim($value))->startOfDay();

        return [
            'date' => $date->toDateString(),
            'fuzzy' => [
                'year' => (int) $date->year,
                'month' => (int) $date->month,
                'day' => (int) $date->day,
            ],
        ];
    }

    private function fuzzyDateToString(?array $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $year = isset($value['year']) ? (int) $value['year'] : null;
        $month = isset($value['month']) ? (int) $value['month'] : null;
        $day = isset($value['day']) ? (int) $value['day'] : null;

        if (!$year || !$month || !$day) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
