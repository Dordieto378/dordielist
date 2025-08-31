<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Collection;
use App\Models\Favorite;
use App\Models\CollectionItem;

class AnilistController extends Controller
{
    public function home(Request $request)
    {
        $accessToken = env('ANILIST_ACCESS_TOKEN');

        $viewerId = $this->getViewerId($accessToken);
        if (!$viewerId) {
            return "Unable to retrieve AniList user ID.";
        }

        $animeMedia = $this->fetchUserMedia($viewerId, "ANIME", $accessToken);
        $mangaMedia = $this->fetchUserMedia($viewerId, "MANGA", $accessToken);
        $allMedia   = array_merge($animeMedia, $mangaMedia);

        usort($allMedia, function ($a, $b) {
            $dateA = isset($a['startDate'])
                ? sprintf('%04d%02d%02d', $a['startDate']['year'] ?? 0, $a['startDate']['month'] ?? 0, $a['startDate']['day'] ?? 0)
                : '00000000';
            $dateB = isset($b['startDate'])
                ? sprintf('%04d%02d%02d', $b['startDate']['year'] ?? 0, $b['startDate']['month'] ?? 0, $b['startDate']['day'] ?? 0)
                : '00000000';
            return strcmp($dateB, $dateA);
        });

        $dropped = array_filter($allMedia, function($m) {
            return isset($m['listStatus']) && strtoupper($m['listStatus']) === 'DROPPED';
        });
        usort($dropped, function($a, $b) {
            return ($b['averageScore'] ?? 0) <=> ($a['averageScore'] ?? 0);
        });
        $dropped = array_slice($dropped, 0, 12);

        $scored = array_filter($allMedia, function($m) {
            return !empty($m['userScore']);
        });
        usort($scored, function($a, $b) {
            return ($b['userScore'] ?? 0) <=> ($a['userScore'] ?? 0);
        });
        $highestRated4 = array_slice($scored, 0, 4);

        $page    = (int) $request->input('page', 1);
        $perPage = 24;
        $offset  = ($page - 1) * $perPage;
        $pageItems = array_slice($allMedia, $offset, $perPage);

        $paginator = new LengthAwarePaginator(
            $pageItems,
            count($allMedia),
            $perPage,
            $page,
            [
                'path'  => $request->url(),
                'query' => $request->query(),
            ]
        );

        $selectedView = $request->input('view', 'grid');

        return view('home', [
            'dropped'        => $dropped,
            'highestRated4'  => $highestRated4,
            'paginatedMedia' => $paginator,
            'selectedView'   => $selectedView,
        ]);
    }

    /**
     * Get the user's AniList ID via GraphQL.
     */
    private function getViewerId(string $accessToken): ?int
    {
        $url = 'https://graphql.anilist.co';
        $query = '{ Viewer { id } }';

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type'  => 'application/json',
        ])->post($url, ['query' => $query]);

        if (!$response->successful()) {
            return null;
        }
        $data = $response->json();
        return $data['data']['Viewer']['id'] ?? null;
    }

    /**
     * Fetch all anime or manga from the user's AniList account with versioned caching.
     */
    private function fetchUserMedia(int $userId, string $type, string $accessToken): array
    {
        $url = 'https://graphql.anilist.co';

        // -- Step 1: Get Version Information (lightweight query) --
        $versionQuery = <<<GQL
        query (\$userId: Int, \$type: MediaType) {
            MediaListCollection(userId: \$userId, type: \$type) {
                lists {
                    entries {
                        updatedAt
                    }
                }
            }
        }
        GQL;

        $variables = [
            'userId' => $userId,
            'type'   => $type, 
        ];

        $versionResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type'  => 'application/json',
        ])->post($url, [
            'query'     => $versionQuery,
            'variables' => $variables,
        ]);

        if (!$versionResponse->successful()) {
            return [];
        }

        $versionJson = $versionResponse->json();
        $updatedDates = [];
        if (!empty($versionJson['data']['MediaListCollection']['lists'])) {
            foreach ($versionJson['data']['MediaListCollection']['lists'] as $list) {
                foreach ($list['entries'] as $entry) {
                    if (isset($entry['updatedAt'])) {
                        $updatedDates[] = $entry['updatedAt'];
                    }
                }
            }
        }

        $version = !empty($updatedDates) ? md5(implode('-', $updatedDates)) : 'default_version';

        $cacheKey = 'anilist_user_media_' . $userId . '_' . strtolower($type) . '_' . $version;

        return Cache::rememberForever($cacheKey, function () use ($userId, $type, $accessToken, $url) {
            $fullQuery = <<<GQL
            query (\$userId: Int, \$type: MediaType) {
                MediaListCollection(userId: \$userId, type: \$type) {
                    lists {
                        entries {
                            score
                            progress
                            status
                            updatedAt
                            createdAt
                            media {
                                id
                                type
                                title {
                                    english
                                    romaji
                                }
                                coverImage {
                                    large
                                    extraLarge
                                }
                                bannerImage
                                description
                                genres
                                tags {
                                    name
                                }
                                averageScore
                                episodes
                                duration
                                chapters
                                volumes
                                format
                                status
                                isAdult 
                                startDate {
                                    year
                                    month
                                    day
                                }
                                endDate {
                                    year
                                    month
                                    day
                                }
                                countryOfOrigin
                            }
                        }
                    }
                }
            }
            GQL;

            $variables = [
                'userId' => $userId,
                'type'   => $type,
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json',
            ])->post($url, [
                'query'     => $fullQuery,
                'variables' => $variables,
            ]);

            if (!$response->successful()) {
                return [];
            }

            $json = $response->json();
            $mediaItems = [];

            if (!empty($json['data']['MediaListCollection']['lists'])) {
                foreach ($json['data']['MediaListCollection']['lists'] as $list) {
                    foreach ($list['entries'] as $entry) {
                        if (isset($entry['media'])) {
                            $media = $entry['media'];
                            
                            $media['userScore']    = $entry['score'];
                            $media['userProgress'] = $entry['progress'];
                            $media['listStatus']   = $entry['status'];

                            if (isset($media['tags']) && is_array($media['tags'])) {
                                $media['tags'] = array_map(function($tag) {
                                    return is_array($tag) && isset($tag['name'])
                                        ? $tag['name']
                                        : '';
                                }, $media['tags']);
                            } else {
                                $media['tags'] = [];
                            }
                            
                            $media['description'] = isset($media['description']) 
                                ? strip_tags($media['description']) 
                                : 'No synopsis available.';
                            
                            $mediaItems[] = $media;
                        }
                    }
                }
            }
            
            return array_values($mediaItems);
        });
    }

        
    public function getAllMedia()
    {
        $accessToken = env('ANILIST_ACCESS_TOKEN');
        $viewerId = $this->getViewerId($accessToken);
        
        if (!$viewerId) {
            return response()->json([], 400);
        }

        $animeMedia = $this->fetchUserMedia($viewerId, "ANIME", $accessToken);
        $mangaMedia = $this->fetchUserMedia($viewerId, "MANGA", $accessToken);
        $allMedia = array_merge($animeMedia, $mangaMedia);

        return response()->json($allMedia);
    }

    public function show($id)
    {
        $accessToken = env('ANILIST_ACCESS_TOKEN');
        $item        = $this->fetchSingleItem($id, $accessToken);

        if (! $item) {
            abort(404);
        }

        $type     = strtoupper($item['type'] ?? '');
        $genres   = $item['genres'] ?? [];
        // countryOfOrigin is a string like "JP", "KR", etc.
        $origin   = strtoupper($item['countryOfOrigin'] ?? '');
        $id   = strtoupper($item['id'] ?? '');

        if ($type === 'ANIME' && in_array('Hentai', $genres, true)) {
            $category = 'hentais';
        } elseif ($type === 'ANIME') {
            $category = 'animes';
        } elseif ($type === 'MANGA') {
            // first check for Korea
            if ($origin === 'KR') {
                $category = 'manwhas';
            } else {
                $category = 'mangas';
            }
        } 
        $isFavorited = Favorite::where([
            ['favoritable_type', $category],
            ['favoritable_id',   $id],
        ])->exists();

        $allCollections = Collection::orderBy('is_system','desc')
                                    ->orderBy('name')
                                    ->get();

        $attachedIds = CollectionItem::where('item_type', $category)
                                    ->where('item_id',   $id)
                                    ->pluck('collection_id')
                                    ->toArray();

        return view('media.anilist', [
            'item'           => $item,
            'id'            => $id,
            'category'       => $category,
            'isFavorited'    => $isFavorited,
            'allCollections' => $allCollections,
            'attachedIds'    => $attachedIds,
        ]);
    }




    public function fetchSingleItem($id, $accessToken)
    {
        $url = 'https://graphql.anilist.co';
        $query = <<<GQL
            query (\$id: Int) {
                Media(id: \$id) {
                    id
                    type
                    title {
                        english
                        romaji
                    }
                    coverImage {
                        extraLarge
                    }
                    bannerImage
                    description
                    genres
                    tags {
                        name
                    }
                    averageScore
                    episodes
                    chapters
                    volumes
                    format
                    status
                    isAdult   
                    startDate {
                        year
                        month
                        day
                    }
                    countryOfOrigin
                    studios {
                        edges {
                            isMain
                            node {
                                name
                            }
                        }
                    }
                    staff {
                        edges {
                            node {
                                name {
                                    full
                                }
                            }
                            role
                        }
                    }
                    mediaListEntry {
                        score
                        progress
                        status
                    }
                }
            }
    GQL;
    
        $variables = ['id' => (int) $id];
    
        $response = Http::withHeaders([
            'Authorization' => "Bearer $accessToken",
            'Content-Type'  => 'application/json',
        ])->post($url, [
            'query'     => $query,
            'variables' => $variables,
        ]);
    
        if (!$response->successful()) {
            \Log::error('AniList Single API Error', ['response' => $response->body()]);
            return null;
        }
    
        $json = $response->json();
        $item = $json['data']['Media'] ?? null;
    
        if ($item && isset($item['description'])) {
            $item['description'] = strip_tags($item['description']);
        }
    
        \Log::debug('Single Media JSON:', $json);
        return $item;
    }

}
