<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use App\Http\Controllers\AnilistController;
use Illuminate\Support\Facades\Storage;

class CollectionController extends Controller
{
    public function index()
    {
        // ensure “Favorites” exists
        $favorites = Collection::firstOrCreate(
            ['name' => 'Favorites'],
            ['is_system' => true]
        );

        $latestFav = Favorite::latest()->first();

        if (! $latestFav) {
            $favoritesThumbnail = asset('images/no-image.jpg');
        } else {
            $id = (int) ltrim($latestFav->favoritable_id, 'v');

            if ($latestFav->favoritable_type === 'visual-novel') {
                $vn = app(VndbController::class)->fetchVnById($id);
                $favoritesThumbnail = $vn['image']['url'] ?? asset('images/no-image.jpg');

            } elseif ($latestFav->favoritable_type === 'doujins') {
                $doujin = \App\Models\Doujin::find($id);
                if ($doujin) {
                    // Instead of asset(...), ask the B2 disk for the public URL:
                    $favoritesThumbnail = Storage::disk('b2')->url($doujin->cover_url);
                } else {
                    $favoritesThumbnail = asset('images/no-image.jpg');
                }
            }else {
                // fallback: AniList
                $ani = app(AnilistController::class)
                            ->fetchSingleItem($id, env('ANILIST_ACCESS_TOKEN'));

                $favoritesThumbnail = $ani['coverImage']['extraLarge']
                                ?? asset('images/no-image.jpg');
            }
        }

        $otherCollections = Collection::with('latestItem')
            ->where('is_system', false)
            ->get();

        // build an array of thumbnails keyed by collection id
        $collectionThumbnails = [];
        $anilist           = app(AnilistController::class);
        $token             = env('ANILIST_ACCESS_TOKEN');

        foreach ($otherCollections as $col) {

            // most-recent pivot row
            $latest = $col->items()->orderBy('created_at', 'desc')->first();

            if (!$latest) {
                $collectionThumbnails[$col->id] = asset('images/no-image.jpg');
                continue;
            }

            /* --- strip any leading “v” and cast to int --- */
            $numericId = (int) ltrim($latest->item_id, 'v');

            /* fetch the record (VNDB or AniList) */
            /* fetch the record (VNDB or AniList) — with a safety fallback */
            $typeHint  = $latest->item_type ?: 'visual-novel';   // default if NULL

            $media = $this->fetchMedia($typeHint, $numericId);

            if (!$media && $typeHint !== 'visual-novel') {
                // AniList look-up failed → try VNDB before giving up
                $media = $this->fetchMedia('visual-novel', $numericId);
            }

            if (!$media) {
                $collectionThumbnails[$col->id] = asset('images/no-image.jpg');
                continue;
            }

            /* decide where the cover lives */
            $detectedType = $media['__type'] ?? $latest->item_type;

            $thumb = $detectedType === 'visual-novel'
                    ? ($media['image']['url'] ?? asset('images/no-image.jpg'))
                    : ($media['coverImage']['extraLarge'] ?? asset('images/no-image.jpg'));

            $collectionThumbnails[$col->id] = $thumb;
        }


        return view('collection.index', compact(
            'favorites',
            'favoritesThumbnail',
            'otherCollections',
        ));
    }

    public function create()
    {
        return view('collection.create');
    }

    public function store(Request $request)
    {
        $request->validate([
        'name' => 'required|string|max:50|unique:collections,name'
        ]);

        $col = Collection::create([
        'name'      => $request->name,
        'is_system' => false,
        ]);

        if ($request->wantsJson()) {
            // return the new collection as JSON
            return response()->json($col);
        }

        return redirect()->route('collection.index');
    }


    public function show(Collection $collection)
    {
        // If this is the system “Favorites” collection, load from the favorites table:
        if ($collection->is_system) {
            $items = Favorite::all()->map(fn($f) => (object)[
                'item_type'     => $f->favoritable_type,
                'item_id'       => $f->favoritable_id,
                'thumbnail_url' => $f->thumbnail_url,
                'title'         => $f->title,
                'type_label'    => match($f->favoritable_type) {
                    'visual-novel' => 'Visual Novel',
                    'doujins'      => 'Doujin',
                    'animes'       => 'Anime',
                    'mangas'       => 'Manga',
                    'manwhas'      => 'Manwha',
                    'hentais'      => 'Hentai',
                    default        => ucfirst($f->favoritable_type),
                },
            ]);
        } else {
            $items = $collection->items()->get();
        }
        return view('collection.content', [
            'collection' => $collection,
            'items'      => $items,
        ]);
    }

    public function destroy(Collection $collection)
    {
        if ($collection->is_system) {
            abort(403, 'Cannot remove system collection.');
        }

        $collection->delete();

        return redirect()->route('collection.index')
                         ->with('status', 'Collection deleted');
    }

    public function attachMedia(Request $request)
    {
        // normalise the VN id first
        $raw   = $request->input('item_id');        // "v11" or "123"
        $clean = (int) ltrim($raw, 'v');            // 11

        $data = $request->validate([
            'item_type'        => 'required|in:animes,mangas,manwhas,hentais,doujins,visual-novel',
            'item_id'          => 'required',       // we’ll cast ourselves
            'add_to_favorites' => 'nullable|in:1',
            'collection_ids'   => 'nullable|array',
            'collection_ids.*' => 'integer|exists:collections,id',
        ]);

        $data['item_id'] = $clean;                 // overwrite with the int

        /* ----------------------------------------------------------
        1)  Favourites toggle  ( works already )
        ---------------------------------------------------------- */
        if ($request->filled('add_to_favorites')) {
            Favorite::firstOrCreate([
                'favoritable_type' => $data['item_type'],
                'favoritable_id'   => $data['item_id'],
            ]);
        } else {
            Favorite::where([
                ['favoritable_type', $data['item_type']],
                ['favoritable_id',   $data['item_id']],
            ])->delete();
        }

        /* ----------------------------------------------------------
        2)  Custom collections
        ---------------------------------------------------------- */
        $newIds   = $data['collection_ids'] ?? [];

        // currently attached, excluding system collections
        $existing = CollectionItem::where('item_type', $data['item_type'])
                    ->where('item_id',   $data['item_id'])
                    ->pluck('collection_id')
                    ->toArray();

        // a) remove unchecked
        $toRemove = array_diff($existing, $newIds);
        if ($toRemove) {
            CollectionItem::where('item_type', $data['item_type'])
                ->where('item_id',   $data['item_id'])
                ->whereIn('collection_id', $toRemove)
                ->delete();
        }

        // b) add newly checked
        foreach ($newIds as $cid) {
            // 1) fetch media
            $media = $this->fetchMedia($data['item_type'], $clean);

            // 2) extract thumbnail URL
            $thumb = $media['coverImage']['extraLarge']
                ?? ($media['image']['url'] ?? null);

            // 3) extract title (english, then romaji, then fallback)
            $title = null;
            if (isset($media['title']['english'])) {
                $title = $media['title']['english'];
            } elseif (isset($media['title']['romaji'])) {
                $title = $media['title']['romaji'];
            } elseif (is_string($media['title'] ?? null)) {
                $title = $media['title'];
            }

            // 4) upsert pivot with thumbnail and title
            CollectionItem::updateOrCreate(
                [
                    'collection_id' => $cid,
                    'item_type'     => $data['item_type'],
                    'item_id'       => $clean,
                ],
                [
                    'thumbnail_url' => $thumb,
                    'title'         => $title,
                ]
            );
        }

        return back()->with('status', 'Collections updated');
    }



    public function fetchMedia(string $type, int $id)
    {
        /* ---------- 1. VNDB ---------- */
        if ($type === 'visual-novel') {
            $vn = app(VndbController::class)->fetchVnById($id);
            if ($vn) {
                $vn['__type'] = 'visual-novel';
            }
            return $vn;
        }

        /* ---------- 2. Local doujins ---------- */
        if ($type === 'doujins') {
            $doujin = \App\Models\Doujin::find($id);
            if (! $doujin) {
                return null;
            }
            return [
                '__type'     => 'doujins',
                'coverImage' => [
                    'extraLarge' => Storage::disk('b2')->url($doujin->cover_url),
                ],
                'title' => [
                    'english' => $doujin->doujin_name,
                    'romaji'  => $doujin->doujin_name,
                ],
            ];
        }

        /* ---------- 3. Everything else → AniList ---------- */
        $token = env('ANILIST_ACCESS_TOKEN');
        $ani   = app(AnilistController::class)->fetchSingleItem($id, $token);

        if ($ani) {
            $ani['__type'] = $type;   // e.g. “animes”, “mangas”… for later checks
        }

        return $ani;                 // could still be null if AniList had no hit
    }

    public function removeItem(Collection $collection, Request $request)
    {
        $rawId = $request->input('item_id');          // e.g. "v11" or "123"
        $id    = (int) ltrim($rawId, 'v');            // → 11

        $data = $request->validate([
            'item_type' => 'required',
            'item_id'   => 'required',                // already cleaned above
        ]);

        if ($collection->is_system) {
            // Favourites → delete from the favourites table
            Favorite::where([
                ['favoritable_type', $data['item_type']],
                ['favoritable_id',   $id],
            ])->delete();
        } else {
            // Normal collection → delete the pivot row
            CollectionItem::where('collection_id', $collection->id)
                ->where('item_type', $data['item_type'])
                ->where('item_id',   $id)
                ->delete();
        }

        return back();
    }


}