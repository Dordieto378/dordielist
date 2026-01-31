<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Media;

class ImportAnilist extends Command
{
    protected $signature = 'anilist:import';
    protected $description = 'Import your AniList entries into local media table';

    public function handle(): int
    {
        $token = env('ANILIST_ACCESS_TOKEN');
        if (!$token) {
            $this->error('ANILIST_ACCESS_TOKEN is missing in ..env');
            return self::FAILURE;
        }

        $viewerId = $this->getViewerId($token);
        if (!$viewerId) {
            $this->error('Unable to get AniList Viewer ID.');
            return self::FAILURE;
        }
        $this->info("AniList Viewer ID: {$viewerId}");

        foreach (['ANIME','MANGA'] as $type) {
            $this->info("Fetching {$type}…");
            $entries = $this->fetchList($token, $viewerId, $type);
            $this->info('Received '.count($entries)." {$type} entries.");

            $saved = 0;
            foreach ($entries as $entry) {
                $media = $entry['media'] ?? null;
                if (!$media) continue;

                // ----- derive fields -----
                $sourceId  = $media['id'];
                $genres    = $media['genres'] ?? [];
                $tags      = array_values(array_filter(array_map(fn($t) => $t['name'] ?? null, $media['tags'] ?? [])));
                $genresLower = array_map('mb_strtolower', $genres);
                $origin    = $media['countryOfOrigin'] ?? null;
                $avgScore  = $media['averageScore'] ?? null;
                $mStatus   = $media['status'] ?? null;
                $lStatus   = $entry['status'] ?? null;
                $uScore    = isset($entry['score']) ? (int)$entry['score'] : null;
                $progress  = isset($entry['progress']) ? (int)$entry['progress'] : null;


                $publishers = [];

                if ($type === 'ANIME' && !empty($media['studios']['edges'])) {
                    $mainStudios = [];
                    $producers   = [];
                    foreach ($media['studios']['edges'] as $edge) {
                        $name   = $edge['node']['name'] ?? null;
                        $isMain = !empty($edge['isMain']);
                        $isAnim = $edge['node']['name'] ?? null; // false => producer
                        if (!$name) continue;

                        if ($isMain)           $mainStudios[] = $name;   // keep main animation studios
                        if ($isAnim === false) $producers[]   = $name;   // also keep producers
                    }
                    $publishers = array_merge($mainStudios, $producers);

                } elseif (in_array($type, ['MANGA'], true) && !empty($media['staff']['edges'])) {
                    $allow = [
                        'story', 'art', 'story & art',         // strict authoring roles
                        // uncomment if you ALSO want these typical author roles:
                        // 'author', 'writer', 'original creator',
                    ];
                    $denySubstrings = ['assistant', 'letter', 'touch-up', 'touch up', 'editor', 'translation', 'translator', 'publisher'];

                    foreach ($media['staff']['edges'] as $edge) {
                        $rawRole = strtolower($edge['role'] ?? '');
                        $name    = $edge['node']['name']['full'] ?? null;
                        if (!$name || $rawRole === '') continue;

                        // quick deny if role mentions support work
                        $isDenied = false;
                        foreach ($denySubstrings as $bad) {
                            if (str_contains($rawRole, $bad)) { $isDenied = true; break; }
                        }
                        if ($isDenied) continue;

                        // normalize role: remove parentheticals, unify separators, collapse spaces
                        $role = preg_replace('/\s*\(.*?\)\s*/', ' ', $rawRole); // drop "(manga)" etc
                        $role = str_replace([' and ', ',', '/', '・'], [' & ', ' & ', ' & ', ' & '], $role);
                        $role = trim(preg_replace('/\s+/', ' ', $role));        // collapse spaces

                        if (in_array($role, $allow, true)) {
                            $publishers[] = $name;
                        }
                    }
                }

                $publishers = array_values(array_unique(array_filter($publishers)));

                // start/release date
                $y = $media['startDate']['year']  ?? null;
                $m = $media['startDate']['month'] ?? null;
                $d = $media['startDate']['day']   ?? null;
                $startDate = null;
                if ($y) {
                    // if month/day missing, default to 01
                    $startDate = Carbon::createFromDate($y, $m ?: 1, $d ?: 1)->toDateString();
                }

                // list timestamps (Unix seconds)
                $listCreatedAt = !empty($entry['createdAt']) ? Carbon::createFromTimestamp($entry['createdAt']) : null;
                $listUpdatedAt = !empty($entry['updatedAt']) ? Carbon::createFromTimestamp($entry['updatedAt']) : null;

                // title, cover, banner, description
                $titleEn = $media['title']['english'] ?? null;
                $titleRo = $media['title']['romaji']  ?? null;
                $cover   = $media['coverImage']['extraLarge'] ?? null;
                $banner  = $media['bannerImage'] ?? null;
                $desc    = $media['description'] ?? null;
                $episodesCnt = $media['episodes'] ?? null;
                $chaptersCnt = $media['chapters'] ?? null;
                $volumesCnt  = $media['volumes']  ?? null;

                $episodesToSave = ($type === 'ANIME') ? $episodesCnt : null;
                $chaptersToSave = ($type === 'MANGA') ? $chaptersCnt : null;
                $volumesToSave  = ($type === 'MANGA') ? $volumesCnt  : null;

                // ----- local type mapping -----
                if ($type === 'ANIME') {
                    $localType = in_array('Hentai', $genres, true) ? 'hentai' : 'anime';
                } else {
                    $localType = strtoupper((string)$origin) === 'KR' ? 'manwha' : 'manga';
                }

                // slug: prefer romaji → english, ensure uniqueness with source suffix
                $base = $titleRo ?: $titleEn ?: ('media-'.$sourceId);
                $slug = Str::slug($base.'-al'.$sourceId);

                // ----- upsert -----
                Media::updateOrCreate(
                    ['source' => 'anilist', 'source_id' => $sourceId],
                    [
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
                        'episodes_cnt'   => $episodesToSave,
                        'chapters_cnt'   => $chaptersToSave,
                        'volumes_cnt'    => $volumesToSave,
                        'languages'      => null,
                        'progress'       => $progress,
                    ]
                );

                $saved++;
            }

            $this->info("Saved/updated {$saved} rows for {$type}.");
        }

        return self::SUCCESS;
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
                progress
                createdAt
                updatedAt
                media {
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

        $data = $resp->json('data.MediaListCollection.lists') ?? [];
        $out  = [];
        foreach ($data as $list) {
            foreach ($list['entries'] as $entry) $out[] = $entry;
        }
        return $out;
    }
}
