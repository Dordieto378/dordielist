<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Media;
use App\Models\User;
use App\Support\MediaMetadataSyncer;
use App\Support\VndbPublisherData;
use GuzzleHttp\Client;

class ImportVndb extends Command
{
    protected $signature = 'vndb:import {--status=}';
    protected $description = 'Import your VNDB list into the local media table';

    private array $labelMap = [
        'playing'  => 1,
        'finished' => 2,
        'stalled'  => 3,
        'dropped'  => 4,
        'wishlist' => 5,
    ];

    private Client $client;

    public function __construct(private readonly MediaMetadataSyncer $metadataSyncer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $credentials = $this->resolveCredentials();
        if (!$credentials) {
            $this->error('No VNDB API token and username found. Save them in API settings or set VNDB_API_TOKEN and VNDB_USERNAME.');
            return self::FAILURE;
        }

        [$token, $username] = $credentials;
        $this->client = new Client([
            'base_uri' => 'https://api.vndb.org/kana/',
            'headers'  => [
                'Authorization' => 'Token '.$token,
                'Accept'        => 'application/json',
            ],
            'timeout'  => 30,
        ]);

        $userId = $this->lookupUserId($username);
        if (!$userId) {
            $this->error("Cannot find VNDB user: {$username}");
            return self::FAILURE;
        }
        $this->info("VNDB User ID: {$userId}");

        $status = strtolower((string)$this->option('status'));
        $rows   = $this->fetchUlist($userId, $status);

        $this->info('Received ' . count($rows) . ' entries.');
        if (!count($rows)) return self::SUCCESS;

        $vndbIds = [];
        foreach ($rows as $entry) {
            $vidRaw = $entry['vn']['id'] ?? ($entry['id'] ?? null);
            $vidNum = is_string($vidRaw) ? (int) ltrim($vidRaw, 'vV') : (int) $vidRaw;
            if ($vidNum > 0) {
                $vndbIds[] = $vidNum;
            }
        }
        $publishersByVn = $this->fetchReleasePublishers(array_values(array_unique($vndbIds)));

        $inserted = 0;
        $updated  = 0;

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        $now = now();

        foreach ($rows as $e) {
            $vn = $e['vn'] ?? [];

            $vidRaw = $vn['id'] ?? ($e['id'] ?? null);
            if (!$vidRaw) { $bar->advance(); continue; }

            $vidNum = is_string($vidRaw) ? (int) ltrim($vidRaw, 'vV') : (int) $vidRaw;
            if ($vidNum <= 0) { $bar->advance(); continue; }

            // title
            $titleEn = null;
            $titleRo = null;
            $titleNative = null;
            $mainTitle = $vn['title'] ?? null;

            if (!empty($vn['titles']) && is_array($vn['titles'])) {
                foreach ($vn['titles'] as $title) {
                    if (!isset($title['lang'], $title['title'])) continue;
                    if ($title['lang'] === 'en') $titleEn = $title['title'];
                    if ($title['lang'] === 'ja-latn') $titleRo = $title['title'];
                }

                $titleNative = $this->pickNativeTitle($vn['titles']);
            }

            if (!$titleRo && $mainTitle) {
                $titleRo = $mainTitle;
            }
            if (!$titleNative && is_string($mainTitle) && $this->containsNonLatin($mainTitle)) {
                $titleNative = $mainTitle;
            }
            $titleForSlug = $titleEn ?: $titleRo ?: 'vn';

            // fields
            $cover  = $vn['image']['url'] ?? null;
            $desc   = $vn['description'] ?? null;

            $tags = array_values(array_filter(
                array_map(fn($t) => $t['name'] ?? null, $vn['tags'] ?? []),
                fn($x) => (string)$x !== ''
            ));

            $devs = array_values(array_filter(
                array_map(fn($d) => $d['name'] ?? null, $vn['developers'] ?? []),
                fn($x) => (string)$x !== ''
            ));

            $publishers = $publishersByVn === null ? null : ($publishersByVn[$vidNum] ?? []);

            $langs = $vn['languages'] ?? [];

            $avg   = isset($vn['rating']) ? (float)$vn['rating'] : null;
            $vote  = isset($e['vote'])    ? (int)$e['vote']     : null;
            $label = $e['labels'][0]['label'] ?? null;  // playing / finished / ...

            $year = null;
            $released = $vn['released'] ?? null;        // "YYYY-MM-DD" or "YYYY"
            if (is_string($released) && strlen($released) >= 4 && ctype_digit(substr($released, 0, 4))) {
                $year = (int) substr($released, 0, 4);
            }

            $slug = Str::slug($titleForSlug . '-v' . $vidNum);

            // prepare column set; include only columns that exist to avoid SQL errors
            $vals = [
                'type'          => 'vn',
                'title_english' => $titleEn,
                'title_romaji'  => $titleRo,
                'title_native'  => $titleNative,
                'slug'          => $slug,
                'cover_url'     => $cover,
                'banner_url'    => null,
                'description'   => $desc,
                'origin'        => null,
                'episodes_cnt'  => null,
                'chapters_cnt'  => null,
                'volumes_cnt'   => null,
            ];

            // optional columns (only set if present in your schema)
            if (Schema::hasColumn('media', 'avg_score'))      $vals['avg_score']   = $avg;
            if (Schema::hasColumn('media', 'user_score'))     $vals['user_score']  = $vote;
            if (Schema::hasColumn('media', 'list_status'))    $vals['list_status'] = $label ? strtoupper($label) : null;
            if (Schema::hasColumn('media', 'year'))           $vals['year']        = $year;
            if (Schema::hasColumn('media', 'release_date'))   $vals['release_date']= $released;

            // does it exist?
            $exists = DB::table('media')
                ->where('source', 'vndb')
                ->where('source_id', $vidNum)
                ->exists();

            if ($exists) {
                DB::table('media')
                    ->where('source', 'vndb')
                    ->where('source_id', $vidNum)
                    ->update($vals);
                $updated++;
            } else {
                DB::table('media')->insert(array_merge([
                    'source'     => 'vndb',
                    'source_id'  => $vidNum,
                ], $vals));
                $inserted++;
            }

            $model = Media::where('source', 'vndb')
                ->where('source_id', $vidNum)
                ->first();

            if ($model) {
                $this->metadataSyncer->syncVn($model, $tags, $langs, $devs, $publishers);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("VNDB import complete. Inserted: {$inserted}, Updated: {$updated}");

        return self::SUCCESS;
    }

    private function resolveCredentials(): ?array
    {
        $adminUser = User::with('role')
            ->get()
            ->first(function (User $user) {
                return optional($user->role)->role === 'Admin'
                    && filled($user->vndb_api_token)
                    && filled($user->vndb_username);
            });

        if ($adminUser) {
            return [$adminUser->vndb_api_token, $adminUser->vndb_username];
        }

        $userWithCredentials = User::query()
            ->get()
            ->first(fn (User $user) => filled($user->vndb_api_token) && filled($user->vndb_username));

        if ($userWithCredentials) {
            return [$userWithCredentials->vndb_api_token, $userWithCredentials->vndb_username];
        }

        $token = env('VNDB_API_TOKEN');
        $username = env('VNDB_USERNAME');

        return filled($token) && filled($username) ? [$token, $username] : null;
    }

    private function lookupUserId(string $username): ?string
    {
        $res  = $this->client->get('user', ['query' => ['q' => $username]]);
        $data = json_decode((string)$res->getBody(), true);
        foreach ($data as $row) {
            if (!empty($row['id'])) return $row['id']; // e.g. "u220281"
        }
        return null;
    }

    private function fetchUlist(string $userId, string $status = ''): array
    {
        $fields = implode(',', [
            'vn.id',
            'vn.title',
            'vn.titles.lang',
            'vn.titles.title',
            'vn.description',
            'vn.image.url',
            'vn.tags.name',
            'vn.developers.name',
            'vn.languages',
            'labels.label',
            'vote',
            'vn.rating',
            'vn.released',
        ]);

        $page = 1;
        $all  = [];

        do {
            $payload = [
                'user'    => $userId,
                'fields'  => $fields,
                'results' => 100,
                'page'    => $page,
            ];
            if (isset($this->labelMap[$status])) {
                $payload['filters'] = ['label', '=', $this->labelMap[$status]];
            }

            $res  = $this->client->post('ulist', ['json' => $payload]);
            $json = json_decode((string)$res->getBody(), true);

            $batch = $json['results'] ?? [];
            $all   = array_merge($all, $batch);
            $more  = !empty($json['more']);
            $page++;
        } while ($more);

        return $all;
    }

    private function fetchReleasePublishers(array $vnIds): ?array
    {
        $vnIds = array_values(array_unique(array_filter(
            array_map(fn ($id) => (int) $id, $vnIds),
            fn ($id) => $id > 0
        )));

        if (empty($vnIds)) {
            return [];
        }

        $grouped = [];

        foreach (array_chunk($vnIds, 50) as $chunk) {
            $page = 1;

            do {
                try {
                    $res = $this->client->post('release', [
                        'json' => [
                            'filters' => VndbPublisherData::releaseFilter($chunk),
                            'fields' => VndbPublisherData::releaseFields(),
                            'results' => 100,
                            'page' => $page,
                        ],
                    ]);
                } catch (\Throwable) {
                    return null;
                }

                $json = json_decode((string) $res->getBody(), true);
                if (!is_array($json)) {
                    return null;
                }

                $grouped = VndbPublisherData::mergeGrouped(
                    $grouped,
                    VndbPublisherData::groupByVn($json['results'] ?? [])
                );

                $more = !empty($json['more']);
                $page++;
            } while ($more);
        }

        return $grouped;
    }

    private function pickNativeTitle(array $titles): ?string
    {
        foreach ($titles as $title) {
            $value = trim((string) ($title['title'] ?? ''));
            if ($value !== '' && $this->containsNonLatin($value)) {
                return $value;
            }
        }

        return null;
    }

    private function containsNonLatin(string $value): bool
    {
        return preg_match('/[^\p{Latin}\p{Common}\p{Inherited}\p{Nd}\p{Zs}\p{P}\p{S}]/u', $value) === 1;
    }
}
