<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Media;
use App\Support\MediaMetadataSyncer;
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

        $this->client = new Client([
            'base_uri' => 'https://api.vndb.org/kana/',
            'headers'  => [
                'Authorization' => 'Token ' . env('VNDB_API_TOKEN'),
                'Accept'        => 'application/json',
            ],
            'timeout'  => 30,
        ]);
    }

    public function handle(): int
    {
        // sanity: ensure we're on the right DB
        $this->line('DB: ' . config('database.connections.' . config('database.default') . '.database'));

        $username = env('VNDB_USERNAME');
        if (!$username) {
            $this->error('VNDB_USERNAME is not set in ..env');
            return self::FAILURE;
        }

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
            $titleRaw = $vn['title'] ?? null;
            if (is_array($titleRaw)) {
                $titleEn = $titleRaw['english'] ?? null;
                $titleRo = $titleRaw['romaji']  ?? null;
            } else {
                $titleEn = $titleRaw;
                $titleRo = null;
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
                $this->metadataSyncer->syncVn($model, $tags, $langs, $devs);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("VNDB import complete. Inserted: {$inserted}, Updated: {$updated}");

        return self::SUCCESS;
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
}
