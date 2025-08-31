<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;

class VndbController extends Controller
{
    private array $labelMap = [
        'playing'  => 1,
        'finished' => 2,
        'stalled'  => 3,
        'dropped'  => 4,
        'wishlist' => 5,
    ];

    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.vndb.org/kana/',
            'headers'  => [
                'Authorization' => 'Token ' . env('VNDB_API_TOKEN'),
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * Fetch a user’s VN list from VNDB,
     * and return it in a “frontend‐friendly” shape.
     *
     * Now adds “hasNoSexualContent” = true if any VN tag is exactly “No sexual content.”
     *
     * @param string $username
     * @param string $status
     * @return array
     */
    public function getUserVnList(string $username, string $status = 'finished'): array
    {
        // 1) Look up numeric VNDB user ID
        $res  = $this->client->get('user', ['query' => ['q' => $username]]);
        $json = json_decode((string)$res->getBody(), true);

        $userRec = null;
        foreach ($json as $rec) {
            if (is_array($rec) && isset($rec['id'])) {
                $userRec = $rec;
                break;
            }
        }
        if (!$userRec) {
            return [];  
        }
        $userId = $userRec['id'];

        // 2) Build a big “ulist” query with all necessary fields
        $fields = implode(',', [
            'vn.id',
            'vn.title',
            'vn.description',
            'vn.image.url',
            'vn.tags.name',
            'vn.developers.name',
            'vn.languages',
            'labels.id',
            'labels.label',
            'vote',
            'vn.rating',
            'vn.released',
        ]);

        $allEntries = [];
        $page       = 1;
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
            $data = json_decode((string)$res->getBody(), true);

            $entries    = $data['results'] ?? [];
            $allEntries = array_merge($allEntries, $entries);

            $more = ! empty($data['more']);
            $page++;
        } while ($more);

        // 3) Transform each “raw” VNDB object into our frontend shape:
        return array_map(function($e) {
            $vn = $e['vn'] ?? [];

            $id = $vn['id'] 
                  ?? $e['id'] 
                  ?? null;

            // Flatten title:
            $titleData = $vn['title'] ?? null;
            if (is_array($titleData)) {
                $title = $titleData['english'] 
                       ?? $titleData['romaji'] 
                       ?? 'No Title';
            } else {
                $title = is_string($titleData) 
                       ? $titleData 
                       : 'No Title';
            }

            // Build “hasNoSexualContent” if any tag is exactly “No sexual content”
            $rawTags           = $vn['tags'] ?? [];
            $hasNoSexualContent = false;
            foreach ($rawTags as $t) {
                $tagName = strtolower($t['name'] ?? '');
                if ($tagName === 'no sexual content') {
                    $hasNoSexualContent = true;
                    break;
                }
            }

            // Return everything—note: we no longer rely on minage/etc.
            return [
                'id'                  => $id,
                'title'               => $title,
                'image'               => ['url' => $vn['image']['url'] ?? null],
                'tags'                => array_map(fn($t) => ['name' => $t['name']], $vn['tags'] ?? []),
                'developers'          => array_map(fn($d) => ['name' => $d['name'] ?? null], $vn['developers'] ?? []),
                'languages'           => $vn['languages'] ?? [],
                'status'              => $e['labels'][0]['label'] ?? null,
                'score'               => isset($e['vote']) ? (int)$e['vote'] : 0,
                'average'             => isset($e['vn']['rating']) ? (float)$e['vn']['rating'] : 0,
                'year'                => isset($vn['released']) ? (int)substr($vn['released'], 0, 4) : 0,
                'hasNoSexualContent'  => $hasNoSexualContent,
            ];
        }, $allEntries);
    }

    public function show(string $rawId)
    {
        $id = (int) ltrim($rawId, 'v');
        $vn = $this->fetchVnById($id);
        if (!$vn) {
            abort(404);
        }

        $category = 'visual-novel';

        $isFavorited = Favorite::where([
            ['favoritable_type', $category],
            ['favoritable_id',   $id],
        ])->exists();

        $allCollections = Collection::orderBy('is_system', 'desc')
                                    ->orderBy('name')
                                    ->get();

        $attachedIds = CollectionItem::where('item_type', $category)
                                     ->where('item_id',   $id)
                                     ->pluck('collection_id')
                                     ->toArray();

        return view('media.vndb', [
            'item'           => $vn,
            'category'       => $category,
            'isFavorited'    => $isFavorited,
            'allCollections' => $allCollections,
            'attachedIds'    => $attachedIds,
        ]);
    }

    public function fetchVnById(int $id): ?array
    {
        // 1) Fetch /vn for this single ID
        $fields = implode(',', [
            'id',
            'title',
            'description',
            'image.url',
            'tags.name',
            'developers.name',
            'languages',
            'rating',
            'released',
        ]);

        $res = $this->client->post('vn', [
            'json' => [
                'filters' => ['id', '=', (string)$id],
                'fields'  => $fields,
                'results' => 1,
            ]
        ]);
        $raw = json_decode((string)$res->getBody(), true)['results'][0] ?? null;
        if (!$raw) {
            return null;
        }

        // 2) Find the user’s personal vote (if it exists) by reusing getUserVnList()
        $all  = $this->getUserVnList(env('VNDB_USERNAME'), '');
        $vote = null;
        foreach ($all as $entry) {
            if ((int)$entry['id'] === $id) {
                $vote = $entry['score'];
                break;
            }
        }

        // Flatten & return
        $title = is_array($raw['title'] ?? null)
            ? ($raw['title']['english'] ?? $raw['title']['romaji'] ?? 'No Title')
            : ($raw['title'] ?? 'No Title');

        // Recompute “hasNoSexualContent” for the detail view as well
        $rawTags           = $raw['tags'] ?? [];
        $hasNoSexualContent = false;
        foreach ($rawTags as $t) {
            $tagName = strtolower($t['name'] ?? '');
            if ($tagName === 'no sexual content') {
                $hasNoSexualContent = true;
                break;
            }
        }

        return [
            'id'                 => $raw['id'],
            'title'              => $title,
            'description'        => $raw['description'] ?? '',
            'image'              => ['url' => $raw['image']['url'] ?? null],
            'tags'               => array_map(fn($t) => ['name' => $t['name']], $raw['tags'] ?? []),
            'developers'         => array_map(fn($d) => ['name' => $d['name']], $raw['developers'] ?? []),
            'languages'          => $raw['languages'] ?? [],
            'average'            => isset($raw['rating']) ? (float)$raw['rating'] : 0,
            'released'           => $raw['released'] ?? null,
            'score'              => $vote,
            'hasNoSexualContent' => $hasNoSexualContent,
        ];
    }

    public function apiList()
    {
        $username = env('VNDB_USERNAME');
        $list     = $this->getUserVnList($username, '');
        return response()->json($list);
    }
}
