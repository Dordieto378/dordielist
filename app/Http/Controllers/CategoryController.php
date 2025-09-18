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
//        if ($row instanceof \App\Models\Doujin) {
//            $raw = $row->cover_url;
//            $cover = str_starts_with($raw, 'images/')
//                ? asset($raw)
//                : \Storage::disk('b2')->url($raw);
//
//            return [
//                'id'    => $row->id,
//                'url'   => route('media.doujin', ['doujin' => $row->id]),
//                'cover' => $cover,
//                'title' => $row->doujin_name,
//                'nsfw'  => true,
//            ];
//        }

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
                    ? route('vn.show', ['id' => $row->source_id])
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
//        if ($normalized === 'DOUJINS') {
//            $perPage   = 40;
//            $nameOrder = $request->query('name_order', 'none');
//
//            $allAuthors = Doujin::query()->select('author_name')->distinct()->orderBy('author_name')->pluck('author_name')->toArray();
//
//            $selectedAuthors = $request->query('author', []);
//            if (!is_array($selectedAuthors)) $selectedAuthors = explode(',', (string)$selectedAuthors);
//
//            $query = Doujin::query();
//            if ($nameOrder === 'az')      $query->orderBy('doujin_name');
//            elseif ($nameOrder === 'za')  $query->orderByDesc('doujin_name');
//            else                          $query->orderBy('id');
//
//            foreach ($selectedAuthors as $authorName) {
//                if ($authorName !== '') $query->where('author_name', 'LIKE', "%{$authorName}%");
//            }
//
//            $paginator = $query->paginate($perPage, ['*'], 'page')->appends([
//                'author'     => implode(',', $selectedAuthors),
//                'name_order' => $nameOrder,
//            ]);
//
//            return view('category', [
//                'category'        => 'DOUJINS',
//                'media'           => $paginator->items(),
//                'paginatedMedia'  => $paginator,
//                'nameOrder'       => $nameOrder,
//                'allAuthors'      => $allAuthors,
//                'selectedAuthors' => $selectedAuthors,
//                // keep Blade happy for fields it might read:
//                'allStudios'      => [],
//                'selectedStudio'  => [],
//                'allTags'         => [],
//                'allGenres'       => [],
//                'selectedGenres'  => [],
//                'allYears'        => [],
//                'selectedYears'   => [],
//            ]);
//        }

        /* -------------------- VNDB -------------------- */
        if ($normalized === 'VISUAL-NOVEL') {
            $q = Media::query()->where('type', 'vn');

            $listFilter = strtolower($request->query('list_filter', 'all'));
            if ($listFilter !== 'all') {
                $q->where('list_status', strtoupper($listFilter));
            }

            $titleOrder = $request->query('title_order', 'none');
            $scoreOrder = $request->query('score_order', 'none');     // avg_* or personal_*
            $yearOrder  = $request->query('year_order',  'none');

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

            $allTags = Media::where('type','vn')
                ->pluck('tags')
                ->flatten()
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $allDevelopers = Media::where('type','vn')
                ->pluck('publisher')
                ->flatten()
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $allLanguages = Media::where('type','vn')
                ->pluck('languages')
                ->flatten()
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();

            $allYears = Media::where('type','vn')
                ->whereNotNull('year')
                ->distinct()->orderBy('year')->pluck('year')->toArray();

            $cards = $p->getCollection()
                ->map(fn ($row) => $this->toCard($row))
                ->values();

            $p->setCollection($cards);

            return view('category', [
                'category'           => 'VISUAL-NOVEL',
                'media'           => $cards->all(),
                'paginatedMedia'     => $p,

                'listFilter'         => $listFilter,
                'titleOrder'         => $titleOrder,
                'scoreOrder'         => $scoreOrder,
                'yearOrder'          => $yearOrder,

                'allTags'            => $allTags,
                'selectedTags'       => array_filter(explode(',', (string)$request->query('tags',''))),

                'allDevelopers'      => $allDevelopers,
                'selectedDevelopers' => $devArr,

                'allLanguages'       => $allLanguages,
                'selectedLanguages'  => $selectedLanguages,

                'allYears'           => $allYears,
                'selectedYears'      => (array)$request->query('year', []),
            ]);
        }

        /* -------------- ANILIST-------------- */
        $q = Media::query();

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
                break;
        }

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

        if ($listFilter !== 'all') {
            $q->where('list_status', $listFilter);
        }
        if ($mediaStatus !== 'all') {
            $q->where('media_status', $mediaStatus);
        }

        $titleExpr = 'COALESCE(title_english, title_romaji)';

        if ($scoreOrder !== 'none') {
            $q->orderBy(
                (str_contains($scoreOrder, 'avg') ? 'avg_score' : 'user_score'),
                (str_contains($scoreOrder, 'desc') ? 'desc' : 'asc')
            );
        } elseif ($dateOrder !== 'none') {
            $col = str_contains($dateOrder,'start')   ? 'start_date'
                : (str_contains($dateOrder,'updated') ? 'list_updated_at'
                : (str_contains($dateOrder,'created') ? 'list_created_at' : 'start_date'));
            $q->orderBy($col, str_contains($dateOrder,'desc') ? 'desc' : 'asc');
        } elseif ($titleOrder !== 'none') {
            $q->orderByRaw("$titleExpr " . ($titleOrder === 'za' ? 'DESC' : 'ASC'));
        } else {
            $q->orderByRaw("$titleExpr ASC");
        }

        $perPage = 40;
        $p = $q->paginate($perPage)->appends($request->query());

        $allGenres = Media::selectRaw('JSON_EXTRACT(genres, "$") as g')
            ->whereNotNull('genres')->get()
            ->flatMap(fn($row) => $this->toArray($row->g))
            ->unique()->sort()->values()->all();

        $allTags = Media::selectRaw('JSON_EXTRACT(tags, "$") as t')
            ->whereNotNull('tags')->get()
            ->flatMap(fn($row) => $this->toArray($row->t))
            ->unique()->sort()->values()->all();

        $allYears  = Media::whereNotNull('year')->distinct()->orderBy('year')->pluck('year')->toArray();

        $allStudios = [];
        if (in_array($normalized, ['ANIMES','HENTAIS'])) {
            $allStudios = Media::whereIn('type', ['anime','hentai'])
                ->whereNotNull('publisher')
                ->pluck('publisher')
                ->flatMap(fn ($arr) => (array) $arr)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        $allAuthors      = [];
        $selectedAuthor  = [];

        $cards = $p->getCollection()
            ->map(fn ($row) => $this->toCard($row))
            ->values();

        $p->setCollection($cards);

        return view('category', [
            'category'        => ucfirst(str_replace('-', ' ', $category)),
            'media'           => $cards->all(),
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
            'allStudios'      => $allStudios,
            'selectedStudio'  => $selectedStudio,
            'allAuthors'      => $allAuthors,
            'selectedAuthor'  => $selectedAuthor,
        ]);
    }

//    public function showDoujin(Doujin $doujin)
//    {
//        $encoded = implode('/', array_map('rawurlencode', explode('/', $doujin->cover_url)));
//        $coverUrl = Storage::disk(config('filesystems.default'))->url($encoded);
//
//        $isFavorited = Favorite::where([
//            ['favoritable_type', 'doujins'],
//            ['favoritable_id',   $doujin->id],
//        ])->exists();
//
//        $allCollections = Collection::orderBy('name')->get();
//
//        $attachedIds = CollectionItem::where([
//            ['item_type', 'doujins'],
//            ['item_id',   $doujin->id],
//        ])->pluck('collection_id')->toArray();
//
//        return view('media.doujin', [
//            'doujin'         => $doujin,
//            'coverUrl'       => $coverUrl,
//            'allCollections' => $allCollections,
//            'attachedIds'    => $attachedIds,
//            'isFavorited'    => $isFavorited,
//        ]);
//    }


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
