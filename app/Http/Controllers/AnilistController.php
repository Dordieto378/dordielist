<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use App\Models\Media;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

        $wishlikeStatuses = ['WISHLIST', 'PLANNING', 'PLAN TO WATCH', 'PLAN TO READ'];

        $wish = array_values(array_filter($all, function ($m) use ($wishlikeStatuses) {
            $status = strtoupper($m['listStatus'] ?? '');
            $avg    = $m['averageScore'] ?? null;

            return in_array($status, $wishlikeStatuses, true)
                && $avg !== null && $avg !== '' && is_numeric($avg);
        }));

        usort($wish, function ($a, $b) {
            $avgA = (float) ($a['averageScore'] ?? -1);
            $avgB = (float) ($b['averageScore'] ?? -1);

            if ($avgB === $avgA) {
                $cmp = ((int)($b['userScore'] ?? 0)) <=> ((int)($a['userScore'] ?? 0));
                if ($cmp !== 0) return $cmp;

                $dateA = sprintf('%04d%02d%02d', $a['startDate']['year'] ?? 0, $a['startDate']['month'] ?? 0, $a['startDate']['day'] ?? 0);
                $dateB = sprintf('%04d%02d%02d', $b['startDate']['year'] ?? 0, $b['startDate']['month'] ?? 0, $b['startDate']['day'] ?? 0);
                return $dateB <=> $dateA;
            }
            return $avgB <=> $avgA;
        });

        $highestRated4 = array_slice($wish, 0, 4);

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

    /**
     * Derive a canonical type so unreleased AniList anime without episode counts
     * aren't mislabeled as manga/manwha locally.
     */
    private function canonicalType(Media $m): string
    {
        $t = strtolower($m->type);

        if ($m->source === 'anilist') {
            // If already anime/hentai, keep it.
            if (!in_array($t, ['manga', 'manwha'], true)) {
                return strtoupper($t);
            }

            // If episodes were synced later, trust that.
            if (!is_null($m->episodes_cnt)) {
                return 'ANIME';
            }

            // If no chapter/volume info AND unreleased/releasing, assume anime.
            $status = strtoupper((string) $m->media_status);
            if (is_null($m->chapters_cnt) && is_null($m->volumes_cnt)
                && in_array($status, ['NOT_YET_RELEASED', 'RELEASING'], true)) {
                return 'ANIME';
            }
        }

        return strtoupper($t);
    }

    private function mapMediaRow(Media $m): array
    {
        $genres   = $this->toArray($m->genres);
        $tags     = $this->toArray($m->tags);
        $publisher = $this->toArray($m->publisher);
        $languages= $this->toArray($m->languages);

        $canonicalType = $this->canonicalType($m);

        $studios = in_array(strtolower($canonicalType), ['anime','hentai'], true) ? $publisher : [];
        $authors = in_array(strtolower($canonicalType), ['manga','manwha'], true) ? $publisher : [];

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

        $year  = $m->year ?: (optional(\Carbon\Carbon::parse($m->start_date))->year);
        $month = optional(\Carbon\Carbon::parse($m->start_date))->month;
        $day   = optional(\Carbon\Carbon::parse($m->start_date))->day;

        return [
            'id'          => $m->id,
            'type'        => $canonicalType,
            'title'       => [
                'english' => $m->title_english,
                'romaji'  => $m->title_romaji,
            ],
            'coverImage'  => [
                'extraLarge' => $m->cover_url
                    ? (strtoupper($m->type) === 'DOUJIN'
                        ? Storage::url(ltrim($m->cover_url, '/'))
                        : asset($m->cover_url))
                    : asset('images/no-image.jpg'),
            ],
            'bannerImage' => $m->banner_url,
            'description' => $descPlain,
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
            'isNsfw' => (int)($m->isNsfw ?? 0),
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

    public function syncFromAnilist(Request $request)
    {
        $token = env('ANILIST_ACCESS_TOKEN');
        if (!$token) {
            return back()->with('error', 'ANILIST_ACCESS_TOKEN is missing in .env');
        }

        $viewerId = $this->getViewerId($token);
        if (!$viewerId) {
            return back()->with('error', 'Unable to get AniList Viewer ID');
        }

        $types = ['ANIME','MANGA'];
        $allEntries = [];
        foreach ($types as $type) {
            $entries = $this->fetchList($token, $viewerId, $type);
            $allEntries = array_merge($allEntries, $entries);
        }

        $seenIds = [];

        $created = 0; $updated = 0;

        DB::beginTransaction();
        try {
            foreach ($allEntries as $entry) {
                $media = $entry['media'] ?? null;
                if (!$media) continue;

                $sourceId  = $media['id'];
                $seenIds[] = $sourceId;

                $genres      = $media['genres'] ?? [];
                $tags        = array_values(array_filter(array_map(fn($t) => $t['name'] ?? null, $media['tags'] ?? [])));
                $genresLower = array_map('mb_strtolower', $genres);

                $isAdultFlag = (bool)($media['isAdult'] ?? false);
                $isNsfw      = $isAdultFlag || in_array('hentai', $genresLower, true);

                $origin   = $media['countryOfOrigin'] ?? null;
                $avgScore = $media['averageScore']     ?? null;
                $mStatus  = $media['status']           ?? null;
                $lStatus  = $entry['status']           ?? null;
                $uScore   = isset($entry['score']) ? (int)$entry['score'] : null;

                $publishers = [];
                if (!empty($media['studios']['edges'])) {
                    $mainStudios = [];
                    $producers   = [];
                    foreach ($media['studios']['edges'] as $edge) {
                        $name   = $edge['node']['name'] ?? null;
                        $isMain = !empty($edge['isMain']);
                        if (!$name) continue;
                        if ($isMain) $mainStudios[] = $name;
                    }
                    $publishers = array_merge($mainStudios, $producers);
                }
                if (!empty($media['staff']['edges'])) {
                    $allow = ['story','art','story & art'];
                    $denySubstrings = ['assistant','letter','touch','editor','translation','translator','publisher'];
                    foreach ($media['staff']['edges'] as $edge) {
                        $rawRole = strtolower($edge['role'] ?? '');
                        $name    = $edge['node']['name']['full'] ?? null;
                        if (!$name || $rawRole === '') continue;
                        $isDenied = false;
                        foreach ($denySubstrings as $bad) { if (str_contains($rawRole, $bad)) { $isDenied = true; break; } }
                        if ($isDenied) continue;
                        $role = preg_replace('/\s*\(.*?\)\s*/', ' ', $rawRole);
                        $role = str_replace([' and ', ',', '/', '・'], ' & ', $role);
                        $role = trim(preg_replace('/\s+/', ' ', $role));
                        if (in_array($role, $allow, true)) $publishers[] = $name;
                    }
                }
                $publishers = array_values(array_unique(array_filter($publishers)));

                $y = $media['startDate']['year']  ?? null;
                $m = $media['startDate']['month'] ?? null;
                $d = $media['startDate']['day']   ?? null;
                $startDate = $y ? Carbon::createFromDate($y, $m ?: 1, $d ?: 1)->toDateString() : null;

                $titleEn = $media['title']['english'] ?? null;
                $titleRo = $media['title']['romaji']  ?? null;
                $cover   = $media['coverImage']['extraLarge'] ?? null;
                $banner  = $media['bannerImage'] ?? null;
                $desc    = $media['description'] ?? null;

                $episodesCnt = $media['episodes'] ?? null;
                $chaptersCnt = $media['chapters'] ?? null;
                $volumesCnt  = $media['volumes']  ?? null;

                $remoteType = $this->guessRemoteType($media, $genres);
                if ($remoteType === 'ANIME') {
                    $localType = in_array('Hentai', $genres, true) ? 'hentai' : 'anime';
                } else {
                    $localType = strtoupper((string)$origin) === 'KR' ? 'manwha' : 'manga';
                }

                $base = $titleRo ?: $titleEn ?: ('media-'.$sourceId);
                $slug = Str::slug($base.'-al'.$sourceId);

                $values = [
                    'type'           => $localType,
                    'title_english'  => $titleEn,
                    'title_romaji'   => $titleRo,
                    'slug'           => $slug,
                    'cover_url'      => $cover,
                    'banner_url'     => $banner,
                    'description'    => $desc,
                    'genres'         => $genres,
                    'tags'           => $tags,
                    'publisher'      => $publishers ?: null,
                    'origin'         => $origin,
                    'list_status'    => $lStatus,
                    'media_status'   => $mStatus,
                    'user_score'     => $uScore,
                    'avg_score'      => $avgScore,
                    'year'           => $y,
                    'start_date'     => $startDate,
                    'episodes_cnt'   => ($remoteType === 'ANIME') ? $episodesCnt : null,
                    'chapters_cnt'   => ($remoteType === 'MANGA') ? $chaptersCnt : null,
                    'volumes_cnt'    => ($remoteType === 'MANGA') ? $volumesCnt  : null,
                    'languages'      => null,
                    'isNsfw'         => $isNsfw ? 1 : 0,
                ];

                $model = Media::updateOrCreate(
                    ['source' => 'anilist', 'source_id' => $sourceId],
                    $values
                );
                if ($model->wasRecentlyCreated) $created++; else $updated++;
            }

            $seenIds = array_values(array_unique($seenIds));
            if (!empty($seenIds)) {
                Media::where('source', 'anilist')
                    ->whereNotIn('source_id', $seenIds)
                    ->delete();
            } else {
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'AniList sync failed: '.$e->getMessage());
        }

        return back()->with('status', "AniList sync done. created={$created}, updated={$updated}.");
    }

    private function getViewerId(string $token): ?int
    {
        $query = '{ Viewer { id } }';
        $resp = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type'  => 'application/json',
        ])->post('https://graphql.anilist.co', ['query' => $query]);

        if (!$resp->successful()) return null;
        return $resp->json('data.Viewer.id');
    }

    private function fetchList(string $token, int $userId, string $type): array
    {
        $query = <<<'GQL'
    query ($userId:Int, $type:MediaType) {
      MediaListCollection(userId:$userId, type:$type) {
        lists {
          entries {
            status
            score
            createdAt
            updatedAt
            media {
              type
              format
              isAdult
              id
              title { english romaji }
              coverImage { extraLarge }
              bannerImage
              description
              genres
              startDate { year month day }
              countryOfOrigin
              status
              averageScore
              tags { name }
              studios { edges { isMain node { name } } }
              staff   { edges { node { name { full } } role } }
              episodes
              chapters
              volumes
            }
          }
        }
      }
    }
    GQL;

        $resp = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type'  => 'application/json',
        ])->post('https://graphql.anilist.co', [
            'query'     => $query,
            'variables' => ['userId' => $userId, 'type' => $type],
        ]);

        if (!$resp->successful()) return [];
        $lists = $resp->json('data.MediaListCollection.lists') ?? [];
        $out = [];
        foreach ($lists as $list) {
            foreach ($list['entries'] as $entry) $out[] = $entry;
        }
        return $out;
    }

    private function guessRemoteType(array $media, array $genres): string
    {
        // Prefer the explicit AniList media type when present.
        $type = strtoupper($media['type'] ?? '');
        if (in_array($type, ['ANIME', 'MANGA'], true)) {
            return $type;
        }

        // Fall back to format hints (AniList formats like TV, OVA, MOVIE, etc.).
        $format = strtoupper($media['format'] ?? '');
        $animeFormats = ['TV', 'TV_SHORT', 'MOVIE', 'SPECIAL', 'OVA', 'ONA', 'MUSIC'];
        if ($format && in_array($format, $animeFormats, true)) {
            return 'ANIME';
        }

        // Use available counts as a last resort.
        if (array_key_exists('episodes', $media) && $media['episodes'] !== null) {
            return 'ANIME';
        }
        if (array_key_exists('chapters', $media) && $media['chapters'] !== null) {
            return 'MANGA';
        }

        // Default to ANIME when unsure (prevents unreleased shows from being mis-filed as manga).
        return 'ANIME';
    }

}
