<?php

namespace App\Http\Controllers;

use App\Models\AnilistAuthor;
use App\Models\AnilistGenre;
use App\Models\AnilistStudio;
use App\Models\AnilistTag;
use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Models\VnDeveloper;
use App\Models\VnLanguage;
use App\Models\VnTag;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private function toCard($row): array
    {
        if (is_array($row) && isset($row['url'], $row['cover'])) {
            return $row;
        }

        if ($row->type === 'doujin') {
            $title = $row->title_english ?: ($row->title_romaji ?: ($row->title_native ?: 'No Title'));

            $cover = $row->cover_url ?: null;
            if ($cover && !preg_match('#^https?://#i', $cover)) {
                $cover = \Storage::url(ltrim($cover, '/'));
            }
            if (!$cover) {
                $cover = asset('images/no-image.jpg');
            }

            return [
                'id' => $row->id,
                'url' => route('doujins.show', ['media' => $row->id]),
                'cover' => $cover,
                'title' => $title,
                'nsfw' => (int) ($row->isNsfw ?? 0) === 1,
            ];
        }

        if ($row instanceof Media) {
            $isVN = $row->type === 'vn';

            return [
                'id' => $row->id,
                'url' => $isVN
                    ? route('vn.show', ['id' => $row->id])
                    : route('media.show', ['id' => $row->id]),
                'cover' => $row->cover_url ?: asset('images/no-image.jpg'),
                'title' => $row->title_english ?: ($row->title_romaji ?: ($row->title_native ?: 'No Title')),
                'nsfw' => (int) ($row->isNsfw ?? 0) === 1,
            ];
        }

        return [
            'id' => 0,
            'url' => '#',
            'cover' => asset('images/no-image.jpg'),
            'title' => 'No Title',
            'nsfw' => false,
        ];
    }

    public function show(Request $request, $category, $listFilter = 'all', $mediaStatus = 'all', $titleOrder = 'none', $scoreOrder = 'none', $dateOrder = 'none')
    {
        $normalized = strtoupper($category);

        if ($normalized === 'DOUJINS') {
            $q = Media::query()
                ->where('type', 'doujin')
                ->with('doujinAuthors:id,name');

            $nameOrder = $request->query('name_order', 'none');

            $selectedAuthors = $request->query('author', []);
            if (!is_array($selectedAuthors)) {
                $selectedAuthors = array_filter(array_map('trim', explode(',', (string) $selectedAuthors)));
            }
            $selectedAuthors = array_values(array_filter($selectedAuthors));

            foreach ($selectedAuthors as $author) {
                $q->whereHas('doujinAuthors', fn ($query) => $query->where('name', $author));
            }

            $titleExpr = 'COALESCE(NULLIF(title_romaji,""), NULLIF(title_english,""), NULLIF(title_native,""), slug)';
            if ($nameOrder === 'az') {
                $q->orderByRaw("$titleExpr ASC")
                    ->orderBy('id', 'asc');
            } elseif ($nameOrder === 'za') {
                $q->orderByRaw("$titleExpr DESC")
                    ->orderBy('id', 'asc');
            } else {
                $q->orderBy('id', 'asc');
            }

            $perPage = 40;
            $p = $q->paginate($perPage)->appends($request->query());

            $allAuthors = DoujinAuthor::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'doujin'))
                ->orderBy('name')
                ->pluck('name')
                ->all();

            $cards = $p->getCollection()
                ->map(fn ($row) => $this->toCard($row))
                ->values();
            $p->setCollection($cards);

            return view('category', [
                'category' => 'DOUJINS',
                'media' => $cards->all(),
                'paginatedMedia' => $p,
                'nameOrder' => $nameOrder,
                'allAuthors' => $allAuthors,
                'selectedAuthors' => $selectedAuthors,
            ]);
        }

        if ($normalized === 'VISUAL-NOVEL') {
            $q = Media::query()
                ->where('type', 'vn')
                ->with(['vnTags:id,name', 'vnLanguages:id,name', 'vnDevelopers:id,name']);

            $listFilter = strtolower($request->query('list_filter', 'all'));
            if ($listFilter !== 'all') {
                $q->where('list_status', strtoupper($listFilter));
            }

            $titleOrder = $request->query('title_order', 'none');
            $scoreOrder = $request->query('score_order', 'none');
            $yearOrder = $request->query('year_order', 'none');

            if ($tagsCsv = $request->query('tags')) {
                foreach (explode(',', $tagsCsv) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') {
                        $q->whereHas('vnTags', fn ($query) => $query->where('name', $tag));
                    }
                }
            }

            $selectedLanguages = $request->query('language', []);
            if (!is_array($selectedLanguages)) {
                $selectedLanguages = explode(',', $selectedLanguages);
            }
            foreach ($selectedLanguages as $language) {
                if ($language !== '') {
                    $q->whereHas('vnLanguages', fn ($query) => $query->where('name', $language));
                }
            }

            $selectedDevelopers = $request->query('developers', '');
            $devArr = $selectedDevelopers ? explode(',', $selectedDevelopers) : [];
            foreach ($devArr as $developer) {
                $developer = trim($developer);
                if ($developer !== '') {
                    $q->whereHas('vnDevelopers', fn ($query) => $query->where('name', $developer));
                }
            }

            if ($year = $request->query('year')) {
                $q->where('year', (int) $year);
            }

            $titleExpr = 'COALESCE(NULLIF(title_english,""), NULLIF(title_romaji,""), NULLIF(title_native,""))';

            if ($scoreOrder !== 'none') {
                $q->orderBy(
                    str_contains($scoreOrder, 'avg') ? 'avg_score' : 'user_score',
                    str_contains($scoreOrder, 'desc') ? 'desc' : 'asc'
                )->orderBy('id', 'asc');
            } elseif ($yearOrder !== 'none') {
                $q->orderBy('year', $yearOrder === 'year_desc' ? 'desc' : 'asc')
                    ->orderBy('id', 'asc');
            } elseif ($titleOrder !== 'none') {
                $q->orderByRaw("$titleExpr ".($titleOrder === 'za' ? 'DESC' : 'ASC'))
                    ->orderBy('id', 'asc');
            } else {
                $q->orderByRaw("$titleExpr ASC")
                    ->orderBy('id', 'asc');
            }

            $perPage = 40;
            $p = $q->paginate($perPage)->appends($request->query());

            $allTags = VnTag::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'vn'))
                ->orderBy('name')
                ->pluck('name')
                ->all();

            $allDevelopers = VnDeveloper::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'vn'))
                ->orderBy('name')
                ->pluck('name')
                ->all();

            $allLanguages = VnLanguage::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'vn'))
                ->orderBy('name')
                ->pluck('name')
                ->all();

            $allYears = Media::where('type', 'vn')
                ->whereNotNull('year')
                ->distinct()
                ->orderBy('year')
                ->pluck('year')
                ->toArray();

            $cards = $p->getCollection()
                ->map(fn ($row) => $this->toCard($row))
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
                'selectedTags' => array_filter(explode(',', (string) $request->query('tags', ''))),
                'allDevelopers' => $allDevelopers,
                'selectedDevelopers' => $devArr,
                'allLanguages' => $allLanguages,
                'selectedLanguages' => $selectedLanguages,
                'allYears' => $allYears,
                'selectedYears' => (array) $request->query('year', []),
            ]);
        }

        $q = Media::query()->with([
            'anilistGenres:id,name',
            'anilistTags:id,name',
            'anilistStudios:id,name',
            'anilistAuthors:id,name',
        ]);

        $studioParam = $request->query('studio', '');
        $selectedStudio = is_array($studioParam)
            ? array_filter($studioParam)
            : array_filter(array_map('trim', explode(',', (string) $studioParam)));

        $authorParam = $request->query('author', '');
        $selectedAuthor = is_array($authorParam)
            ? array_filter($authorParam)
            : array_filter(array_map('trim', explode(',', (string) $authorParam)));

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

        if (in_array($normalized, ['ANIMES', 'HENTAIS'], true)) {
            foreach ($selectedStudio as $studio) {
                if ($studio !== '') {
                    $q->whereHas('anilistStudios', fn ($query) => $query->where('name', $studio));
                }
            }
        }

        if (in_array($normalized, ['MANGAS', 'MANWHAS'], true)) {
            foreach ($selectedAuthor as $author) {
                if ($author !== '') {
                    $q->whereHas('anilistAuthors', fn ($query) => $query->where('name', $author));
                }
            }
        }

        if ($year = $request->query('year')) {
            $q->where('year', $year);
        }

        $genreParams = $request->query('genre', []);
        if (!is_array($genreParams)) {
            $genreParams = array_map('trim', explode(',', (string) $genreParams));
        }
        $genreParams = array_values(array_filter($genreParams, fn ($genre) => $genre !== ''));

        foreach ($genreParams as $genre) {
            $q->whereHas('anilistGenres', fn ($query) => $query->where('name', $genre));
        }

        if ($tagsCsv = $request->query('tags')) {
            foreach (explode(',', $tagsCsv) as $tag) {
                $tag = trim($tag);
                if ($tag !== '') {
                    $q->whereHas('anilistTags', fn ($query) => $query->where('name', $tag));
                }
            }
        }

        if ($listFilter !== 'all') {
            $q->where('list_status', $listFilter);
        }
        if ($mediaStatus !== 'all') {
            $q->where('media_status', $mediaStatus);
        }

        $titleExpr = 'COALESCE(NULLIF(title_english,""), NULLIF(title_romaji,""), NULLIF(title_native,""))';

        if ($scoreOrder !== 'none') {
            $q->orderBy(
                str_contains($scoreOrder, 'avg') ? 'avg_score' : 'user_score',
                str_contains($scoreOrder, 'desc') ? 'desc' : 'asc'
            )->orderBy('id', 'asc');
        } elseif ($dateOrder !== 'none') {
            $col = str_contains($dateOrder, 'start') ? 'start_date'
                : (str_contains($dateOrder, 'updated') ? 'list_updated_at'
                    : (str_contains($dateOrder, 'created') ? 'list_created_at' : 'start_date'));
            $q->orderBy($col, str_contains($dateOrder, 'desc') ? 'desc' : 'asc')
                ->orderBy('id', 'asc');
        } elseif ($titleOrder !== 'none') {
            $q->orderByRaw("$titleExpr ".($titleOrder === 'za' ? 'DESC' : 'ASC'))
                ->orderBy('id', 'asc');
        } else {
            $q->orderByRaw("$titleExpr ASC")
                ->orderBy('id', 'asc');
        }

        $perPage = 40;
        $p = $q->paginate($perPage)->appends($request->query());

        $allGenres = AnilistGenre::query()
            ->whereHas('media', fn ($query) => $query->whereIn('type', ['anime', 'hentai', 'manga', 'manwha']))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $allTags = AnilistTag::query()
            ->whereHas('media', fn ($query) => $query->whereIn('type', ['anime', 'hentai', 'manga', 'manwha']))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $allYears = Media::whereNotNull('year')
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->toArray();

        $allStudios = [];
        if ($normalized === 'ANIMES') {
            $allStudios = AnilistStudio::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'anime'))
                ->orderBy('name')
                ->pluck('name')
                ->all();
        } elseif ($normalized === 'HENTAIS') {
            $allStudios = AnilistStudio::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'hentai'))
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        $allAuthors = [];
        if ($normalized === 'MANGAS') {
            $allAuthors = AnilistAuthor::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'manga'))
                ->orderBy('name')
                ->pluck('name')
                ->all();
        } elseif ($normalized === 'MANWHAS') {
            $allAuthors = AnilistAuthor::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'manwha'))
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        $cards = $p->getCollection()->map(fn ($row) => $this->toCard($row))->values();
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
            'selectedYears' => (array) $request->query('year', []),
            'allStudios' => $allStudios,
            'selectedStudio' => $selectedStudio,
            'allAuthors' => $allAuthors,
            'selectedAuthor' => $selectedAuthor,
        ]);
    }
}
