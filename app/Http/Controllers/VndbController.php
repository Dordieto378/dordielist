<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class VndbController extends Controller
{
    private array $labelMapDb = [
        'playing'  => 'PLAYING',
        'finished' => 'FINISHED',
        'stalled'  => 'STALLED',
        'dropped'  => 'DROPPED',
        'wishlist' => 'WISHLIST',
    ];

    public function getUserVnList(string $username = '', string $status = '')
    {
        $q = Media::query()->where('type', 'vn');

        if ($status && isset($this->labelMapDb[strtolower($status)])) {
            $q->where('list_status', $this->labelMapDb[strtolower($status)]);
        }

        $req = request();

        if ($langs = $this->csv($req->query('language'))) {
            $q->where(function ($qq) use ($langs) {
                foreach ($langs as $lang) {
                    $qq->orWhereRaw('JSON_CONTAINS(languages, ?)', [json_encode($lang)]);
                }
            });
        }

        if ($devs = $this->csv($req->query('developers'))) {
            $q->where(function ($qq) use ($devs) {
                foreach ($devs as $d) {
                    $qq->orWhereRaw('JSON_CONTAINS(publisher, ?)', [json_encode($d)]);
                }
            });
        }

        if ($tags = $this->csv($req->query('tags'))) {
            $q->where(function ($qq) use ($tags) {
                foreach ($tags as $t) {
                    $qq->orWhereRaw('JSON_CONTAINS(tags, ?)', [json_encode($t)]);
                }
            });
        }

        if ($year = $req->query('year')) {
            $q->where('year', (int) $year);
        }

        $titleOrder = $req->query('title_order', 'none');
        $scoreOrder = $req->query('score_order', 'none');
        $yearOrder  = $req->query('year_order',  'none');

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

        $paginator = $q->paginate(24)->appends($req->query());

        $media = $paginator->getCollection()->map(function (Media $m) {
            $title = $m->title_english ?: ($m->title_romaji ?: 'No Title');
            $tags  = array_map(fn ($t) => ['name' => $t], $m->tags ?? []);
            $devs  = array_map(fn ($d) => ['name' => $d], $m->publisher ?? []);

            $hasNoSex = false;
            foreach (($m->tags ?? []) as $t) {
                if (mb_strtolower($t) === 'no sexual content') { $hasNoSex = true; break; }
            }

            return [
                'id'        => (int) $m->id,
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

        $paginator->setCollection($media);

        return $paginator;
    }

    private function csv(?string $s): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $s))));
    }

    public function show(string $rawId)
    {
        $id = (int) ltrim($rawId, 'v');

        $vn = $this->fetchVnById($id);
        if (!$vn) abort(404);

        $mediaRow      = Media::find($id);
        $launchRelExe  = $mediaRow?->launch_rel_exe;
        $hasLauncher   = !empty($launchRelExe);

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
            'hasLauncher'     => $hasLauncher,
            'launchRelExe'    => $launchRelExe,
        ]);
    }

    public function fetchVnById(int $id): ?array
    {
        $m = Media::where('type','vn')->where('id', $id)->first();
        if (!$m) return null;

        $title = $m->title_english ?: ($m->title_romaji ?: 'No Title');
        $tags  = array_map(fn ($t) => ['name' => $t], $m->tags ?? []);
        $devs  = array_map(fn ($d) => ['name' => $d], $m->publisher ?? []);
        $descHtml = $this->renderVnDescription($m->description ?? '');

        $hasNoSex = false;
        foreach (($m->tags ?? []) as $t) {
            if (mb_strtolower($t) === 'no sexual content') { $hasNoSex = true; break; }
        }
        $mediaModel = Media::find($id);

        return [
            'id'                 => (int) $m->id,
            'title'              => $title,
            'description_html'   => $descHtml,
            'image'              => ['url' => $m->cover_url],
            'tags'               => $tags,
            'developers'         => $devs,
            'languages'          => $m->languages ?? [],
            'average'            => (float) ($m->avg_score ?? 0),
            'released'           => $m->year ? sprintf('%04d', (int)$m->year) : null,
            'score'              => (int) ($m->user_score ?? 0),
            'hasNoSexualContent' => $hasNoSex,
            'year'               => (int) ($m->year ?? 0),
            'media'              => $mediaModel,
        ];
    }

    public function apiList()
    {
        $paginator = $this->getUserVnList('', request('list_filter',''));
        return response()->json($paginator->items());
    }

    private function renderVnDescription(string $raw, string $linkClass = 'text-blue-600 hover:underline cursor-pointer'): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        $tokens = [];
        $cls = ' class="'.htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8').'"';

        $text = preg_replace_callback(
            '/\[url=(https?:\/\/[^\]\s]+)\](.*?)\[\/url\]/i',
            function ($m) use (&$tokens, $cls) {
                $url   = filter_var($m[1], FILTER_SANITIZE_URL);
                if (!preg_match('#^https?://#i', $url)) return $m[0];
                $label = $m[2];
                $tok   = '__A'.count($tokens).'__';
                $tokens[$tok] =
                    '<a'.$cls.' href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener noreferrer">'.
                    htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a>';
                return $tok;
            }, $text
        );

        $text = preg_replace_callback(
            '/\[url\](https?:\/\/.*?)\[\/url\]/i',
            function ($m) use (&$tokens, $cls) {
                $url = trim($m[1]);
                $safe = filter_var($url, FILTER_SANITIZE_URL);
                if (!preg_match('#^https?://#i', $safe)) return $m[0];
                $tok = '__A'.count($tokens).'__';
                $tokens[$tok] =
                    '<a'.$cls.' href="'.htmlspecialchars($safe, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener noreferrer">'.
                    htmlspecialchars($safe, ENT_QUOTES, 'UTF-8').'</a>';
                return $tok;
            }, $text
        );

        $text = preg_replace('/\[(spoiler)(?:=[^\]]*)?\]/i', '__SPOILER_OPEN__', $text);
        $text = preg_replace('/\[\/spoiler\]/i', '__SPOILER_CLOSE__', $text);

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = strtr($escaped, $tokens);

        $html = str_replace(
            ['__SPOILER_OPEN__', '__SPOILER_CLOSE__'],
            ['<span class="spoiler" tabindex="0" role="button" aria-expanded="false">', '</span>'],
            $html
        );

        return nl2br($html, false);
    }

    public function syncFromVndb(Request $request)
    {
        $token = Auth::user()?->vndb_api_token;
        $username = Auth::user()?->vndb_username;

        if (!$token || !$username) {
            return back()->with('error', 'Add your VNDB API token and username in account settings first.');
        }

        $userId = $this->vndbLookupUserId($token, $username);
        if (!$userId) {
            return back()->with('error', "Cannot find VNDB user: {$username}");
        }

        $rows = $this->vndbFetchUlist($token, $userId);
        if (!count($rows)) {
            return back()->with('error', 'VNDB returned 0 entries.');
        }

        $created = 0;
        $updated = 0;
        $seen    = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $e) {
                $vn = $e['vn'] ?? [];

                $vidRaw = $vn['id'] ?? ($e['id'] ?? null);
                if (!$vidRaw) continue;
                $vid = is_string($vidRaw) ? (int) ltrim($vidRaw, 'vV') : (int) $vidRaw;
                if ($vid <= 0) continue;
                $seen[] = $vid;

                $titleEn = null;
                $titleRo = null;

                $mainTitle = $vn['title'] ?? null;

                if (!empty($vn['titles']) && is_array($vn['titles'])) {
                    foreach ($vn['titles'] as $t) {
                        if (!isset($t['lang'], $t['title'])) continue;
                        if ($t['lang'] === 'en') {
                            $titleEn = $t['title'];
                        }
                        if ($t['lang'] === 'ja-latn') {
                            $titleRo = $t['title'];
                        }
                    }
                }

                if (!$titleEn && $mainTitle && preg_match('/[A-Za-z]/', $mainTitle)) {
                    $titleEn = $mainTitle;
                }

                if (!$titleRo && $mainTitle) {
                    $titleRo = $mainTitle;
                }

                $titleForSlug = $titleEn ?: $titleRo ?: 'vn';

                $cover = $vn['image']['url'] ?? null;
                $desc  = $vn['description']   ?? null;

                $tags = array_values(array_filter(
                    array_map(fn($t) => $t['name'] ?? null, $vn['tags'] ?? []),
                    fn($x) => (string) $x !== ''
                ));

                $devs = array_values(array_filter(
                    array_map(fn($d) => $d['name'] ?? null, $vn['developers'] ?? []),
                    fn($x) => (string) $x !== ''
                ));

                $langs = $vn['languages'] ?? [];

                $avg  = isset($vn['rating']) ? (float) $vn['rating'] : null;
                $vote = isset($e['vote'])    ? (int) $e['vote']      : null;

                $rawLabel  = $e['labels'][0]['label'] ?? null;
                $labelMapN = [1=>'PLAYING', 2=>'FINISHED', 3=>'STALLED', 4=>'DROPPED', 5=>'WISHLIST'];
                $listStatus = is_numeric($rawLabel)
                    ? ($labelMapN[(int)$rawLabel] ?? null)
                    : ($rawLabel ? strtoupper($rawLabel) : null);

                $released = $vn['released'] ?? null;
                $year = (is_string($released) && strlen($released) >= 4 && ctype_digit(substr($released, 0, 4)))
                    ? (int) substr($released, 0, 4)
                    : null;

                $slugBase = Str::slug($titleForSlug . '-v' . $vid);

                $values = ['type' => 'vn'];
                if (\Schema::hasColumn('media', 'title_english'))  $values['title_english'] = $titleEn;
                if (\Schema::hasColumn('media', 'title_romaji'))   $values['title_romaji']  = $titleRo;
                if (\Schema::hasColumn('media', 'slug'))           $values['slug']          = $slugBase;
                if (\Schema::hasColumn('media', 'cover_url'))      $values['cover_url']     = $cover;
                if (\Schema::hasColumn('media', 'banner_url'))     $values['banner_url']    = null;
                if (\Schema::hasColumn('media', 'description'))    $values['description']   = $desc;
                if (\Schema::hasColumn('media', 'genres'))         $values['genres']        = null;
                if (\Schema::hasColumn('media', 'tags'))           $values['tags']          = $tags ?: null;
                if (\Schema::hasColumn('media', 'origin'))         $values['origin']        = null;
                if (\Schema::hasColumn('media', 'episodes_cnt'))   $values['episodes_cnt']  = null;
                if (\Schema::hasColumn('media', 'chapters_cnt'))   $values['chapters_cnt']  = null;
                if (\Schema::hasColumn('media', 'volumes_cnt'))    $values['volumes_cnt']   = null;
                if (\Schema::hasColumn('media', 'avg_score'))      $values['avg_score']     = $avg;
                if (\Schema::hasColumn('media', 'user_score'))     $values['user_score']    = $vote;
                if (\Schema::hasColumn('media', 'list_status'))    $values['list_status']   = $listStatus;
                if (\Schema::hasColumn('media', 'languages'))      $values['languages']     = $langs ?: null;
                if (\Schema::hasColumn('media', 'publisher'))      $values['publisher']     = $devs ?: null;
                if (\Schema::hasColumn('media', 'year'))           $values['year']          = $year;
                if (\Schema::hasColumn('media', 'release_date'))   $values['release_date']  = $released;

                $model = Media::where('source', 'vndb')->where('source_id', $vid)->first();
                if (!$model) {
                    $model = Media::where('type', 'vn')->where('id', $vid)->first() ?: new Media();
                }

                if (\Schema::hasColumn('media', 'source'))    $model->source    = 'vndb';
                if (\Schema::hasColumn('media', 'source_id')) $model->source_id = $vid;

                if (array_key_exists('slug', $values)) {
                    $try = $values['slug'];
                    $i = 1;
                    while (
                    Media::where('slug', $try)
                        ->when($model->exists, fn($q) => $q->where('id', '<>', $model->id))
                        ->exists()
                    ) {
                        $try = $slugBase . '-' . $i++;
                    }
                    $values['slug'] = $try;
                }

                $model->forceFill($values);
                $wasNew = !$model->exists;
                $model->save();

                if ($wasNew || $model->wasRecentlyCreated) $created++; else $updated++;
            }

            $seen = array_values(array_unique($seen));
            if (!empty($seen)) {
                Media::where('source', 'vndb')->whereNotIn('source_id', $seen)->delete();
                Media::whereNull('source')->where('type', 'vn')->whereNotIn('id', $seen)->delete();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'VNDB sync failed: '.$e->getMessage());
        }

        return back()->with('status', "VNDB sync done. created={$created}, updated={$updated}.");
    }

    private function vndbClient(string $token)
    {
        return Http::baseUrl('https://api.vndb.org/kana/')
            ->withHeaders([
                'Authorization' => 'Token '.$token,
                'Accept'        => 'application/json',
            ]);
    }

    private function vndbLookupUserId(string $token, string $username): ?string
    {
        $resp = $this->vndbClient($token)->get('user', ['q' => $username]);
        if (!$resp->successful()) return null;

        $data = $resp->json();
        if (!is_array($data)) return null;

        foreach ($data as $row) {
            if (!empty($row['id'])) return $row['id'];
        }
        return null;
    }

    private function vndbFetchUlist(string $token, string $userId): array
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

        $page = 1; $all = [];
        do {
            $payload = ['user'=>$userId,'fields'=>$fields,'results'=>100,'page'=>$page];
            $resp = $this->vndbClient($token)->post('ulist', $payload);
            if (!$resp->successful()) break;

            $json  = $resp->json();
            $batch = $json['results'] ?? [];
            $all   = array_merge($all, $batch);
            $more  = !empty($json['more']);
            $page++;
        } while ($more);

        return $all;
    }

    public function markNsfw($mediaId)
    {
        $m = Media::where('type', 'vn')->findOrFail((int)$mediaId);

        if (\Schema::hasColumn('media', 'isNsfw')) {
            $m->isNsfw = 1;
            $m->save();
        }

        return back()->with('status', 'Marked as NSFW.');
    }
}
