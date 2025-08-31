<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Media;
use App\Models\Doujin;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;

class CategoryController extends Controller
{
    public function show(Request $request, $category, $listFilter = 'all', $mediaStatus = 'all', $titleOrder = 'none', $scoreOrder = 'none', $dateOrder = 'none')
    {
        $normalized = strtoupper($category);

        /* -------------------- DOUJINS (keep) -------------------- */
        if ($normalized === 'DOUJINS') {
            $perPage   = 40;
            $nameOrder = $request->query('name_order', 'none');

            $allAuthors = Doujin::query()->select('author_name')->distinct()->orderBy('author_name')->pluck('author_name')->toArray();

            $selectedAuthors = $request->query('author', []);
            if (!is_array($selectedAuthors)) $selectedAuthors = explode(',', (string)$selectedAuthors);

            $query = Doujin::query();
            if ($nameOrder === 'az')      $query->orderBy('doujin_name');
            elseif ($nameOrder === 'za')  $query->orderByDesc('doujin_name');
            else                          $query->orderBy('id');

            foreach ($selectedAuthors as $authorName) {
                if ($authorName !== '') $query->where('author_name', 'LIKE', "%{$authorName}%");
            }

            $paginator = $query->paginate($perPage, ['*'], 'page')->appends([
                'author'     => implode(',', $selectedAuthors),
                'name_order' => $nameOrder,
            ]);

            return view('category', [
                'category'        => 'DOUJINS',
                'media'           => $paginator->items(),
                'paginatedMedia'  => $paginator,
                'nameOrder'       => $nameOrder,
                'allAuthors'      => $allAuthors,
                'selectedAuthors' => $selectedAuthors,
                // keep Blade happy for fields it might read:
                'allStudios'      => [],
                'selectedStudio'  => [],
                'allTags'         => [],
                'allGenres'       => [],
                'selectedGenres'  => [],
                'allYears'        => [],
                'selectedYears'   => [],
            ]);
        }

        /* -------------- ANIMES / HENTAIS / MANGAS / MANWHAS -------------- */
        $q = Media::query();

        // Build selectedStudio early (only applies to ANIMES/HENTAIS)
        $selectedStudio = [];
        if (in_array($normalized, ['ANIMES', 'HENTAIS'])) {
            $studioParam = $request->query('studio', '');
            $selectedStudio = is_array($studioParam)
                ? $studioParam
                : array_filter(array_map('trim', explode(',', (string)$studioParam)));
        }

        switch ($normalized) {
            case 'ANIMES':
                $q->where('type', 'anime');
                break;

            case 'HENTAIS':
                $q->where('type', 'hentai');
                break;

            case 'MANGAS':
                $q->where('type', 'manga');
                break;

            case 'MANWHAS':
                $q->where('type', 'manwha');
                break;

            default:
                // leave $q as-is for other categories
                break;
        }

        // Optional filters (?year=, ?genre[]=, ?tags=CSV)
        if ($year = $request->query('year')) {
            $q->where('year', $year);
        }

        $genreParams = (array) $request->query('genre', []);
        foreach ($genreParams as $g) {
            if ($g !== '') $q->whereJsonContains('genres', $g);
        }

        if ($tagsCsv = $request->query('tags')) {
            foreach (explode(',', $tagsCsv) as $t) {
                $t = trim($t);
                if ($t !== '') $q->whereJsonContains('tags', $t);
            }
        }

        // Status filters from path params (listFilter, mediaStatus)
        if ($listFilter !== 'all') {
            $q->where('list_status', $listFilter); // e.g. CURRENT, DROPPED ...
        }
        if ($mediaStatus !== 'all') {
            $q->where('media_status', $mediaStatus); // e.g. FINISHED, RELEASING ...
        }

        // Sorting
        $titleExpr = 'COALESCE(title_english, title_romaji)';

        if ($scoreOrder !== 'none') {
            $q->orderBy(
                (str_contains($scoreOrder, 'avg') ? 'avg_score' : 'user_score'),
                (str_contains($scoreOrder, 'desc') ? 'desc' : 'asc')
            );
        } elseif ($dateOrder !== 'none') {
            // use AniList list timestamps (what you wanted)
            $col = str_contains($dateOrder,'start')   ? 'start_date'
                : (str_contains($dateOrder,'updated') ? 'list_updated_at'
                : (str_contains($dateOrder,'created') ? 'list_created_at' : 'start_date'));
            $q->orderBy($col, str_contains($dateOrder,'desc') ? 'desc' : 'asc');
        } elseif ($titleOrder !== 'none') {
            $q->orderByRaw("$titleExpr " . ($titleOrder === 'za' ? 'DESC' : 'ASC'));
        } else {
            $q->orderByRaw("$titleExpr ASC");
        }

        // Pagination + transform each row to the “entry.media = {...}” shape used by your partial
        $perPage = 40;
        $p = $q->paginate($perPage)->appends($request->query());

        $items = $p->getCollection()->map(function (Media $m) {
            return [
                'media' => [
                    'id'               => $m->id,
                    'title'            => [
                        'english' => $m->title_english,
                        'romaji'  => $m->title_romaji,
                    ],
                    'coverImage'       => ['extraLarge' => $m->cover_url], // stored full URL from importer
                    'genres'           => $this->toArray($m->genres),
                    'isAdult'          => (bool)($m->is_adult ?? false),
                    'startDate'        => ['year' => $m->year ?? null],
                    'averageScore'     => $m->avg_score ?? null,
                    'countryOfOrigin'  => $m->origin,
                    'type'             => strtoupper($m->type),
                    // studios for possible display (optional)
                    'studios'          => $this->toArray($m->studios ?? []),
                ],
                'status'    => $m->list_status ?? null,
                'score'     => $m->user_score ?? null,
                'updatedAt' => optional($m->updated_at)->timestamp,
                'createdAt' => optional($m->created_at)->timestamp,
            ];
        })->all();

        // Sidebar data
        $allGenres = Media::selectRaw('JSON_EXTRACT(genres, "$") as g')
            ->whereNotNull('genres')->get()
            ->flatMap(fn($row) => $this->toArray($row->g))
            ->unique()->sort()->values()->all();

        $allTags = Media::selectRaw('JSON_EXTRACT(tags, "$") as t')
            ->whereNotNull('tags')->get()
            ->flatMap(fn($row) => $this->toArray($row->t))
            ->unique()->sort()->values()->all();

        $allYears  = Media::whereNotNull('year')->distinct()->orderBy('year')->pluck('year')->toArray();

        // Build allStudios only for anime/hentai (safe default empty otherwise)
        $allStudios = [];
        if (in_array($normalized, ['ANIMES','HENTAIS'])) {
            $allStudios = Media::whereIn('type', ['anime','hentai'])
                ->whereNotNull('studios')
                ->pluck('studios')
                ->flatMap(fn ($arr) => (array) $arr)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        // For non-anime/hentai, your Blade shows an AUTHOR dropdown.
        // If you don't have authors in your table yet, pass safe defaults to avoid undefined var.
        $allAuthors      = [];
        $selectedAuthor  = [];

        return view('category', [
            'category'        => ucfirst(str_replace('-', ' ', $category)),
            'media'           => $items,
            'paginatedMedia'  => $p,
            'listFilter'      => $listFilter,
            'mediaStatus'     => $mediaStatus,
            'titleOrder'      => $titleOrder,
            'scoreOrder'      => $scoreOrder,
            'dateOrder'       => $dateOrder,
            'allTags'        => $allTags,
            'allGenres'       => $allGenres,
            'selectedGenres'  => $genreParams,
            'allYears'        => $allYears,
            'selectedYears'   => (array) $request->query('year', []),

            // NEW: studio data so Blade stops erroring
            'allStudios'      => $allStudios,
            'selectedStudio'  => $selectedStudio,

            // Safe defaults so AUTHOR dropdown section doesn't explode when on MANGAS/MANWHAS
            'allAuthors'      => $allAuthors,
            'selectedAuthor'  => $selectedAuthor,
        ]);
    }

    public function showDoujin(Doujin $doujin)
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $doujin->cover_url)));
        $coverUrl = Storage::disk(config('filesystems.default'))->url($encoded);

        $isFavorited = Favorite::where([
            ['favoritable_type', 'doujins'],
            ['favoritable_id',   $doujin->id],
        ])->exists();

        $allCollections = Collection::orderBy('name')->get();

        $attachedIds = CollectionItem::where([
            ['item_type', 'doujins'],
            ['item_id',   $doujin->id],
        ])->pluck('collection_id')->toArray();

        return view('media.doujin', [
            'doujin'         => $doujin,
            'coverUrl'       => $coverUrl,
            'allCollections' => $allCollections,
            'attachedIds'    => $attachedIds,
            'isFavorited'    => $isFavorited,
        ]);
    }

    /* -------- helpers -------- */

    private function toArray($maybeJson): array
    {
        if (is_array($maybeJson)) return $maybeJson;
        if (is_string($maybeJson) && $maybeJson !== '') {
            $decoded = json_decode($maybeJson, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
