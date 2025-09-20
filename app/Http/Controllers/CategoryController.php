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
    private function toCard($row): array
    {
        // If it’s already a normalized card, just return it
        if (is_array($row) && isset($row['url'], $row['cover'])) {
            return $row;
        }

        // Doujin model
        if ($row->type === 'doujin') {
            $title = $row->title_english ?: ($row->title_romaji ?: 'No Title');

            // cover_url may be a relative storage path (preferred). If so, resolve to public URL.
            $cover = $row->cover_url ?: null;
            if ($cover && !preg_match('#^https?://#i', $cover)) {
                $cover = \Storage::url(ltrim($cover, '/'));
            }
            if (!$cover) {
                $cover = asset('images/no-image.jpg');
            }

            return [
                'id'    => $row->id,
                'url'   => route('doujins.show', ['media' => $row->id]),
                'cover' => $cover,
                'title' => $title,
                'nsfw'  => true,
            ];
        }

        if ($row instanceof \App\Models\Media) {
            $isVN = ($row->type === 'vn');

            $tags = $this->toArray($row->tags);

            $hasNoSex = false;
            if ($isVN && $tags) {
                foreach ($tags as $t) {
                    if (mb_strtolower(trim($t)) === 'no sexual content') {
                        $hasNoSex = true;
                        break;
                    }
                }
            }

            $isNonVnNsfw = in_array('Hentai', (array)($row->genres ?? []), true) || (bool)($row->is_adult ?? false);

            $nsfw = $isVN ? !$hasNoSex : $isNonVnNsfw;

            return [
                'id'    => $row->id,
                'url'   => $isVN
                    ? route('vn.show', ['id' => $row->id])
                    : route('media.show', ['id' => $row->id]),
                'cover' => $row->cover_url ?: asset('images//no-image.jpg'),
                'title' => $row->title_english ?: ($row->title_romaji ?: 'No Title'),
                'nsfw'  => $nsfw,
            ];
        }

        return [
            'id'    => 0,
            'url'   => '#',
            'cover' => asset('images//no-image.jpg'),
            'title' => 'No Title',
            'nsfw'  => false,
        ];
    }


    public function show(Request $request, $category, $listFilter = 'all', $mediaStatus = 'all', $titleOrder = 'none', $scoreOrder = 'none', $dateOrder = 'none')
    {
        $normalized = strtoupper($category);

        /* -------------------- DOUJINS -------------------- */
        if ($normalized === 'DOUJINS') {
            $q = Media::query()->where('type', 'doujin');

            // filters
            $nameOrder = $request->query('name_order', 'none');

            // selected authors from query (comma-separated)
            $selectedAuthors = $request->query('author', []);
            if (!is_array($selectedAuthors)) {
                $selectedAuthors = array_filter(array_map('trim', explode(',', (string)$selectedAuthors)));
            }
            $selectedAuthors = array_values(array_filter($selectedAuthors)); // clean

            // ▶ AND semantics: the doujin must contain ALL selected authors
            if (!empty($selectedAuthors)) {
                $q->where(function ($and) use ($selectedAuthors) {
                    // strict: every selected author must be present as its own JSON element
                    foreach ($selectedAuthors as $auth) {
                        $and->whereJsonContains('publisher', $auth);
                    }
                    // fallback: if some rows still store a single composite string, match JSON text
                    // REQUIRE all tokens to appear in the JSON string as well (still AND)
                    $and->orWhere(function ($textAnd) use ($selectedAuthors) {
                        foreach ($selectedAuthors as $auth) {
                            $textAnd->where('publisher', 'LIKE', '%"'.$auth.'"%');
                        }
                    });
                });
            }

            // Sort by title (romaji -> english -> slug)
            $titleExpr = 'COALESCE(NULLIF(title_romaji,""), NULLIF(title_english,""), slug)';
            if ($nameOrder === 'az')      $q->orderByRaw("$titleExpr ASC");
            elseif ($nameOrder === 'za')  $q->orderByRaw("$titleExpr DESC");
            else                          $q->orderBy('id');

            // paginate
            $perPage = 40;
            $p = $q->paginate($perPage)->appends($request->query());

            // Sidebar authors come from DB publisher JSON (split any composites just for the list)
            $allAuthors = Media::where('type', 'doujin')
                ->pluck('publisher')                // collection of arrays
                ->flatten()                         // strings (some may be "A and B")
                ->flatMap(fn ($v) => $this->splitAuthorsFromValue($v))
                ->filter()
                ->unique(fn ($v) => mb_strtolower($v))
                ->sort()
                ->values()
                ->all();

            // map to cards
            $cards = $p->getCollection()
                ->map(fn($row) => $this->toCard($row))
                ->values();
            $p->setCollection($cards);

            return view('category', [
                'category'        => 'DOUJINS',
                'media'           => $cards->all(),
                'paginatedMedia'  => $p,
                'nameOrder'       => $nameOrder,
                'allAuthors'      => $allAuthors,
                'selectedAuthors' => $selectedAuthors,
            ]);
        }
        /* -------------------- VNDB -------------------- */
        if ($normalized === 'VISUAL-NOVEL') {
            $q = Media::query()->where('type', 'vn');

            $listFilter = strtolower($request->query('list_filter', 'all'));
            if ($listFilter !== 'all') {
                $q->where('list_status', strtoupper($listFilter));
            }

            $titleOrder = $request->query('title_order', 'none');
            $scoreOrder = $request->query('score_order', 'none');     // avg_* or personal_*
            $yearOrder = $request->query('year_order', 'none');

            if ($tagsCsv = $request->query('tags')) {
                foreach (explode(',', $tagsCsv) as $t) {
                    $t = trim($t);
                    if ($t !== '') $q->whereJsonContains('tags', $t);
                }
            }

            $selectedLanguages = $request->query('language', []);
            if (!is_array($selectedLanguages)) {
                $selectedLanguages = explode(',', $selectedLanguages);
            }
            foreach ($selectedLanguages as $lang) {
                if ($lang !== '') $q->whereJsonContains('languages', $lang);
            }

            $selectedDevelopers = $request->query('developers', '');
            $devArr = $selectedDevelopers ? explode(',', $selectedDevelopers) : [];
            foreach ($devArr as $dev) {
                $dev = trim($dev);
                if ($dev !== '') $q->whereJsonContains('publisher', $dev);
            }

            if ($year = $request->query('year')) {
                $q->where('year', (int)$year);
            }

            $titleExpr = 'COALESCE(title_english, title_romaji)';

            if ($scoreOrder !== 'none') {
                $q->orderBy(
                    (str_contains($scoreOrder, 'avg') ? 'avg_score' : 'user_score'),
                    (str_contains($scoreOrder, 'desc') ? 'desc' : 'asc')
                );
            } elseif ($yearOrder !== 'none') {
                $q->orderBy('year', $yearOrder === 'year_desc' ? 'desc' : 'asc');
            } elseif ($titleOrder !== 'none') {
                $q->orderByRaw("$titleExpr " . ($titleOrder === 'za' ? 'DESC' : 'ASC'));
            } else {
                $q->orderByRaw("$titleExpr ASC");
            }

            $perPage = 40;
            $p = $q->paginate($perPage)->appends($request->query());

            $allTags = Media::where('type', 'vn')
                ->pluck('tags')
                ->flatten()
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $allDevelopers = Media::where('type', 'vn')
                ->pluck('publisher')
                ->flatten()
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $allLanguages = Media::where('type', 'vn')
                ->pluck('languages')
                ->flatten()
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $allYears = Media::where('type', 'vn')
                ->whereNotNull('year')
                ->distinct()->orderBy('year')->pluck('year')->toArray();

            $cards = $p->getCollection()
                ->map(fn($row) => $this->toCard($row))
                ->values();

            $p->setCollection($cards);

            return view('category', [
                'category' => 'VISUAL-NOVEL',
                'media' => $cards->all(),
                'paginatedMedia' => $p,

                'listFilter' => $listFilter,
                'titleOrder' => $titleOrder,
                'scoreOrder' => $scoreOrder,
                'yearOrder' => $yearOrder,

                'allTags' => $allTags,
                'selectedTags' => array_filter(explode(',', (string)$request->query('tags', ''))),

                'allDevelopers' => $allDevelopers,
                'selectedDevelopers' => $devArr,

                'allLanguages' => $allLanguages,
                'selectedLanguages' => $selectedLanguages,

                'allYears' => $allYears,
                'selectedYears' => (array)$request->query('year', []),
            ]);
        }

        /* -------------- ANILIST-------------- */
        $q = Media::query();

        /** -----------------------
         * Read filters from query
         * ----------------------*/
// --- selected filters coming from query ---
        $studioParam = $request->query('studio', '');
        $selectedStudio = is_array($studioParam)
            ? array_filter($studioParam)
            : array_filter(array_map('trim', explode(',', (string)$studioParam)));

        $authorParam = $request->query('author', '');
        $selectedAuthor = is_array($authorParam)
            ? array_filter($authorParam)
            : array_filter(array_map('trim', explode(',', (string)$authorParam)));

// --- apply filters: studios only for anime/hentai; authors only for manga/manwha ---
        $applyPublisherFilter = function ($query, $value) {
            $query->where(function ($qq) use ($value) {
                $qq->whereJsonContains('publisher', $value)
                    ->orWhere('publisher', 'LIKE', '%"'.$value.'"%')
                    ->orWhere('publisher', 'LIKE', '%'.$value.'%');
            });
        };

        /** ------------------------------------------
         * Category narrowing (type)
         * -----------------------------------------*/
        switch (strtoupper($normalized)) {
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
            case 'DOUJINS':                                  // ← add this
                $q->where('type', 'doujin');
                break;
            default:
                break;
        }

        /** ------------------------------------------
         * Apply studio/author filters
         * - Studios live in `publisher` for anime/hentai
         * - Authors live in `publisher` for manga/manwha
         *   (adjust column to `authors` if your schema has it)
         * -----------------------------------------*/
        if (in_array($normalized, ['ANIMES','HENTAIS'])) {
            foreach ($selectedStudio as $studio) {
                if ($studio !== '') $applyPublisherFilter($q, $studio);
            }
        }

        if (in_array($normalized, ['MANGAS','MANWHAS','DOUJINS'])) {
            foreach ($selectedAuthor as $author) {
                if ($author !== '') $applyPublisherFilter($q, $author);
            }
        }


        /** ------------------------------------------
         * Other filters (year, genres, tags, statuses)
         * -----------------------------------------*/
        if ($year = $request->query('year')) {
            $q->where('year', $year);
        }

        $genreParams = (array)$request->query('genre', []);
        foreach ($genreParams as $g) {
            if ($g !== '') $q->whereJsonContains('genres', $g);
        }

        if ($tagsCsv = $request->query('tags')) {
            foreach (explode(',', $tagsCsv) as $t) {
                $t = trim($t);
                if ($t !== '') $q->whereJsonContains('tags', $t);
            }
        }

        if ($listFilter !== 'all') $q->where('list_status', $listFilter);
        if ($mediaStatus !== 'all') $q->where('media_status', $mediaStatus);

        /** ------------------------------------------
         * Sorting
         * -----------------------------------------*/
        $titleExpr = 'COALESCE(title_english, title_romaji)';

        if ($scoreOrder !== 'none') {
            $q->orderBy(
                (str_contains($scoreOrder, 'avg') ? 'avg_score' : 'user_score'),
                (str_contains($scoreOrder, 'desc') ? 'desc' : 'asc')
            );
        } elseif ($dateOrder !== 'none') {
            $col = str_contains($dateOrder, 'start') ? 'start_date'
                : (str_contains($dateOrder, 'updated') ? 'list_updated_at'
                    : (str_contains($dateOrder, 'created') ? 'list_created_at' : 'start_date'));
            $q->orderBy($col, str_contains($dateOrder, 'desc') ? 'desc' : 'asc');
        } elseif ($titleOrder !== 'none') {
            $q->orderByRaw("$titleExpr " . ($titleOrder === 'za' ? 'DESC' : 'ASC'));
        } else {
            $q->orderByRaw("$titleExpr ASC");
        }

        /** ------------------------------------------
         * Pagination
         * -----------------------------------------*/
        $perPage = 40;
        $p = $q->paginate($perPage)->appends($request->query());

        /** ------------------------------------------
         * Sidebar lists (don’t nuke selected values)
         * -----------------------------------------*/
        $allGenres = Media::selectRaw('JSON_EXTRACT(genres, "$") as g')
            ->whereNotNull('genres')->get()
            ->flatMap(fn($row) => $this->toArray($row->g))
            ->unique()->sort()->values()->all();

        $allTags = Media::selectRaw('JSON_EXTRACT(tags, "$") as t')
            ->whereNotNull('tags')->get()
            ->flatMap(fn($row) => $this->toArray($row->t))
            ->unique()->sort()->values()->all();

        $allYears = Media::whereNotNull('year')->distinct()->orderBy('year')->pluck('year')->toArray();

        $allStudios = [];
        if ($normalized === 'ANIMES') {
            $allStudios = Media::where('type','anime')
                ->whereNotNull('publisher')
                ->pluck('publisher')
                ->flatMap(fn ($arr) => (array) $arr)
                ->filter()->unique()->sort()->values()->all();
        } elseif ($normalized === 'HENTAIS') {
            $allStudios = Media::where('type','hentai')
                ->whereNotNull('publisher')
                ->pluck('publisher')
                ->flatMap(fn ($arr) => (array) $arr)
                ->filter()->unique()->sort()->values()->all();
        }

        $allAuthors = [];
        if ($normalized === 'MANGAS') {
            $allAuthors = Media::where('type','manga')
                ->whereNotNull('publisher')
                ->pluck('publisher')
                ->flatMap(fn ($arr) => (array) $arr)
                ->filter()->unique()->sort()->values()->all();
        } elseif ($normalized === 'MANWHAS') {
            $allAuthors = Media::where('type','manwha')
                ->whereNotNull('publisher')
                ->pluck('publisher')
                ->flatMap(fn ($arr) => (array) $arr)
                ->filter()->unique()->sort()->values()->all();
        } elseif ($normalized === 'DOUJINS') {                      // ← add this
            $allAuthors = Media::where('type','doujin')
                ->whereNotNull('publisher')
                ->pluck('publisher')
                ->flatMap(fn ($arr) => (array) $arr)
                ->filter()->unique()->sort()->values()->all();
        }
        /** ------------------------------------------
         * Map to cards + return
         * -----------------------------------------*/
        $cards = $p->getCollection()->map(fn($row) => $this->toCard($row))->values();
        $p->setCollection($cards);

        return view('category', [
            'category' => ucfirst(str_replace('-', ' ', $category)),
            'media' => $cards->all(),
            'paginatedMedia' => $p,

            'listFilter' => $listFilter,
            'mediaStatus' => $mediaStatus,
            'titleOrder' => $titleOrder,
            'scoreOrder' => $scoreOrder,
            'dateOrder' => $dateOrder,

            'allTags' => $allTags,
            'allGenres' => $allGenres,
            'selectedGenres' => $genreParams,

            'allYears' => $allYears,
            'selectedYears' => (array)$request->query('year', []),

            'allStudios' => $allStudios,
            'selectedStudio' => $selectedStudio,

            'allAuthors' => $allAuthors,
            'selectedAuthor' => $selectedAuthor,
        ]);
    }

    private function splitAuthorsFromValue($v): array
    {
        if (!is_string($v) || $v === '') return [];
        // split on common joiners: ",", "&", " and ", " x ", "×"
        $parts = preg_split('/\s*(?:,|&| and | x |×)\s*/i', $v);
        return array_values(array_filter(array_map('trim', $parts)));
    }


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
