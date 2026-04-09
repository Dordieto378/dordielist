<?php

namespace App\Services;

use App\Models\Media;
use App\Support\MediaMetadataSyncer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AnilistSyncService
{
    public function __construct(private readonly MediaMetadataSyncer $metadataSyncer)
    {
    }

    public function sync(string $token): array
    {
        $viewerId = $this->getViewerId($token);
        if (!$viewerId) {
            throw new \RuntimeException('Unable to get AniList Viewer ID.');
        }

        $allEntries = [];
        foreach (['ANIME', 'MANGA'] as $type) {
            $allEntries = array_merge($allEntries, $this->fetchList($token, $viewerId, $type));
        }

        $seenIds = [];
        $mediaColumns = array_flip(Schema::getColumnListing('media'));
        $created = 0;
        $updated = 0;
        $deleted = 0;

        DB::beginTransaction();

        try {
            foreach ($allEntries as $entry) {
                $media = $entry['media'] ?? null;
                if (!$media) {
                    continue;
                }

                $sourceId = (int) $media['id'];
                $seenIds[] = $sourceId;

                $genres = array_values(array_filter(array_map(fn ($genre) => trim((string) $genre), $media['genres'] ?? [])));
                $tagRecords = collect($media['tags'] ?? [])
                    ->map(fn (array $tag) => [
                        'name' => trim((string) ($tag['name'] ?? '')),
                        'source_id' => isset($tag['id']) && is_numeric($tag['id']) ? (int) $tag['id'] : null,
                    ])
                    ->filter(fn (array $tag) => $tag['name'] !== '')
                    ->unique(fn (array $tag) => mb_strtolower($tag['name']))
                    ->values()
                    ->all();

                $origin = $media['countryOfOrigin'] ?? null;
                $avgScore = $media['averageScore'] ?? null;
                $mediaStatus = $media['status'] ?? null;
                $listStatus = $entry['status'] ?? null;
                $userScore = isset($entry['score']) ? (int) $entry['score'] : null;
                $progress = isset($entry['progress']) ? (int) $entry['progress'] : null;
                $listStartDate = $this->fuzzyDateToString($entry['startedAt'] ?? null);
                $listEndDate = $this->fuzzyDateToString($entry['completedAt'] ?? null);

                $studioRecords = [];
                if (!empty($media['studios']['edges'])) {
                    $studioRecords = collect($media['studios']['edges'])
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
                }

                $authorRecords = [];
                if (!empty($media['staff']['edges'])) {
                    $allow = ['story', 'art', 'story & art'];
                    $denySubstrings = ['assistant', 'letter', 'touch', 'editor', 'translation', 'translator', 'publisher'];

                    $authorRecords = collect($media['staff']['edges'])
                        ->map(function (array $edge) use ($allow, $denySubstrings) {
                            $rawRole = strtolower((string) ($edge['role'] ?? ''));
                            $name = trim((string) ($edge['node']['name']['full'] ?? ''));

                            if ($name === '' || $rawRole === '') {
                                return null;
                            }

                            foreach ($denySubstrings as $substring) {
                                if (str_contains($rawRole, $substring)) {
                                    return null;
                                }
                            }

                            $role = preg_replace('/\s*\(.*?\)\s*/', ' ', $rawRole);
                            $role = str_replace([' and ', ',', '/', 'Ã£Æ’Â»'], ' & ', $role);
                            $role = trim((string) preg_replace('/\s+/', ' ', $role));

                            if (!in_array($role, $allow, true)) {
                                return null;
                            }

                            return [
                                'name' => $name,
                                'source_id' => isset($edge['node']['id']) && is_numeric($edge['node']['id']) ? (int) $edge['node']['id'] : null,
                            ];
                        })
                        ->filter()
                        ->unique(fn (array $author) => mb_strtolower($author['name']))
                        ->values()
                        ->all();
                }

                $year = $media['startDate']['year'] ?? null;
                $month = $media['startDate']['month'] ?? null;
                $day = $media['startDate']['day'] ?? null;
                $startDate = ($year && $month && $day)
                    ? Carbon::createFromDate($year, $month, $day)->toDateString()
                    : null;

                $titleEn = $media['title']['english'] ?? null;
                $titleRo = $media['title']['romaji'] ?? null;
                $titleNative = $media['title']['native'] ?? null;
                $cover = $media['coverImage']['extraLarge'] ?? null;
                $banner = $media['bannerImage'] ?? null;
                $desc = $media['description'] ?? null;
                $episodesCnt = $media['episodes'] ?? null;
                $chaptersCnt = $media['chapters'] ?? null;
                $volumesCnt = $media['volumes'] ?? null;

                $remoteType = $this->guessRemoteType($media);
                if ($remoteType === 'ANIME') {
                    $localType = in_array('Hentai', $genres, true) ? 'hentai' : 'anime';
                } else {
                    $localType = strtoupper((string) $origin) === 'KR' ? 'manwha' : 'manga';
                }

                $base = $titleRo ?: $titleEn ?: ('media-'.$sourceId);
                $slug = Str::slug($base.'-al'.$sourceId);

                $values = array_intersect_key([
                    'type' => $localType,
                    'title_english' => $titleEn,
                    'title_romaji' => $titleRo,
                    'title_native' => $titleNative,
                    'slug' => $slug,
                    'cover_url' => $cover,
                    'banner_url' => $banner,
                    'description' => $desc,
                    'origin' => $origin,
                    'list_status' => $listStatus,
                    'media_status' => $mediaStatus,
                    'user_score' => $userScore,
                    'avg_score' => $avgScore,
                    'year' => $year,
                    'start_date' => $startDate,
                    'list_start_date' => $listStartDate,
                    'list_end_date' => $listEndDate,
                    'episodes_cnt' => $remoteType === 'ANIME' ? $episodesCnt : null,
                    'chapters_cnt' => $remoteType === 'MANGA' ? $chaptersCnt : null,
                    'volumes_cnt' => $remoteType === 'MANGA' ? $volumesCnt : null,
                    'progress' => $progress,
                ], $mediaColumns);

                $model = Media::updateOrCreate(
                    ['source' => 'anilist', 'source_id' => $sourceId],
                    $values
                );

                $this->metadataSyncer->syncAnilist(
                    $model,
                    $genres,
                    $tagRecords,
                    in_array($localType, ['anime', 'hentai'], true) ? $studioRecords : [],
                    in_array($localType, ['manga', 'manwha'], true) ? $authorRecords : []
                );

                if ($model->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $seenIds = array_values(array_unique($seenIds));
            if ($seenIds !== []) {
                $deleted = Media::where('source', 'anilist')
                    ->whereNotIn('source_id', $seenIds)
                    ->delete();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'viewer_id' => $viewerId,
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'total' => count($seenIds),
        ];
    }

    private function getViewerId(string $token): ?int
    {
        $query = '{ Viewer { id } }';
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ])->post('https://graphql.anilist.co', ['query' => $query]);

        if (!$response->successful()) {
            return null;
        }

        return $response->json('data.Viewer.id');
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
              type
              format
              id
              title { english romaji native }
              coverImage { extraLarge }
              bannerImage
              description
              genres
              startDate { year month day }
              countryOfOrigin
              status
              averageScore
              tags { id name }
              studios { edges { isMain node { id name } } }
              staff { edges { node { id name { full } } role } }
              episodes
              chapters
              volumes
            }
          }
        }
      }
    }
    GQL;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ])->post('https://graphql.anilist.co', [
            'query' => $query,
            'variables' => ['userId' => $userId, 'type' => $type],
        ]);

        if (!$response->successful()) {
            return [];
        }

        $lists = $response->json('data.MediaListCollection.lists') ?? [];
        $entries = [];
        foreach ($lists as $list) {
            foreach ($list['entries'] as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function guessRemoteType(array $media): string
    {
        $type = strtoupper($media['type'] ?? '');
        if (in_array($type, ['ANIME', 'MANGA'], true)) {
            return $type;
        }

        $format = strtoupper($media['format'] ?? '');
        $animeFormats = ['TV', 'TV_SHORT', 'MOVIE', 'SPECIAL', 'OVA', 'ONA', 'MUSIC'];
        if ($format && in_array($format, $animeFormats, true)) {
            return 'ANIME';
        }

        if (array_key_exists('episodes', $media) && $media['episodes'] !== null) {
            return 'ANIME';
        }
        if (array_key_exists('chapters', $media) && $media['chapters'] !== null) {
            return 'MANGA';
        }

        return 'ANIME';
    }

    private function fuzzyDateToString(?array $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $year = isset($value['year']) ? (int) $value['year'] : null;
        $month = isset($value['month']) ? (int) $value['month'] : null;
        $day = isset($value['day']) ? (int) $value['day'] : null;

        if (!$year || !$month || !$day) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
