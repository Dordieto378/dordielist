<?php

namespace App\Http\Controllers;

use App\Models\AnilistAuthor;
use App\Models\AnilistGenre;
use App\Models\AnilistStudio;
use App\Models\AnilistTag;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\DoujinAuthor;
use App\Models\Favorite;
use App\Models\Media;
use App\Support\DoujinAuthorLinks;
use App\Models\VnDeveloper;
use App\Models\VnLanguage;
use App\Models\VnTag;
use App\Support\VndbLanguages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private function displayCategoryTitle(string $category): string
    {
        return match (strtoupper($category)) {
            'ANIMES' => 'ANIME',
            'HENTAIS' => 'HENTAI',
            'MANGAS' => 'MANGA',
            'MANHWAS' => 'MANHWA',
            'LIGHT-NOVELS' => 'LIGHT NOVELS',
            'VISUAL-NOVEL' => 'VISUAL-NOVEL',
            'DOUJINS' => 'DOUJINS',
            default => strtoupper(str_replace('-', ' ', $category)),
        };
    }

    private function toCard($row): array
    {
        if (is_array($row) && isset($row['url'], $row['cover'])) {
            return $row;
        }

        if ($row->type === 'doujin') {
            $title = $row->title_english ?: ($row->slug ?: 'No Title');

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

    private function eraLabel(int $decade): string
    {
        if ($decade >= 1900 && $decade < 2000) {
            return substr((string) $decade, 2, 2).'s';
        }

        return $decade.'s';
    }

    private function collectionFilterData(Request $request, string $itemType, string $mediaType): array
    {
        $mediaIds = Media::query()
            ->select('id')
            ->where('type', $mediaType);

        $options = collect();

        $hasFavorites = Favorite::query()
            ->where('favoritable_type', $itemType)
            ->whereIn('favoritable_id', clone $mediaIds)
            ->exists();

        if ($hasFavorites) {
            $options->push([
                'value' => 'favorites',
                'label' => 'Favorites',
            ]);
        }

        $collections = Collection::query()
            ->where('is_system', false)
            ->whereHas('items', fn (Builder $query) => $query
                ->where('item_type', $itemType)
                ->whereIn('item_id', clone $mediaIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($collections as $collection) {
            $options->push([
                'value' => (string) $collection->id,
                'label' => $collection->name,
            ]);
        }

        $selected = (string) $request->query('collection', '');
        if (! $options->contains(fn (array $option) => $option['value'] === $selected)) {
            $selected = '';
        }

        return [
            'collectionOptions' => $options->all(),
            'selectedCollection' => $selected,
        ];
    }

    private function applyCollectionFilter(Builder $query, string $selected, string $itemType): void
    {
        if ($selected === 'favorites') {
            $query->whereIn('id', Favorite::query()
                ->select('favoritable_id')
                ->where('favoritable_type', $itemType));

            return;
        }

        if ($selected !== '') {
            $query->whereIn('id', CollectionItem::query()
                ->select('item_id')
                ->where('item_type', $itemType)
                ->where('collection_id', (int) $selected));
        }
    }

    public function show(Request $request, $category, $listFilter = 'all', $mediaStatus = 'all', $titleOrder = 'none', $scoreOrder = 'none', $dateOrder = 'none')
    {
        $normalized = strtoupper($category);

        if ($normalized === 'DOUJINS') {
            $q = Media::query()
                ->where('type', 'doujin')
                ->with(['doujinAuthors:id,name']);

            $collectionFilter = $this->collectionFilterData($request, 'doujins', 'doujin');
            $this->applyCollectionFilter($q, $collectionFilter['selectedCollection'], 'doujins');

            $nameOrder = $request->query('name_order', 'none');
            $selectedAuthors = $request->query('author', []);
            if (!is_array($selectedAuthors)) {
                $selectedAuthors = array_filter(array_map('trim', explode(',', (string) $selectedAuthors)));
            }
            $selectedAuthors = array_values(array_filter($selectedAuthors));

            foreach ($selectedAuthors as $author) {
                $q->whereHas('doujinAuthors', fn ($query) => $query->where('name', $author));
            }

            $titleExpr = 'COALESCE(NULLIF(title_english,""), slug)';
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

            $allAuthorRows = DoujinAuthor::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'doujin'))
                ->orderBy('name')
                ->get(['name', 'twitter_url', 'patreon_url', 'fanbox_url', 'pixiv_url']);
            $allAuthors = $allAuthorRows->pluck('name')->all();
            $allAuthorLinks = $allAuthorRows
                ->mapWithKeys(fn (DoujinAuthor $author) => [
                    $author->name => DoujinAuthorLinks::payload($author),
                ]);
            $cards = $p->getCollection()
                ->map(fn ($row) => $this->toCard($row))
                ->values();
            $p->setCollection($cards);

            return view('category', [
                'category' => $this->displayCategoryTitle($normalized),
                'categorySlug' => strtolower($category),
                'media' => $cards->all(),
                'paginatedMedia' => $p,
                'nameOrder' => $nameOrder,
                'allAuthors' => $allAuthors,
                'allAuthorLinks' => $allAuthorLinks,
                'selectedAuthors' => $selectedAuthors,
                ...$collectionFilter,
            ]);
        }

        if ($normalized === 'VISUAL-NOVEL') {
            $q = Media::query()
                ->where('type', 'vn')
                ->with(['vnTags:id,name', 'vnLanguages:id,name', 'vnDevelopers:id,name']);

            $collectionFilter = $this->collectionFilterData($request, 'visual-novel', 'vn');
            $this->applyCollectionFilter($q, $collectionFilter['selectedCollection'], 'visual-novel');

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
            $selectedLanguages = array_values(array_filter(
                array_map('trim', $selectedLanguages),
                fn ($language) => $language !== ''
            ));
            foreach ($selectedLanguages as $language) {
                $q->whereHas('vnLanguages', fn ($query) => $query->where('name', $language));
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

            $allLanguageCodes = VnLanguage::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'vn'))
                ->orderBy('name')
                ->pluck('name')
                ->all();
            $allLanguages = VndbLanguages::options($allLanguageCodes);

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
                'category' => $this->displayCategoryTitle($normalized),
                'categorySlug' => strtolower($category),
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
                ...$collectionFilter,
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

        $categoryTypes = match ($normalized) {
            'ANIMES' => ['anime'],
            'HENTAIS' => ['hentai'],
            'MANGAS' => ['manga'],
            'MANHWAS' => ['manhwa'],
            'LIGHT-NOVELS' => ['light_novel'],
            default => ['anime', 'hentai', 'manga', 'manhwa', 'light_novel'],
        };

        $q->whereIn('type', $categoryTypes);

        $collectionItemType = strtolower($normalized);
        $collectionFilter = $this->collectionFilterData($request, $collectionItemType, $categoryTypes[0]);
        $this->applyCollectionFilter($q, $collectionFilter['selectedCollection'], $collectionItemType);

        if (in_array($normalized, ['ANIMES', 'HENTAIS'], true)) {
            foreach ($selectedStudio as $studio) {
                if ($studio !== '') {
                    $q->whereHas('anilistStudios', fn ($query) => $query->where('name', $studio));
                }
            }
        }

        if (in_array($normalized, ['MANGAS', 'MANHWAS', 'LIGHT-NOVELS'], true)) {
            foreach ($selectedAuthor as $author) {
                if ($author !== '') {
                    $q->whereHas('anilistAuthors', fn ($query) => $query->where('name', $author));
                }
            }
        }

        $selectedEra = (string) $request->query('era', '');
        if ($selectedEra !== '' && preg_match('/^\d+$/', $selectedEra)) {
            $eraStart = (int) $selectedEra;
            $q->whereBetween('year', [$eraStart, $eraStart + 9]);
        } else {
            $selectedEra = '';
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
            ->whereHas('media', fn ($query) => $query->whereIn('type', ['anime', 'hentai', 'manga', 'manhwa', 'light_novel']))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $allTags = AnilistTag::query()
            ->whereHas('media', fn ($query) => $query->whereIn('type', ['anime', 'hentai', 'manga', 'manhwa', 'light_novel']))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $allYears = Media::whereIn('type', $categoryTypes)
            ->whereNotNull('year')
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->filter(fn ($year) => $year > 0)
            ->toArray();

        $allEras = collect($allYears)
            ->map(fn ($year) => intdiv((int) $year, 10) * 10)
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($decade) => [
                'value' => (string) $decade,
                'label' => $this->eraLabel((int) $decade),
            ])
            ->all();

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
        } elseif ($normalized === 'MANHWAS') {
            $allAuthors = AnilistAuthor::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'manhwa'))
                ->orderBy('name')
                ->pluck('name')
                ->all();
        } elseif ($normalized === 'LIGHT-NOVELS') {
            $allAuthors = AnilistAuthor::query()
                ->whereHas('media', fn ($query) => $query->where('type', 'light_novel'))
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        $cards = $p->getCollection()->map(fn ($row) => $this->toCard($row))->values();
        $p->setCollection($cards);

        return view('category', [
            'category' => $this->displayCategoryTitle($normalized),
            'categorySlug' => strtolower($category),
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
            'allEras' => $allEras,
            'selectedEra' => $selectedEra,
            'allStudios' => $allStudios,
            'selectedStudio' => $selectedStudio,
            'allAuthors' => $allAuthors,
            'selectedAuthor' => $selectedAuthor,
            ...$collectionFilter,
        ]);
    }
}
