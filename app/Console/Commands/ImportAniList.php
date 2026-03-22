<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Media;
use App\Support\MediaMetadataSyncer;

class ImportAnilist extends Command
{
    protected $signature = 'anilist:import';
    protected $description = 'Import your AniList entries into local media table';

    public function __construct(private readonly MediaMetadataSyncer $metadataSyncer)
    {
        parent::__construct();
    }

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
        $mediaColumns = array_flip(Schema::getColumnListing('media'));
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
                $tagRecords = collect($media['tags'] ?? [])
                    ->map(fn (array $tag) => [
                        'name' => trim((string) ($tag['name'] ?? '')),
                        'source_id' => isset($tag['id']) && is_numeric($tag['id']) ? (int) $tag['id'] : null,
                    ])
                    ->filter(fn (array $tag) => $tag['name'] !== '')
                    ->unique(fn (array $tag) => mb_strtolower($tag['name']))
                    ->values()
                    ->all();
                $tags      = $this->metadataSyncer->normalizedNames($tagRecords) ?? [];
                $origin    = $media['countryOfOrigin'] ?? null;
                $avgScore  = $media['averageScore'] ?? null;
                $mStatus   = $media['status'] ?? null;
                $lStatus   = $entry['status'] ?? null;
                $uScore    = isset($entry['score']) ? (int)$entry['score'] : null;
                $progress  = isset($entry['progress']) ? (int)$entry['progress'] : null;
                $listStartDate = $this->fuzzyDateToString($entry['startedAt'] ?? null);
                $listEndDate = $this->fuzzyDateToString($entry['completedAt'] ?? null);


                $studios = [];
                $authors = [];

                if ($type === 'ANIME' && !empty($media['studios']['edges'])) {
                    $studios = collect($media['studios']['edges'])
                        ->map(function (array $edge) {
                            $node = $edge['node'] ?? [];
                            $name = trim((string) ($node['name'] ?? ''));

                            return [
                                'name' => $name,
                                'source_id' => isset($node['id']) && is_numeric($node['id']) ? (int) $node['id'] : null,
                                'is_main' => !empty($edge['isMain']),
                            ];
                        })
                        ->filter(fn (array $studio) => $studio['name'] !== '' && $studio['is_main'])
                        ->unique(fn (array $studio) => mb_strtolower($studio['name']))
                        ->map(fn (array $studio) => [
                            'name' => $studio['name'],
                            'source_id' => $studio['source_id'],
                        ])
                        ->values()
                        ->all();

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
                            $authors[] = [
                                'name' => trim((string) $name),
                                'source_id' => isset($edge['node']['id']) && is_numeric($edge['node']['id']) ? (int) $edge['node']['id'] : null,
                            ];
                        }
                    }
                }

                $authors = collect($authors)
                    ->filter(fn (array $author) => $author['name'] !== '')
                    ->unique(fn (array $author) => mb_strtolower($author['name']))
                    ->values()
                    ->all();
                $publishers = $this->metadataSyncer->normalizedNames($type === 'ANIME' ? $studios : $authors) ?? [];

                // start/release date
                $y = $media['startDate']['year']  ?? null;
                $m = $media['startDate']['month'] ?? null;
                $d = $media['startDate']['day']   ?? null;
                $startDate = ($y && $m && $d)
                    ? Carbon::createFromDate($y, $m, $d)->toDateString()
                    : null;

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
                $values = array_intersect_key([
                    'type'           => $localType,
                    'title_english'  => $titleEn,
                    'title_romaji'   => $titleRo,
                    'slug'           => $slug,
                    'cover_url'      => $cover,
                    'banner_url'     => $banner,
                    'description'    => $desc,
                    'origin'         => $origin,
                    'list_status'    => $lStatus,
                    'media_status'   => $mStatus,
                    'user_score'     => $uScore,
                    'avg_score'      => $avgScore,
                    'year'           => $y,
                    'start_date'     => $startDate,
                    'list_start_date'=> $listStartDate,
                    'list_end_date'  => $listEndDate,
                    'episodes_cnt'   => $episodesToSave,
                    'chapters_cnt'   => $chaptersToSave,
                    'volumes_cnt'    => $volumesToSave,
                    'progress'       => $progress,
                ], $mediaColumns);

                $model = Media::updateOrCreate(
                    ['source' => 'anilist', 'source_id' => $sourceId],
                    $values
                );

                $this->metadataSyncer->syncAnilist(
                    $model,
                    $genres,
                    $tagRecords,
                    $type === 'ANIME' ? $studios : [],
                    $type === 'MANGA' ? $authors : []
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
                startedAt { year month day }
                completedAt { year month day }
                media {
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
                  tags { id name }
                  studios {
                       edges {
                           isMain
                           node {
                               id
                               name
                           }
                       }
                   }
                   staff {
                       edges {
                           node {
                               id
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

    private function fuzzyDateToString(?array $value): ?string
    {
        if (!is_array($value)) return null;

        $year = isset($value['year']) ? (int) $value['year'] : null;
        $month = isset($value['month']) ? (int) $value['month'] : null;
        $day = isset($value['day']) ? (int) $value['day'] : null;

        if (!$year || !$month || !$day) return null;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
