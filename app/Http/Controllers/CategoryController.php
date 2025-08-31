<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache; 
use App\Http\Controllers\VndbController;
use Illuminate\Support\Facades\File;  
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Doujin; 
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite; 
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

ini_set('memory_limit', '1024M');


class CategoryController extends Controller
{
    public function show(Request $request, $category, $listFilter = 'all', $mediaStatus = 'all', $titleOrder = 'none', $scoreOrder = 'none', $dateOrder = 'none')
    {
        $normalized = strtoupper($category);
        if ($normalized === 'DOUJINS') {
           $perPage   = 40;
            $nameOrder = $request->query('name_order', 'none');

            // 1a. Get all authors for the dropdown
            $allAuthors = Doujin::query()
                ->select('author_name')
                ->distinct()
                ->orderBy('author_name')
                ->pluck('author_name')
                ->toArray();

            // 1b. Figure out which authors were selected (qs: ?author=a,b,c)
            $selectedAuthors = $request->query('author', []);
            if (!is_array($selectedAuthors)) {
                $selectedAuthors = explode(',', $selectedAuthors);
            }

            // 1c. Build the base query
            $query = Doujin::query();

            // Apply name ordering if requested
            if ($nameOrder === 'az') {
                $query->orderBy('doujin_name');
            } elseif ($nameOrder === 'za') {
                $query->orderByDesc('doujin_name');
            } else {
                // fallback ordering (you can keep ID or whatever default you had)
                $query->orderBy('id');
            }

            // Apply author filters (AND‐style)
            if (count($selectedAuthors)) {
                foreach ($selectedAuthors as $authorName) {
                    $query->where('author_name', 'LIKE', "%{$authorName}%");
                }
            }

            // 2. Paginate *once*, then append the needed query string parameters
            $paginator = $query
                ->paginate($perPage, ['*'], 'page')
                ->appends([
                    'author'     => implode(',', $selectedAuthors),
                    'name_order' => $nameOrder,
                ]);

            // 3. Pass everything into the view
            return view('category', [
                'category'        => 'DOUJINS',
                'media'           => $paginator->items(),
                'paginatedMedia'  => $paginator,
                'nameOrder'       => $nameOrder,
                'allAuthors'      => $allAuthors,
                'selectedAuthors' => $selectedAuthors,
            ]);
        }

        if ($normalized === 'VISUAL-NOVEL') {

            $vndb      = new \App\Http\Controllers\VndbController;
            $username  = env('VNDB_USERNAME'); 
            $valid     = ['playing','finished','stalled','dropped','wishlist'];
            $requestLf = strtolower($listFilter);

            // “all” or anything unknown → empty status → VNDB returns *everything*
            if ($requestLf === 'all' || ! in_array($requestLf, $valid)) {
                $status = '';
            } else {
                $status = $requestLf;
            }

            $fullMedia = $vndb->getUserVnList($username, $status);

            $allTags       = $this->extractTags($fullMedia);
            $allDevelopers = $this->extractDevelopers($fullMedia);
            $titleOrder = $request->query('title_order', 'none');
            $scoreOrder = $request->query('score_order','none');
            $yearOrder  = $request->query('year_order', 'none');

            \Log::debug('devs raw:', $allDevelopers);


            $allTags = array_values(
            collect($fullMedia)
                ->flatMap(fn($e)=>array_column($e['tags'] ?? [], 'name'))
                ->unique()
                ->sort()
                ->toArray()
            );

            $selectedTags = $request->query('tags', '');
            if (!empty($selectedTags)) {
                $selectedTags = explode(',', $selectedTags);
                $fullMedia = array_filter($fullMedia, function($e) use ($selectedTags) {
                    $have = array_column($e['tags'] ?? [], 'name');
                    return count(array_intersect($selectedTags, $have)) === count($selectedTags);
                });
            }

            $selectedDevelopers = $request->query('developers', '');
            if (!empty($selectedDevelopers)) {
                $selectedDevelopers = explode(',', $selectedDevelopers);
                $fullMedia = array_filter($fullMedia, function($e) use ($selectedDevelopers) {
                    $have = array_column($e['developers'] ?? [], 'name');
                    return count(array_intersect($selectedDevelopers, $have)) === count($selectedDevelopers);
                });
            } else {
                $selectedDevelopers = [];
            }


            if ($titleOrder !== 'none') {
                usort($fullMedia, function($a, $b) use ($titleOrder) {
                    $ta = strtolower($a['title'] ?? '');
                    $tb = strtolower($b['title'] ?? '');
                    return $titleOrder === 'za'
                        ? strcmp($tb, $ta)
                        : strcmp($ta, $tb);
                });
            }
            
            if ($scoreOrder !== 'none') {
                usort($fullMedia, function($a, $b) use ($scoreOrder) {
                    if (str_starts_with($scoreOrder, 'avg')) {
                        $va = $a['average'];
                        $vb = $b['average'];
                        $desc = $scoreOrder === 'avg_desc';
                    } else {
                        $va = $a['score'];
                        $vb = $b['score'];
                        $desc = $scoreOrder === 'personal_desc';
                    }
                    return $desc
                        ? $vb <=> $va
                        : $va <=> $vb;
                });
            }

            if ($yearOrder !== 'none') {
                usort($fullMedia, function($a, $b) use ($yearOrder) {
                    $ya = $a['year'];
                    $yb = $b['year'];
                    return $yearOrder === 'year_desc'
                        ? $yb <=> $ya
                        : $ya <=> $yb;
                });
            }

            $allLanguages    = $this->extractLanguages($fullMedia);
            // --- ONLY keep one language filter block, and use the plain strings ---
            $selectedLanguages = $request->query('language', []);
            if (!is_array($selectedLanguages)) {            // handles ?language=en,ja too
                $selectedLanguages = explode(',', $selectedLanguages);
            }

            if (!empty($selectedLanguages)) {
                $fullMedia = array_filter(
                    $fullMedia,
                    fn ($e) =>
                        count(array_intersect(
                            $selectedLanguages,
                            $e['languages'] ?? []          // ← array of strings now
                        )) === count($selectedLanguages)
                );
            }


            // 2) read selected langs
            $selectedLanguages = $request->query('language', []);
            if (!is_array($selectedLanguages)) {
                $selectedLanguages = explode(',', $selectedLanguages);
            }

            if (!empty($selectedLanguages)) {
                $fullMedia = array_filter($fullMedia, function($e) use ($selectedLanguages) {
                    // $e['languages'] is an array of strings
                    return count(array_intersect($selectedLanguages, $e['languages'] ?? [])) === count($selectedLanguages);
                });
            }

            $perPage = 40;
            $page    = (int) $request->query('page', 1);
            $offset  = ($page - 1) * $perPage;
            $slice   = array_slice($fullMedia, $offset, $perPage);

            $basePath = route('category', [
                'category'    => $category,  
                'listFilter'  => $status  
            ]);

            $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
                $slice,
                count($fullMedia),
                $perPage,
                $page,
                [
                    'path'  => $basePath,     
                    'query' => $request->query(), 
                ]
            );


            return view('category', [
                'category'       => 'VISUAL-NOVEL',
                'media'          => $slice,
                'paginatedMedia' => $paginator,
                'allTags'        => $allTags,
                'selectedTags'   => $selectedTags,
                'allDevelopers'  => $allDevelopers,
                'selectedDevelopers'=> $selectedDevelopers,
                'listFilter'     => $status,
                'titleOrder'     => $titleOrder,
                'scoreOrder'     => $scoreOrder,
                'dateOrder'      => $dateOrder,
                'yearOrder'      => $yearOrder,
                'allLanguages'     => $allLanguages,
                'selectedLanguages'=> $selectedLanguages,
            ]);

        }

        $accessToken = env('ANILIST_ACCESS_TOKEN');

        $viewerId = $this->getViewerId($accessToken);
        if (!$viewerId) {
            return "Unable to retrieve AniList user ID.";
        }
        
        $fullMedia = $this->fetchMediaByCategory($viewerId, $category, $accessToken);
        
         $allTags   = $this->extractTags($fullMedia);
         $allGenres = $this->extractGenres($fullMedia);
         $allYears  = $this->extractYears($fullMedia);
         
         $normalizedCategory = strtoupper($category);
         if (in_array($normalizedCategory, ['ANIMES', 'HENTAIS'])) {
             $allStudios = $this->extractStudios($fullMedia);
             $selectedStudio = $request->query('studio', '');
         } else {
             $allAuthors = $this->extractAuthors($fullMedia);
             $selectedAuthor = $request->query('author', '');
         }
         
         $media = $fullMedia;
         
         if ($listFilter !== 'all') {
             $media = array_filter($media, function ($entry) use ($listFilter) {
                 return isset($entry['status']) && $entry['status'] === $listFilter;
             });
         }
         
         if ($mediaStatus !== 'all') {
             $media = array_filter($media, function ($entry) use ($mediaStatus) {
                 return isset($entry['media']['status']) && $entry['media']['status'] === $mediaStatus;
             });
         }
         

         if ($normalizedCategory === 'ANIMES') {
             $media = array_filter($media, function($entry) {
                 return !in_array('Hentai', $entry['media']['genres'] ?? []);
             });
         } elseif ($normalizedCategory === 'HENTAIS') {
             $media = array_filter($media, function($entry) {
                 return in_array('Hentai', $entry['media']['genres'] ?? []);
             });
         } elseif ($normalizedCategory === 'MANGAS') {
             $media = array_filter($media, function($entry) {
                 $isNonHentai = !in_array('Hentai', $entry['media']['genres'] ?? []);
                 $isJapanese  = isset($entry['media']['countryOfOrigin']) && strtoupper($entry['media']['countryOfOrigin']) === 'JP';
                 return $isNonHentai && $isJapanese;
             });
         } elseif ($normalizedCategory === 'DOUJINS') {
             $media = array_filter($media, function($entry) {
                 $isHentai   = in_array('Hentai', $entry['media']['genres'] ?? []);
                 $isJapanese = isset($entry['media']['countryOfOrigin']) && strtoupper($entry['media']['countryOfOrigin']) === 'JP';
                 return $isHentai && $isJapanese;
             });
         } elseif ($normalizedCategory === 'MANWHAS') {
             $media = array_filter($media, function($entry) {
                 return isset($entry['media']['countryOfOrigin']) && strtoupper($entry['media']['countryOfOrigin']) === 'KR';
             });
         }
         
         if ($scoreOrder !== 'none') {
             usort($media, function($a, $b) use ($scoreOrder, $dateOrder, $titleOrder) {
                 if (strpos($scoreOrder, 'avg') !== false) {
                     $scoreA = $a['media']['averageScore'] ?? 0;
                     $scoreB = $b['media']['averageScore'] ?? 0;
                 } elseif (strpos($scoreOrder, 'personal') !== false) {
                     $scoreA = $a['score'] ?? 0;
                     $scoreB = $b['score'] ?? 0;
                 } else {
                     $scoreA = $scoreB = 0;
                 }
                 if ($scoreA !== $scoreB) {
                     return ($scoreOrder === 'avg_desc' || $scoreOrder === 'personal_desc')
                         ? $scoreB <=> $scoreA
                         : $scoreA <=> $scoreB;
                 }
                 if ($dateOrder !== 'none') {
                     if (strpos($dateOrder, 'updated') !== false) {
                         $dateA = $a['updatedAt'] ?? 0;
                         $dateB = $b['updatedAt'] ?? 0;
                     } elseif (strpos($dateOrder, 'created') !== false) {
                         $dateA = $a['createdAt'] ?? 0;
                         $dateB = $b['createdAt'] ?? 0;
                     } elseif (strpos($dateOrder, 'start') !== false) {
                         $dateA = $a['media']['startDate']['year'] ?? 0;
                         $dateB = $b['media']['startDate']['year'] ?? 0;
                     } else {
                         $dateA = $dateB = 0;
                     }
                     if ($dateA !== $dateB) {
                         return in_array($dateOrder, ['updated_desc', 'created_desc', 'start_desc'])
                             ? $dateB <=> $dateA
                             : $dateA <=> $dateB;
                     }
                 }
                 $titleA = strtolower($a['media']['title']['english'] ?? $a['media']['title']['romaji'] ?? '');
                 $titleB = strtolower($b['media']['title']['english'] ?? $b['media']['title']['romaji'] ?? '');
                 return ($titleOrder === 'za') ? strcmp($titleB, $titleA) : strcmp($titleA, $titleB);
             });
         } elseif ($dateOrder !== 'none') {
             usort($media, function($a, $b) use ($dateOrder, $titleOrder) {
                 if (strpos($dateOrder, 'updated') !== false) {
                     $dateA = $a['updatedAt'] ?? 0;
                     $dateB = $b['updatedAt'] ?? 0;
                 } elseif (strpos($dateOrder, 'created') !== false) {
                     $dateA = $a['createdAt'] ?? 0;
                     $dateB = $b['createdAt'] ?? 0;
                 } elseif (strpos($dateOrder, 'start') !== false) {
                     $dateA = $a['media']['startDate']['year'] ?? 0;
                     $dateB = $b['media']['startDate']['year'] ?? 0;
                 } else {
                     $dateA = $dateB = 0;
                 }
                 if ($dateA !== $dateB) {
                     return in_array($dateOrder, ['updated_desc', 'created_desc', 'start_desc'])
                         ? $dateB <=> $dateA
                         : $dateA <=> $dateB;
                 }
                 $titleA = strtolower($a['media']['title']['english'] ?? $a['media']['title']['romaji'] ?? '');
                 $titleB = strtolower($b['media']['title']['english'] ?? $b['media']['title']['romaji'] ?? '');
                 return ($titleOrder === 'za') ? strcmp($titleB, $titleA) : strcmp($titleA, $titleB);
             });
         } elseif ($titleOrder !== 'none') {
             usort($media, function($a, $b) use ($titleOrder) {
                 $titleA = strtolower($a['media']['title']['english'] ?? $a['media']['title']['romaji'] ?? '');
                 $titleB = strtolower($b['media']['title']['english'] ?? $b['media']['title']['romaji'] ?? '');
                 return ($titleOrder === 'az') ? strcmp($titleA, $titleB) : strcmp($titleB, $titleA);
             });
         }
         
         $selectedTags = $request->query('tags', '');
         if (!empty($selectedTags)) {
             $selectedTags = array_map('trim', explode(',', $selectedTags));
             $media = array_filter($media, function($entry) use ($selectedTags) {
                 $tagNames = array_map(function($tag) {
                     return $tag['name'] ?? '';
                 }, $entry['media']['tags'] ?? []);
                 return count(array_intersect($selectedTags, $tagNames)) === count($selectedTags);
             });
         }
         
         $selectedGenres = $request->query('genre', []);
         if (!is_array($selectedGenres)) {
             $selectedGenres = [$selectedGenres];
         }
         if (!empty($selectedGenres)) {
             $media = array_filter($media, function($entry) use ($selectedGenres) {
                 $mediaGenres = array_map('strtolower', $entry['media']['genres'] ?? []);
                 foreach ($selectedGenres as $sel) {
                     if (!in_array(strtolower($sel), $mediaGenres)) {
                         return false;
                     }
                 }
                 return true;
             });
         }
         
         $selectedYears = $request->query('year', []);
         if (!is_array($selectedYears)) {
             $selectedYears = [$selectedYears];
         }
         if (!empty($selectedYears)) {
             $media = array_filter($media, function($entry) use ($selectedYears) {
                 $year = $entry['media']['startDate']['year'] ?? null;
                 return in_array($year, $selectedYears);
             });
         }
         
         if (in_array($normalizedCategory, ['ANIMES', 'HENTAIS'])) {
             $selectedStudio = $request->query('studio', []);
             if (!is_array($selectedStudio)) {
                 $selectedStudio = explode(',', $selectedStudio);
             }
             if (!empty($selectedStudio)) {
                 $media = array_filter($media, function($entry) use ($selectedStudio) {
                     if (empty($entry['media']['studios']['edges'])) {
                         return false;
                     }
                    $studioNames = collect($entry['media']['studios']['edges'] ?? [])
                        ->filter(fn ($e) => !empty($e['isMain']) && $e['isMain'])   
                        ->pluck('node.name')
                        ->all();
                    return count(array_intersect($selectedStudio, $studioNames)) === count($selectedStudio);
                 });
             }
         }        
         else {
             if (!empty($selectedAuthor)) {
                 if (!is_array($selectedAuthor)) {
                     $selectedAuthor = explode(',', $selectedAuthor);
                 }
                 $media = array_filter($media, function($entry) use ($selectedAuthor) {
                     if (empty($entry['media']['staff']['edges'])) {
                         return false;
                     }
                     $authorsForItem = [];
                     foreach ($entry['media']['staff']['edges'] as $edge) {
                         if (isset($edge['node']['name']['full']) && isset($edge['role'])) {
                             $role = strtolower($edge['role']);
                             if (in_array($role, ['story', 'art', 'story & art'])) {
                                 $authorsForItem[] = $edge['node']['name']['full'];
                             }
                         }
                     }
                     return count(array_intersect($selectedAuthor, $authorsForItem)) === count($selectedAuthor);
                 });
             }
         }
         
         $media = array_values($media);
         
         $perPage = 40;
         $page = (int) $request->input('page', 1);
         $offset = ($page - 1) * $perPage;
         $currentPageItems = array_slice($media, $offset, $perPage);
         
         $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
             $currentPageItems,
             count($media),
             $perPage,
             $page,
             [
                 'path'  => route('category', [
                     'category'   => $category,
                     'listFilter' => $listFilter,
                     'mediaStatus'=> $mediaStatus,
                     'titleOrder' => $titleOrder,
                     'scoreOrder' => $scoreOrder,
                     'dateOrder'  => $dateOrder,
                 ]),
                 'query' => $request->query(),
             ]
         );
         
         $viewData = [
             'category'       => ucfirst(str_replace('-', ' ', $category)),
             'media'          => $currentPageItems,
             'paginatedMedia' => $paginator, 
             'listFilter'     => $listFilter,
             'mediaStatus'    => $mediaStatus,
             'titleOrder'     => $titleOrder,
             'scoreOrder'     => $scoreOrder,
             'dateOrder'      => $dateOrder,
             'allTags'        => $allTags,
             'allGenres'      => $allGenres,
             'selectedGenres' => $selectedGenres,
             'allYears'       => $allYears,
             'selectedYears'  => $selectedYears,
         ];
         
         if (in_array($normalizedCategory, ['ANIMES', 'HENTAIS'])) {
             $viewData['allStudios'] = $allStudios;
             $viewData['selectedStudio'] = $selectedStudio;
         } else {
             $viewData['allAuthors'] = $allAuthors;
             $viewData['selectedAuthor'] = $selectedAuthor;
         }
         
         return view('category', $viewData);
    }
    
    // --- Helper methods ---
    private function extractTags(array $media): array
    {
        $allTags = [];
        foreach ($media as $entry) {
            if (!empty($entry['media']['tags'])) {
                foreach ($entry['media']['tags'] as $tag) {
                    if (!empty($tag['name'])) {
                        $allTags[$tag['name']] = $tag['name'];
                    }
                }
            }
        }
        $allTags = array_values($allTags);
        sort($allTags, SORT_NATURAL | SORT_FLAG_CASE);
        return $allTags;
    }
    
    private function extractGenres(array $media): array
    {
        $allGenres = [];
        foreach ($media as $entry) {
            if (!empty($entry['media']['genres'])) {
                foreach ($entry['media']['genres'] as $genre) {
                    $allGenres[strtolower($genre)] = $genre;
                }
            }
        }
        $allGenres = array_values($allGenres);
        sort($allGenres, SORT_NATURAL | SORT_FLAG_CASE);
        return $allGenres;
    }
    
    private function extractYears(array $media): array
    {
        $years = [];
        foreach ($media as $entry) {
            if (!empty($entry['media']['startDate']['year'])) {
                $years[] = $entry['media']['startDate']['year'];
            }
        }
        $years = array_unique($years);
        sort($years, SORT_NUMERIC);
        return array_values($years);
    }
    
    private function extractStudios(array $media): array
    {
        $studios = [];

        foreach ($media as $entry) {
            foreach ($entry['media']['studios']['edges'] ?? [] as $edge) {
                if (!empty($edge['isMain']) && $edge['isMain']                     // ⟵ keep only main studio
                    && isset($edge['node']['name'])) {
                    $studios[$edge['node']['name']] = $edge['node']['name'];
                }
            }
        }

        ksort($studios, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($studios);
    }
    
    private function extractAuthors(array $media): array
    {
        $authors = [];
        foreach ($media as $entry) {
            if (!empty($entry['media']['staff']['edges'])) {
                foreach ($entry['media']['staff']['edges'] as $edge) {
                    if (isset($edge['node']['name']['full']) && isset($edge['role'])) {
                        $role = strtolower($edge['role']);
                        if (in_array($role, ['story', 'art', 'story & art'])) {
                            $authors[] = $edge['node']['name']['full'];
                        }
                    }
                }
            }
        }
        $authors = array_unique($authors);
        sort($authors, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($authors);
    }
    
    private function getViewerId(string $accessToken): ?int
    {
        $cacheKey = 'anilist_viewer_id';
        return Cache::remember($cacheKey, now()->addHour(), function () use ($accessToken) {
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
        });
    }
    
    private function fetchMediaByCategory(int $userId, string $category, string $accessToken): array
    {
        $type = strtoupper($category);
        $url = 'https://graphql.anilist.co';
    
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
            'type'   => in_array($type, ['MANGAS', 'DOUJINS', 'MANWHAS']) ? "MANGA" : "ANIME",
        ];
    
        $versionResponse = Http::withHeaders([
            'Authorization' => "Bearer {$accessToken}",
            'Content-Type'  => 'application/json',
        ])->post($url, [
            'query'     => $versionQuery,
            'variables' => $variables,
        ]);
    
        if (!$versionResponse->successful()) {
            logger()->error('AniList Version API Error', ['response' => $versionResponse->body()]);
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
        $version = !empty($updatedDates) ? md5(implode('-', $updatedDates)) : date('YmdHi');
        $cacheKey = 'anilist_media_' . $userId . '_' . strtolower($category) . '_' . $version;
    
        return Cache::rememberForever($cacheKey, function () use ($userId, $category, $accessToken, $type, $url) {
            $fullQuery = <<<GQL
            query (\$userId: Int, \$type: MediaType) {
                MediaListCollection(userId: \$userId, type: \$type) {
                    lists {
                        entries {
                            status
                            score
                            updatedAt
                            createdAt
                            media {
                                id
                                title {
                                    english
                                    romaji
                                }
                                coverImage {
                                    extraLarge
                                }
                                genres
                                startDate {
                                    year
                                }
                                countryOfOrigin
                                status
                                averageScore
                                tags {
                                    name
                                }
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
                                isAdult
                            }
                        }
                    }
                }
            }
            GQL;
    
            $variables = [
                'userId' => $userId,
                'type'   => in_array($type, ['MANGAS', 'DOUJINS', 'MANWHAS']) ? "MANGA" : "ANIME",
            ];
    
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Content-Type'  => 'application/json',
            ])->post($url, [
                'query'     => $fullQuery,
                'variables' => $variables
            ]);

            if (!$response->successful()) {
                logger()->error('AniList Full API Error', ['response' => $response->body()]);
                return [];
            }
    
            $json = $response->json();
            $mediaItems = [];
            if (!empty($json['data']['MediaListCollection']['lists'])) {
                foreach ($json['data']['MediaListCollection']['lists'] as $list) {
                    foreach ($list['entries'] as $entry) {
                        if (isset($entry['media'])) {
                            $mediaItems[] = $entry;
                        }
                    }
                }
            }

            return array_values($mediaItems);
        });
    }

    /**
     *
     * @param  array  $media
     * @return array
     */
    private function extractDevelopers(array $media): array
    {
        $devs = [];

        foreach ($media as $entry) {
            if (empty($entry['developers']) || ! is_array($entry['developers'])) {
                continue;
            }

            foreach ($entry['developers'] as $dev) {
                if (is_string($dev)) {
                    $name = trim($dev);
                } elseif (is_array($dev) && isset($dev['name']) && is_scalar($dev['name'])) {
                    $name = trim((string) $dev['name']);
                } else {
                    continue;
                }

                if ($name === '') {
                    continue;
                }

                $devs[$name] = $name;
            }
        }

        natcasesort($devs);
        return array_values($devs);
    }

    private function fetchVnListFromVndb(string $query = ''): array
    {
        if (trim($query) === '') {
            return []; 
        }

        $vndb       = new VndbController();
        $fakeReq    = new Request(['query' => $query]);
        $response   = $vndb->searchVN($fakeReq)->getData(true);

        return $response['results'] ?? [];
    }

    private function extractLanguages(array $media): array
    {
        $langs = [];
        foreach ($media as $e) {
            foreach ($e['languages'] ?? [] as $langName) {
                $langs[$langName] = $langName;
            }
        }
        $langs = array_values($langs);
        sort($langs, SORT_NATURAL | SORT_FLAG_CASE);
        return $langs;
    }

    public function showDoujin(Doujin $doujin)
    {
        // 1) Re‐build the “raw” key for Backblaze
        $rawPath      = $doujin->cover_url; 
        $parts        = explode('/', $rawPath);
        $encodedParts = array_map(fn($seg) => rawurlencode($seg), $parts);
        $encodedPath  = implode('/', $encodedParts);

        // 2) CHANGE: get a B2/S3‐style URL instead of asset(...)
        $coverUrl = Storage::disk('b2')->url($encodedPath);

        // 3) Are we already “favorited”?
        $isFavorited = Favorite::where([
            ['favoritable_type', 'doujins'],
            ['favoritable_id',   $doujin->id],
        ])->exists();

        // 4) Load all collections (so the modal can list them)
        $allCollections = Collection::orderBy('name')->get();

        // 5) Which collections already contain this doujin?
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

}
