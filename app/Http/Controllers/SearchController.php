<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Media;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        if ($q === '') {
            return response()->json([]);
        }

        $limit = (int) $request->query('limit', 12);

        $items = Media::query()
            ->where(function ($w) use ($q) {
                $w->where('title_english', 'like', "%{$q}%")
                    ->orWhere('title_romaji',  'like', "%{$q}%")
                    ->orWhere('title_native',  'like', "%{$q}%");
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $results = $items->map(function (Media $m) {
            $type = strtolower($m->type ?? '');
            if ($type === 'vn') $type = 'visual-novel';

            $isNsfw = (int)($m->isNsfw ?? 0) === 1;

            $cover = $m->cover_url;
            if ($cover) {
                if (!preg_match('#^https?://#i', $cover) && !str_starts_with($cover, '/')) {
                    $cover = Storage::url(ltrim($cover, '/'));
                }
            } else {
                $cover = asset('images/no-image.jpg');
            }

            return [
                'id'    => (string) $m->id,
                'type'  => $type,
                'title' => [
                    'english' => $m->title_english ?: null,
                    'romaji'  => $m->title_romaji  ?: null,
                    'native'  => $m->title_native  ?: null,
                ],
                'cover' => $cover,
                'coverImage' => ['extraLarge' => $cover],

                'isNsfw' => $isNsfw,

                'isAdult' => $isNsfw,
            ];
        })->values();

        return response()->json($results);
    }
}
