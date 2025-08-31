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
            $this->error('ANILIST_ACCESS_TOKEN is missing in .env');
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
                $origin    = $media['countryOfOrigin'] ?? null;
                $avgScore  = $media['averageScore'] ?? null;
                $mStatus   = $media['status'] ?? null;       // FINISHED / RELEASING...
                $lStatus   = $entry['status'] ?? null;       // CURRENT / COMPLETED...
                $uScore    = isset($entry['score']) ? (int)$entry['score'] : null;

                // studios (main) for anime-type things
                $studios = [];
                if ($type === 'ANIME' && !empty($media['studios']['edges'])) {
                    foreach ($media['studios']['edges'] as $edge) {
                        if (!empty($edge['isMain']) && !empty($edge['node']['name'])) {
                            $studios[] = $edge['node']['name'];
                        }
                    }
                    $studios = array_values(array_unique($studios));
                }

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

                // ----- local type mapping -----
                if ($type === 'ANIME') {
                    $localType = in_array('Hentai', $genres, true) ? 'hentai' : 'anime';
                } else { // MANGA
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
                        'studios'        => $studios ?: null,
                        'origin'         => $origin,
                        'media_status'   => $mStatus,
                        'list_status'    => $lStatus,
                        'user_score'     => $uScore,
                        'avg_score'      => $avgScore,
                        'year'           => $y,
                        'start_date'     => $startDate,
                        'list_created_at'=> $listCreatedAt,
                        'list_updated_at'=> $listUpdatedAt,
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
                createdAt
                updatedAt
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
                  tags { name }
                  studios {
                    edges { isMain node { name } }
                  }
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
