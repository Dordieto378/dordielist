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
    public function home(Request $request)
    {
        $all = Media::query()->get()->map(fn ($m) => $this->mapMediaRow($m))->all();

        usort($all, fn($a,$b) =>
            (sprintf('%04d%02d%02d', $b['startDate']['year'] ?? 0, $b['startDate']['month'] ?? 0, $b['startDate']['day'] ?? 0))
            <=>
            (sprintf('%04d%02d%02d', $a['startDate']['year'] ?? 0, $a['startDate']['month'] ?? 0, $a['startDate']['day'] ?? 0))
        );

        $dropped = array_values(array_filter($all, fn($m) => ($m['listStatus'] ?? '') === 'DROPPED'));
        usort($dropped, fn($a,$b) => ($b['averageScore'] ?? 0) <=> ($a['averageScore'] ?? 0));
        $dropped = array_slice($dropped, 0, 12);

        $scored = array_values(array_filter($all, fn($m) => ($m['userScore'] ?? 0) > 0));
        usort($scored, fn($a,$b) => ($b['userScore'] ?? 0) <=> ($a['userScore'] ?? 0));
        $highestRated4 = array_slice($scored, 0, 4);

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

    public function getAllMedia()
    {
        $all = Media::query()->get()->map(function (Media $m) {
            $publisher = $this->toArray($m->publisher);
            $studios   = in_array($m->type, ['anime','hentai'], true) ? $publisher : [];
            $authors   = in_array($m->type, ['manga','manwha'], true) ? $publisher : [];

            return [
                'id'         => $m->id,
                'type'       => strtoupper($m->type),
                'title'      => ['english' => $m->title_english, 'romaji' => $m->title_romaji],
                'coverImage' => ['extraLarge' => $this->externalOrStorage($m->cover_url)],
                'genres'     => $this->toArray($m->genres),
                'countryOfOrigin' => $m->origin,
                'studios'    => $studios,
                'authors'    => $authors,
            ];
        })->values()->all();

        return response()->json($all);
    }

    public function show($id)
    {
        $m = Media::findOrFail($id);
        $item = $this->mapMediaRow($m);

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

    private function mapMediaRow(Media $m): array
    {
        // JSON-ish columns in your table
        $genres   = $this->toArray($m->genres);
        $tags     = $this->toArray($m->tags);
        $publisher = $this->toArray($m->publisher);
        $languages= $this->toArray($m->languages);

        $studios = in_array($m->type, ['anime','hentai'], true) ? $publisher : [];
        $authors = in_array($m->type, ['manga','manwha'], true) ? $publisher : [];

        $descHtml = $m->description ?? '';

        $desc = preg_replace('/<\s*br\s*\/?>/i', "\n", $descHtml);
        $desc = preg_replace('/<\/p>\s*<p>/i', "\n\n", $desc);

        $desc = strip_tags($desc);
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $desc = preg_replace("/\r\n?/", "\n", $desc);
        $desc = preg_replace("/[ \t]+$/m", "", $desc);
        $desc = preg_replace("/\n{3,}/", "\n\n", $desc);
        $desc = trim($desc);

        $descPlain = $desc;

        // start_date (DATE) -> year/month/day
        $year  = $m->year ?: (optional(\Carbon\Carbon::parse($m->start_date))->year);
        $month = optional(\Carbon\Carbon::parse($m->start_date))->month;
        $day   = optional(\Carbon\Carbon::parse($m->start_date))->day;

        return [
            'id'          => $m->id,
            'type'        => strtoupper($m->type),
            'title'       => [
                'english' => $m->title_english,
                'romaji'  => $m->title_romaji,
            ],
            'coverImage'  => ['extraLarge' => $m->cover_url ? asset($m->cover_url) : asset('images/no-image.jpg')],
            'bannerImage' => $this->coverUrl($m->banner_url),
            'description' => $descPlain ?: 'No synopsis available.',
            'genres'      => $genres,
            'tags'        => $tags,
            'averageScore'=> $m->avg_score,
            'episodes'    => $m->episodes_cnt ?: null,
            'chapters'    => $m->chapters_cnt ?: null,
            'volumes'     => $m->volumes_cnt ?: null,
            'format'      => null,
            'status'      => $m->media_status,
            'startDate'   => ['year' => $year, 'month' => $month, 'day' => $day],
            'countryOfOrigin' => $m->origin,
            'studios'     => $studios,
            'authors'     => $authors,
            'mediaListEntry' => [
                'score'    => $m->user_score,
                'progress' => null,
                'status'   => $m->list_status,
            ],
            'userScore'   => $m->user_score,
            'userProgress'=> null,
            'listStatus'  => $m->list_status,
            'languages'   => $languages,
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
        return Storage::url($path);
    }
}
