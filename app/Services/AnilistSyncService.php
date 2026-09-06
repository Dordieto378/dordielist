<?php

namespace App\Services;

use App\Models\AnilistNotification;
use App\Models\Media;
use App\Support\MediaMetadataSyncer;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AnilistSyncService
{
    private const ANILIST_ENDPOINT = 'https://graphql.anilist.co';

    private const RETRY_DELAYS_MS = [1000, 2500, 5000];

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
        $listFailures = [];
        foreach (['ANIME', 'MANGA'] as $type) {
            $result = $this->fetchList($token, $viewerId, $type);
            $allEntries = array_merge($allEntries, $result['entries']);
            $listFailures = array_merge($listFailures, $result['failures']);
        }

        if ($allEntries === [] && $listFailures !== []) {
            throw new \RuntimeException($listFailures[0]['message']);
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
                    $format = strtoupper((string) ($media['format'] ?? ''));
                    $localType = strtoupper((string) $origin) === 'KR' ? 'manhwa' : 'manga';
                    if ($format === 'NOVEL') {
                        $localType = 'light_novel';
                    }
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
                    in_array($localType, ['manga', 'manhwa', 'light_novel'], true) ? $authorRecords : []
                );

                if ($model->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $seenIds = array_values(array_unique($seenIds));
            if ($seenIds !== [] && $listFailures === []) {
                $deleted = Media::where('source', 'anilist')
                    ->whereNotIn('source_id', $seenIds)
                    ->whereNotIn('id', DB::table('media_archives')->select('media_id'))
                    ->whereNotIn('id', DB::table('chapters')->whereNotNull('media_fk')->select('media_fk'))
                    ->delete();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $notificationError = null;
        try {
            $notifications = $this->syncNotifications($token);
        } catch (\Throwable $e) {
            $notifications = ['created' => 0, 'updated' => 0];
            $notificationError = $this->exceptionMessage($e);
        }

        return [
            'viewer_id' => $viewerId,
            'created' => $created,
            'updated' => $updated,
            'deleted' => $deleted,
            'total' => count($seenIds),
            'notifications' => $notifications,
            'list_failures' => $listFailures,
            'notification_error' => $notificationError,
            'partial' => $listFailures !== [] || $notificationError !== null,
        ];
    }

    private function syncNotifications(string $token): array
    {
        $fullImport = !AnilistNotification::query()->exists();
        $page = 1;
        $inserted = 0;
        $updated = 0;

        do {
            $result = $this->fetchNotifications($token, $page, 50);
            $notifications = $result['notifications'];

            foreach ($notifications as $notification) {
                $wasInserted = $this->storeNotification($notification);
                $wasInserted ? $inserted++ : $updated++;
            }

            $page++;
        } while ($fullImport && $result['has_next_page']);

        return [
            'created' => $inserted,
            'updated' => $updated,
        ];
    }

    private function fetchNotifications(string $token, int $page, int $perPage): array
    {
        $query = <<<'GQL'
query ($page: Int, $perPage: Int) {
  Page(page: $page, perPage: $perPage) {
    pageInfo {
      hasNextPage
    }
    notifications(resetNotificationCount: false) {
      __typename
      ... on AiringNotification {
        id
        type
        animeId
        episode
        contexts
        createdAt
        media {
          id
          title { userPreferred romaji english native }
          coverImage { medium }
        }
      }
      ... on FollowingNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
      }
      ... on ActivityMessageNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
      }
      ... on ActivityMentionNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
      }
      ... on ActivityReplyNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
      }
      ... on ActivityReplySubscribedNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
      }
      ... on ActivityLikeNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
      }
      ... on ActivityReplyLikeNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
      }
      ... on ThreadCommentMentionNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
        comment { thread { id title } }
      }
      ... on ThreadCommentReplyNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
        comment { thread { id title } }
      }
      ... on ThreadCommentSubscribedNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
        comment { thread { id title } }
      }
      ... on ThreadCommentLikeNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
        comment { thread { id title } }
      }
      ... on ThreadLikeNotification {
        id
        type
        userId
        context
        createdAt
        user { id name avatar { medium } }
        thread { id title }
      }
      ... on RelatedMediaAdditionNotification {
        id
        type
        mediaId
        context
        createdAt
        media {
          id
          title { userPreferred romaji english native }
          coverImage { medium }
        }
      }
      ... on MediaDataChangeNotification {
        id
        type
        mediaId
        context
        reason
        createdAt
        media {
          id
          title { userPreferred romaji english native }
          coverImage { medium }
        }
      }
      ... on MediaMergeNotification {
        id
        type
        mediaId
        deletedMediaTitles
        context
        reason
        createdAt
        media {
          id
          title { userPreferred romaji english native }
          coverImage { medium }
        }
      }
      ... on MediaDeletionNotification {
        id
        type
        deletedMediaTitle
        context
        reason
        createdAt
      }
    }
  }
}
GQL;

        $response = $this->postAniList($token, $query, ['page' => $page, 'perPage' => $perPage]);

        if (!$response->successful() || $response->json('errors')) {
            throw new \RuntimeException($this->apiErrorMessage($response, 'AniList notification sync failed.'));
        }

        return [
            'notifications' => $response->json('data.Page.notifications') ?? [],
            'has_next_page' => (bool) $response->json('data.Page.pageInfo.hasNextPage'),
        ];
    }

    private function storeNotification(array $notification): bool
    {
        $anilistId = (int) ($notification['id'] ?? 0);
        if ($anilistId <= 0) {
            return false;
        }

        $row = AnilistNotification::firstOrNew(['anilist_id' => $anilistId]);
        $wasNew = !$row->exists;
        $presentation = $this->formatNotification($notification);

        $row->fill([
            'type' => $notification['type'] ?? $notification['__typename'] ?? null,
            'title' => $presentation['title'],
            'body' => $presentation['body'],
            'url' => $presentation['url'],
            'image_url' => $presentation['image_url'],
            'anilist_media_id' => $presentation['anilist_media_id'],
            'payload' => $notification,
            'notified_at' => isset($notification['createdAt'])
                ? Carbon::createFromTimestamp((int) $notification['createdAt'])
                : null,
        ]);

        if ($wasNew) {
            $row->is_read = false;
        }

        $row->save();

        return $wasNew;
    }

    private function formatNotification(array $notification): array
    {
        $media = $notification['media'] ?? null;
        $user = $notification['user'] ?? null;
        $thread = $notification['thread'] ?? ($notification['comment']['thread'] ?? null);
        $mediaTitle = $this->mediaTitle($media);
        $userName = $user['name'] ?? null;
        $type = (string) ($notification['type'] ?? $notification['__typename'] ?? 'Notification');
        $context = trim((string) ($notification['context'] ?? ''));
        $reason = trim((string) ($notification['reason'] ?? ''));

        if (!empty($notification['contexts']) && is_array($notification['contexts'])) {
            $context = trim(implode('', array_map(fn ($value) => (string) $value, $notification['contexts'])));
        }

        $title = $mediaTitle ?: ($userName ?: ($thread['title'] ?? $this->humanizeNotificationType($type)));
        $body = $context !== '' ? $context : $this->humanizeNotificationType($type);

        if (($notification['__typename'] ?? '') === 'AiringNotification' && $mediaTitle) {
            $episode = $notification['episode'] ?? null;
            $body = $episode ? "Episode {$episode} of {$mediaTitle} aired." : "{$mediaTitle} aired.";
        }

        if ($reason !== '') {
            $body = trim($body.' '.$reason);
        }

        if (!empty($notification['deletedMediaTitle'])) {
            $title = (string) $notification['deletedMediaTitle'];
        }

        return [
            'title' => $title,
            'body' => $body,
            'url' => $this->notificationUrl($notification, $media),
            'image_url' => $media['coverImage']['medium'] ?? $user['avatar']['medium'] ?? null,
            'anilist_media_id' => $media['id'] ?? $notification['mediaId'] ?? $notification['animeId'] ?? null,
        ];
    }

    private function mediaTitle(?array $media): ?string
    {
        if (!$media) {
            return null;
        }

        return $media['title']['userPreferred']
            ?? $media['title']['romaji']
            ?? $media['title']['english']
            ?? $media['title']['native']
            ?? null;
    }

    private function notificationUrl(array $notification, ?array $media): ?string
    {
        $mediaId = $media['id'] ?? $notification['mediaId'] ?? $notification['animeId'] ?? null;
        if ($mediaId) {
            $localMedia = Media::where('source', 'anilist')->where('source_id', $mediaId)->first(['id']);
            return $localMedia ? '/media/'.$localMedia->id : 'https://anilist.co/anime/'.$mediaId;
        }

        if (!empty($notification['user']['name'])) {
            return 'https://anilist.co/user/'.$notification['user']['name'];
        }

        if (!empty($notification['thread']['id'])) {
            return 'https://anilist.co/forum/thread/'.$notification['thread']['id'];
        }

        if (!empty($notification['comment']['thread']['id'])) {
            return 'https://anilist.co/forum/thread/'.$notification['comment']['thread']['id'];
        }

        return null;
    }

    private function humanizeNotificationType(string $type): string
    {
        $type = preg_replace('/Notification$/', '', $type);
        $type = preg_replace('/(?<!^)[A-Z]/', ' $0', (string) $type);

        return trim((string) $type) ?: 'Notification';
    }

    private function getViewerId(string $token): ?int
    {
        $query = '{ Viewer { id } }';
        $response = $this->postAniList($token, $query, [], 30);

        if (!$response->successful()) {
            throw new \RuntimeException($this->apiErrorMessage($response, 'Unable to get AniList Viewer ID.'));
        }

        return $response->json('data.Viewer.id');
    }

    private function fetchList(string $token, int $userId, string $type): array
    {
        if ($type === 'ANIME') {
            $query = <<<'GQL'
    query ($userId:Int, $type:MediaType, $status:MediaListStatus) {
      MediaListCollection(userId:$userId, type:$type, status:$status) {
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
              episodes
              chapters
              volumes
            }
          }
        }
      }
    }
    GQL;
        } else {
            $query = <<<'GQL'
    query ($userId:Int, $type:MediaType, $status:MediaListStatus) {
      MediaListCollection(userId:$userId, type:$type, status:$status) {
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
        }

        $entries = [];
        $failures = [];
        $statuses = ['CURRENT', 'PLANNING', 'COMPLETED', 'PAUSED', 'DROPPED', 'REPEATING'];

        foreach ($statuses as $status) {
            try {
                $response = $this->postAniList($token, $query, [
                    'userId' => $userId,
                    'type' => $type,
                    'status' => $status,
                ]);

                $apiError = $response->json('errors.0.message');
                if (!$response->successful() || $apiError) {
                    throw new \RuntimeException($apiError ?: $this->apiErrorMessage($response, "AniList {$type} {$status} list sync failed."));
                }

                $lists = $response->json('data.MediaListCollection.lists') ?? [];
                foreach ($lists as $list) {
                    foreach ($list['entries'] as $entry) {
                        $entries[] = $entry;
                    }
                }
            } catch (\Throwable $e) {
                $failures[] = [
                    'type' => $type,
                    'status' => $status,
                    'message' => $this->exceptionMessage($e),
                ];
            }
        }

        return [
            'entries' => $entries,
            'failures' => $failures,
        ];
    }

    private function postAniList(string $token, string $query, array $variables = [], int $timeout = 120): Response
    {
        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        return Http::acceptJson()
            ->withToken($token)
            ->timeout($timeout)
            ->connectTimeout(15)
            ->retry(self::RETRY_DELAYS_MS, 0, null, false)
            ->post(self::ANILIST_ENDPOINT, $payload);
    }

    private function apiErrorMessage(Response $response, string $fallback): string
    {
        $apiError = trim((string) $response->json('errors.0.message'));
        if ($apiError !== '') {
            return $apiError;
        }

        $body = trim(strip_tags((string) $response->body()));
        $body = preg_replace('/\s+/', ' ', $body);
        $body = Str::limit((string) $body, 180);

        return $body !== ''
            ? "{$fallback} HTTP {$response->status()}: {$body}"
            : "{$fallback} HTTP {$response->status()}.";
    }

    private function exceptionMessage(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? $message : 'Unknown error.';
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
