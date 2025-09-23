<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use Illuminate\Support\Facades\Storage;
use App\Models\Media;
use App\Models\Doujin;
use Illuminate\Support\Str;


class CollectionController extends Controller
{
    public function index()
    {
        $favorites = Collection::firstOrCreate(
            ['name' => 'Favorites'],
            ['is_system' => true]
        );

        $latestFav = Favorite::latest()->first();

        if (! $latestFav) {
            $favoritesThumbnail = asset('images/no-image.jpg');
        } else {
            $id = (int) ltrim((string)$latestFav->favoritable_id, 'v');

            switch ($latestFav->favoritable_type) {
                case 'visual-novel':
                    $vn = app(VndbController::class)->fetchVnById($id);
                    $favoritesThumbnail = $vn['image']['url'] ?? asset('images/no-image.jpg');
                    break;

                case 'doujins':
                    $doujin = Doujin::find($id);
                    $favoritesThumbnail = $doujin
                        ? Storage::url($doujin->cover_url)
                        : asset('images/no-image.jpg');
                    break;

                case 'animes':
                case 'mangas':
                case 'manwhas':
                case 'hentais':
                    $m = Media::find($id);
                    $favoritesThumbnail = $m
                        ? (Str::startsWith($m->cover_url, ['http://','https://','/'])
                            ? $m->cover_url
                            : asset($m->cover_url))
                        : asset('images/no-image.jpg');
                    break;

                default:
                    $favoritesThumbnail = asset('images/no-image.jpg');
            }
        }

        $otherCollections = Collection::with('latestItem')
            ->where('is_system', false)
            ->get();

        $collectionThumbnails = [];

        foreach ($otherCollections as $col) {

            $latest = $col->items()->orderBy('created_at', 'desc')->first();

            if (!$latest) {
                $collectionThumbnails[$col->id] = asset('images/no-image.jpg');
                continue;
            }

            $numericId = (int) ltrim($latest->item_id, 'v');

            $typeHint  = $latest->item_type ?: 'visual-novel';

            $media = $this->fetchMedia($typeHint, $numericId);

            if (!$media && $typeHint !== 'visual-novel') {
                $media = $this->fetchMedia('visual-novel', $numericId);
            }

            if (!$media) {
                $collectionThumbnails[$col->id] = asset('images/no-image.jpg');
                continue;
            }

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
            'collectionThumbnails',
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
            return response()->json($col);
        }

        return redirect()->route('collection.index');
    }


    public function show(Collection $collection)
    {
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
        $raw   = $request->input('item_id');
        $clean = (int) ltrim($raw, 'v');

        $data = $request->validate([
            'item_type'        => 'required|in:animes,mangas,manwhas,hentais,doujins,visual-novel',
            'item_id'          => 'required',
            'add_to_favorites' => 'nullable|in:1',
            'collection_ids'   => 'nullable|array',
            'collection_ids.*' => 'integer|exists:collections,id',
        ]);

        $data['item_id'] = $clean;

        if ($request->filled('add_to_favorites')) {
            $media = $this->fetchMedia($data['item_type'], $clean);

            $thumb = $media['coverImage']['extraLarge']
                ?? ($media['image']['url'] ?? asset('images/no-image.jpg'));

            $title = $media['title']['english']
                ?? $media['title']['romaji']
                ?? (is_string($media['title'] ?? null) ? $media['title'] : null)
                ?? 'Untitled';

            Favorite::updateOrCreate(
                [
                    'favoritable_type' => $data['item_type'],
                    'favoritable_id'   => $data['item_id'],
                ],
                [
                    'thumbnail_url' => $thumb,
                    'title'         => $title,
                ]
            );
        } else {
            Favorite::where([
                ['favoritable_type', $data['item_type']],
                ['favoritable_id',   $data['item_id']],
            ])->delete();
        }

        $newIds   = $data['collection_ids'] ?? [];

        $existing = CollectionItem::where('item_type', $data['item_type'])
                    ->where('item_id',   $data['item_id'])
                    ->pluck('collection_id')
                    ->toArray();

        $toRemove = array_diff($existing, $newIds);
        if ($toRemove) {
            CollectionItem::where('item_type', $data['item_type'])
                ->where('item_id',   $data['item_id'])
                ->whereIn('collection_id', $toRemove)
                ->delete();
        }

        foreach ($newIds as $cid) {
            $media = $this->fetchMedia($data['item_type'], $clean);

            $thumb = $media['coverImage']['extraLarge']
                ?? ($media['image']['url'] ?? null);

            $title = null;
            if (isset($media['title']['english'])) {
                $title = $media['title']['english'];
            } elseif (isset($media['title']['romaji'])) {
                $title = $media['title']['romaji'];
            } elseif (is_string($media['title'] ?? null)) {
                $title = $media['title'];
            }

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
        if (in_array($type, ['animes','mangas','manwhas','hentais'], true)) {
            $m = Media::find($id);
            if (! $m) return null;

            $cover = $m->cover_url
                ? (Str::startsWith($m->cover_url, ['http://','https://','/'])
                    ? $m->cover_url
                    : asset($m->cover_url))
                : asset('images/no-image.jpg');

            return [
                '__type'     => $type,
                'coverImage' => ['extraLarge' => $cover],
                'title'      => [
                    'english' => $m->title_english,
                    'romaji'  => $m->title_romaji,
                ],
            ];
        }

        if ($type === 'doujins') {
            $doujin = Doujin::find($id);
            if (! $doujin) return null;

            return [
                '__type'     => 'doujins',
                'coverImage' => ['extraLarge' => Storage::url($doujin->cover_url)],
                'title'      => [
                    'english' => $doujin->doujin_name,
                    'romaji'  => $doujin->doujin_name,
                ],
            ];
        }

        if ($type === 'visual-novel') {
            $vn = app(VndbController::class)->fetchVnById($id);
            if ($vn) $vn['__type'] = 'visual-novel';
            return $vn;
        }

        return null;
    }

    public function removeItem(Collection $collection, Request $request)
    {
        $rawId = $request->input('item_id');
        $id    = (int) ltrim($rawId, 'v');

        $data = $request->validate([
            'item_type' => 'required',
            'item_id'   => 'required',
        ]);

        if ($collection->is_system) {
            Favorite::where([
                ['favoritable_type', $data['item_type']],
                ['favoritable_id',   $id],
            ])->delete();
        } else {
            CollectionItem::where('collection_id', $collection->id)
                ->where('item_type', $data['item_type'])
                ->where('item_id',   $id)
                ->delete();
        }

        return back();
    }
    public function rename(Request $request, Collection $collection)
    {
        if ($collection->is_system) {
            abort(403, 'Cannot rename system collection.');
        }

        $data = $request->validate([
            // unique except the current collection
            'name' => 'required|string|max:50|unique:collections,name,' . $collection->id,
        ]);

        $collection->update(['name' => $data['name']]);

        return back()->with('status', 'Collection renamed.');
    }

}
