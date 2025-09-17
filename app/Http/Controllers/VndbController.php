<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use Illuminate\Http\Request;

class VndbController extends Controller
{
    // kept only if you use ?status=… filters
    private array $labelMapDb = [
        'playing'  => 'PLAYING',
        'finished' => 'FINISHED',
        'stalled'  => 'STALLED',
        'dropped'  => 'DROPPED',
        'wishlist' => 'WISHLIST',
    ];

    /**
     * Pull VNs from your DB (media.type='vn'), shaped like your old VNDB array.
     */
    public function getUserVnList(string $username = '', string $status = '')
    {
        $q = Media::query()->where('type', 'vn');

        if ($status && isset($this->labelMapDb[strtolower($status)])) {
            $q->where('list_status', $this->labelMapDb[strtolower($status)]);
        }

        $req = request();

        // language=EN,JP
        if ($langs = $this->csv($req->query('language'))) {
            $q->where(function ($qq) use ($langs) {
                foreach ($langs as $lang) {
                    $qq->orWhereRaw('JSON_CONTAINS(languages, ?)', [json_encode($lang)]);
                }
            });
        }

        // developers param -> stored in studios
        if ($devs = $this->csv($req->query('developers'))) {
            $q->where(function ($qq) use ($devs) {
                foreach ($devs as $d) {
                    $qq->orWhereRaw('JSON_CONTAINS(publisher, ?)', [json_encode($d)]);
                }
            });
        }

        // tags=tag1,tag2
        if ($tags = $this->csv($req->query('tags'))) {
            $q->where(function ($qq) use ($tags) {
                foreach ($tags as $t) {
                    $qq->orWhereRaw('JSON_CONTAINS(tags, ?)', [json_encode($t)]);
                }
            });
        }

        // year=YYYY
        if ($year = $req->query('year')) {
            $q->where('year', (int) $year);
        }

        // ordering
        $titleOrder = $req->query('title_order', 'none');   // az|za
        $scoreOrder = $req->query('score_order', 'none');   // avg_desc|avg_asc|personal_desc|personal_asc
        $yearOrder  = $req->query('year_order',  'none');   // year_desc|year_asc

        if ($titleOrder === 'az') {
            $q->orderByRaw('COALESCE(NULLIF(title_english,""), NULLIF(title_romaji,""), slug) asc');
        } elseif ($titleOrder === 'za') {
            $q->orderByRaw('COALESCE(NULLIF(title_english,""), NULLIF(title_romaji,""), slug) desc');
        }

        if ($scoreOrder === 'avg_desc')       $q->orderBy('avg_score', 'desc');
        elseif ($scoreOrder === 'avg_asc')    $q->orderBy('avg_score', 'asc');
        elseif ($scoreOrder === 'personal_desc') $q->orderBy('user_score', 'desc');
        elseif ($scoreOrder === 'personal_asc')  $q->orderBy('user_score', 'asc');

        if ($yearOrder === 'year_desc')       $q->orderBy('year', 'desc');
        elseif ($yearOrder === 'year_asc')    $q->orderBy('year', 'asc');

        $q->orderBy('start_date', 'desc');

        // Return paginator (so your pagination UI works)
        $paginator = $q->paginate(24)->appends($req->query());

        // Map each Media to the VNDB-ish shape your partial expects
        $media = $paginator->getCollection()->map(function (Media $m) {
            $title = $m->title_english ?: ($m->title_romaji ?: 'No Title');
            $tags  = array_map(fn ($t) => ['name' => $t], $m->tags ?? []);
            $devs  = array_map(fn ($d) => ['name' => $d], $m->publisher ?? []);

            $hasNoSex = false;
            foreach (($m->tags ?? []) as $t) {
                if (mb_strtolower($t) === 'no sexual content') { $hasNoSex = true; break; }
            }

            return [
                'id'        => (int) $m->source_id,
                'title'     => $title,
                'image'     => ['url' => $m->cover_url],
                'tags'      => $tags,
                'developers'=> $devs,
                'languages' => $m->languages ?? [],
                'status'    => $m->list_status ? strtolower($m->list_status) : null,
                'score'     => (int) ($m->user_score ?? 0),
                'average'   => (float) ($m->avg_score ?? 0),
                'year'      => (int) ($m->year ?? 0),
                'hasNoSexualContent' => $hasNoSex,
            ];
        });

        // Replace the paginator collection with our mapped array
        $paginator->setCollection($media);

        // You can return just the array (for JSON), but your category blade
        // expects both $media (array) and $paginatedMedia (paginator).
        // In the controller that calls this, pass both.
        return $paginator;
    }

    private function csv(?string $s): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $s))));
    }

    public function show(string $rawId)
    {
        $id = (int) ltrim($rawId, 'v');

        // Now reads from DB via helper below
        $vn = $this->fetchVnById($id);
        if (!$vn) abort(404);

        $category = 'visual-novel';

        $isFavorited = Favorite::where([
            ['favoritable_type', $category],
            ['favoritable_id',   $id],
        ])->exists();

        $allCollections = Collection::orderBy('is_system', 'desc')
            ->orderBy('name')->get();

        $attachedIds = CollectionItem::where('item_type', $category)
            ->where('item_id', $id)
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
        $m = Media::where('type','vn')->where('source_id', $id)->first();
        if (!$m) return null;

        $title = $m->title_english ?: ($m->title_romaji ?: 'No Title');
        $tags  = array_map(fn ($t) => ['name' => $t], $m->tags ?? []);
        $devs  = array_map(fn ($d) => ['name' => $d], $m->studios ?? []);

        $hasNoSex = false;
        foreach (($m->tags ?? []) as $t) {
            if (mb_strtolower($t) === 'no sexual content') { $hasNoSex = true; break; }
        }

        return [
            'id'                 => (int) $m->source_id,
            'title'              => $title,
            'description'        => $m->description ?? '',
            'image'              => ['url' => $m->cover_url],
            'tags'               => $tags,
            'developers'         => $devs,
            'languages'          => $m->languages ?? [],
            'average'            => (float) ($m->avg_score ?? 0),
            'released'           => $m->start_date ?: null,
            'score'              => (int) ($m->user_score ?? 0),
            'hasNoSexualContent' => $hasNoSex,
            'year'               => (int) ($m->year ?? 0),
        ];
    }

    public function apiList()
    {
        $paginator = $this->getUserVnList('', request('list_filter',''));
        return response()->json($paginator->items());
    }
}
