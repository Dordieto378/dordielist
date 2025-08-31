<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use App\Models\Media;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;

class AnilistController extends Controller
{
    /**
     * Home page (local DB only).
     */
    public function home(Request $request)
    {
        // Pull everything from local Media and map to AniList-like arrays your blades expect.
        $all = Media::query()->get()->map(fn ($m) => $this->mapMediaRow($m))->all();

        // Newest first by startDate.year
        usort($all, fn($a,$b) =>
            (sprintf('%04d%02d%02d', $b['startDate']['year'] ?? 0, $b['startDate']['month'] ?? 0, $b['startDate']['day'] ?? 0))
            <=>
            (sprintf('%04d%02d%02d', $a['startDate']['year'] ?? 0, $a['startDate']['month'] ?? 0, $a['startDate']['day'] ?? 0))
        );

        // Dropped: from list_status on Media
        $dropped = array_values(array_filter($all, fn($m) => ($m['listStatus'] ?? '') === 'DROPPED'));
        usort($dropped, fn($a,$b) => ($b['averageScore'] ?? 0) <=> ($a['averageScore'] ?? 0));
        $dropped = array_slice($dropped, 0, 12);

        // Highest rated 4 by userScore
        $scored = array_values(array_filter($all, fn($m) => ($m['userScore'] ?? 0) > 0));
        usort($scored, fn($a,$b) => ($b['userScore'] ?? 0) <=> ($a['userScore'] ?? 0));
        $highestRated4 = array_slice($scored, 0, 4);

        // Pagination (24/page)
        $page    = (int) $request->input('page', 1);
        $perPage = 24;
        $offset  = ($page - 1) * $perPage;
        $slice   = array_slice($all, $offset, $perPage);

        $paginator = new LengthAwarePaginator(
            $slice, count($all), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('home', [
            'dropped'        => $dropped,
            'highestRated4'  => $highestRated4,
            'paginatedMedia' => $paginator,
            'selectedView'   => $request->input('view', 'grid'),
        ]);
    }

    /**
     * Quick-search source: /api/media (local only).
     * Returns AniList-like objects (ANIME or MANGA).
     */
    public function getAllMedia()
    {
        $all = Media::query()->get()->map(fn ($m) => [
            'id'         => $m->id,
            'type'       => strtoupper($m->type), // 'ANIME' | 'MANGA'
            'title'      => ['english' => $m->title, 'romaji' => $m->alt_title],
            'coverImage' => ['extraLarge' => $this->coverUrl($m->cover_path)],
            'genres'     => $this->toArray($m->genres),
            'isAdult'    => (bool) $m->is_adult,
            'countryOfOrigin' => $m->origin, // 'JP' | 'KR' | ...
        ])->values()->all();

        return response()->json($all);
    }

    /**
     * Media details page: /media/{id} (renders your existing media.anilist blade).
     */
    public function show($id)
    {
        $m = Media::findOrFail($id);
        $item = $this->mapMediaRow($m); // AniList-like array the blade expects

        // Decide category string for favorites/collections like your old logic
        $genres = $item['genres'] ?? [];
        $type   = strtoupper($item['type'] ?? '');
        $origin = strtoupper($item['countryOfOrigin'] ?? '');
        if ($type === 'ANIME' && in_array('Hentai', $genres, true)) {
            $category = 'hentais';
        } elseif ($type === 'ANIME') {
            $category = 'animes';
        } elseif ($type === 'MANGA') {
            $category = ($origin === 'KR') ? 'manwhas' : 'mangas';
        } else {
            $category = 'animes';
        }

        $isFavorited = Favorite::where([
            ['favoritable_type', $category],
            ['favoritable_id',   $m->id],
        ])->exists();

        $allCollections = Collection::orderBy('is_system','desc')->orderBy('name')->get();
        $attachedIds = CollectionItem::where('item_type', $category)
                                     ->where('item_id',   $m->id)
                                     ->pluck('collection_id')->toArray();

        return view('media.anilist', [
            'item'           => $item,
            'id'             => $m->id,
            'category'       => $category,
            'isFavorited'    => $isFavorited,
            'allCollections' => $allCollections,
            'attachedIds'    => $attachedIds,
        ]);
    }

    /* ---------- helpers ---------- */

    private function mapMediaRow(Media $m): array
    {
        $genres = $this->toArray($m->genres);
        $tags   = $this->toArray($m->tags);

        return [
            'id'          => $m->id,
            'type'        => strtoupper($m->type), // 'ANIME' | 'MANGA'
            'title'       => ['english' => $m->title, 'romaji' => $m->alt_title],
            'coverImage'  => ['extraLarge' => $this->coverUrl($m->cover_path)],
            'bannerImage' => null,
            'description' => $m->description ?: 'No synopsis available.',
            'genres'      => $genres,
            'tags'        => $tags,            // strings are fine; blade handles both
            'averageScore'=> $m->avg_score,
            'episodes'    => null,             // your blade shows N/A if null
            'chapters'    => null,
            'volumes'     => null,
            'format'      => null,
            'status'      => $m->media_status, // optional column (e.g., FINISHED)
            'isAdult'     => (bool) $m->is_adult,
            'startDate'   => ['year' => $m->year, 'month' => null, 'day' => null],
            'countryOfOrigin' => $m->origin,   // 'JP', 'KR', ...
            // list entry fields your blade uses for "My Score" and progress
            'mediaListEntry' => [
                'score'    => $m->user_score,
                'progress' => $m->progress,
                'status'   => $m->list_status, // e.g., CURRENT / DROPPED
            ],
            // convenience copies used by home page
            'userScore'   => $m->user_score,
            'userProgress'=> $m->progress,
            'listStatus'  => $m->list_status,
        ];
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

    private function coverUrl(?string $path): string
    {
        if (!$path) return asset('images/no-image.jpg');
        // Uses your default filesystem disk (public for local, b2 for B2).
        return Storage::url($path);
    }
}
