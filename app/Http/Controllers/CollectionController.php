<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;
use Illuminate\Support\Facades\Storage;
use App\Models\Media;
use Illuminate\Support\Str;


class CollectionController extends Controller
{
    private function abortIfViewer(Request $request): void
    {
        abort_if(optional($request->user()?->role)->role === 'Viewer', 403);
    }

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
                    $doujin = Media::where('type', 'doujin')->find($id);
                    $favoritesThumbnail = $doujin
                        ? ($doujin->cover_url
                            ? (Str::startsWith((string) $doujin->cover_url, ['http://','https://','/'])
                                ? $doujin->cover_url
                                : Storage::url((string) $doujin->cover_url))
                            : asset('images/no-image.jpg'))
                        : asset('images/no-image.jpg');
                    break;

                case 'animes':
                case 'mangas':
                case 'manhwas':
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
        'name' => 'required|string|max:50|unique:collections,name',
        'attach_item_type' => 'nullable|in:animes,mangas,manhwas,hentais,doujins,visual-novel',
        'attach_item_id' => 'nullable',
        ]);

        $col = Collection::create([
        'name'      => $request->name,
        'is_system' => false,
        ]);

        $attachType = $request->input('attach_item_type');
        $attachItemId = $request->input('attach_item_id');
        if ($attachType && $attachItemId !== null && $attachItemId !== '') {
            $this->attachItemToCollection($col->id, $attachType, $attachItemId);
        }

        if ($request->wantsJson()) {
            return response()->json($col);
        }

        return redirect()->route('collection.index');
    }


    public function show(Collection $collection)
    {
        if ($collection->is_system) {
            $items = Favorite::orderBy('id', 'asc')->get()->map(fn($f) => (object)[
                'item_type'     => $f->favoritable_type,
                'item_id'       => $f->favoritable_id,
                'thumbnail_url' => $f->thumbnail_url,
                'title'         => $f->title,
                'type_label'    => match($f->favoritable_type) {
                    'visual-novel' => 'Visual Novel',
                    'doujins'      => 'Doujin',
                    'animes'       => 'Anime',
                    'mangas'       => 'Manga',
                    'manhwas'      => 'Manhwa',
                    'hentais'      => 'Hentai',
                    default        => ucfirst($f->favoritable_type),
                },
            ]);
        } else {
            $items = $collection->items()->orderBy('id', 'asc')->get();
        }

        return view('collection.content', [
            'collection' => $collection,
            'items'      => $items,
        ]);
    }


    public function destroy(Collection $collection)
    {
        abort_if(optional(auth()->user()?->role)->role === 'Viewer', 403);

        if ($collection->is_system) {
            abort(403, 'Cannot remove system collection.');
        }

        $collection->delete();

        return redirect()->route('collection.index');
    }

    public function attachMedia(Request $request)
    {
        // normalise the VN id first
        $raw   = $request->input('item_id');
        $clean = (int) ltrim($raw, 'v');

        $data = $request->validate([
            'item_type'        => 'required|in:animes,mangas,manhwas,hentais,doujins,visual-novel',
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
                ?? $media['title']['native']
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
            } elseif (isset($media['title']['native'])) {
                $title = $media['title']['native'];
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

        return back();
    }

    private function attachItemToCollection(int $collectionId, string $itemType, string|int $rawItemId): void
    {
        $cleanId = (int) ltrim((string) $rawItemId, 'v');
        $media = $this->fetchMedia($itemType, $cleanId);

        $thumb = $media['coverImage']['extraLarge']
            ?? ($media['image']['url'] ?? null);

        $title = null;
        if (isset($media['title']['english'])) {
            $title = $media['title']['english'];
        } elseif (isset($media['title']['romaji'])) {
            $title = $media['title']['romaji'];
        } elseif (isset($media['title']['native'])) {
            $title = $media['title']['native'];
        } elseif (is_string($media['title'] ?? null)) {
            $title = $media['title'];
        }

        CollectionItem::updateOrCreate(
            [
                'collection_id' => $collectionId,
                'item_type' => $itemType,
                'item_id' => $cleanId,
            ],
            [
                'thumbnail_url' => $thumb,
                'title' => $title,
            ]
        );
    }

    public function fetchMedia(string $type, int $id)
    {
        if (in_array($type, ['animes','mangas','manhwas','hentais'], true)) {
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
                    'native'  => $m->title_native,
                ],
            ];
        }

        if ($type === 'doujins') {
            $doujin = Media::where('type', 'doujin')->find($id);
            if (! $doujin) return null;

            $cover = $doujin->cover_url
                ? (Str::startsWith((string) $doujin->cover_url, ['http://','https://','/'])
                    ? $doujin->cover_url
                    : Storage::url((string) $doujin->cover_url))
                : asset('images/no-image.jpg');

            return [
                '__type'     => 'doujins',
                'coverImage' => ['extraLarge' => $cover],
                'title'      => [
                    'english' => $doujin->title_english,
                    'romaji'  => $doujin->title_romaji,
                    'native'  => $doujin->title_native,
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
        $this->abortIfViewer($request);

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

        return back();
    }

    public function random(Collection $collection)
    {
        if ($collection->is_system) {
            abort(404);
        }

        $ci = $collection->items()->inRandomOrder()->first();

        if (! $ci) {
            return back()->with('status', 'This collection is empty.');
        }

        $id = (int) ltrim((string)$ci->item_id, 'v');

        switch ($ci->item_type) {
            case 'visual-novel':
                $link = route('vn.show', 'v'.$id);
                break;
            case 'doujins':
                $link = route('doujins.show', ['media' => $id]);
                break;
            default:
                $link = route('media.show', $id);
                break;
        }

        return redirect()->to($link);
    }
}
