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
                    ->orWhere('title_romaji',  'like', "%{$q}%");
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $results = $items->map(function (Media $m) {
            // Normalize type
            $type = strtolower($m->type ?? '');
            if ($type === 'vn') $type = 'visual-novel';

            // Normalize genres into an array (in case it's stored as JSON)
            $genres = $m->genres;
            if (is_string($genres)) {
                $decoded = json_decode($genres, true);
                $genres = is_array($decoded) ? $decoded : [];
            }
            $genres = array_map('strtolower', $genres ?? []);

            // Adult flag
            $isAdult = (bool)($m->is_adult ?? in_array('hentai', $genres, true) || $type === 'doujin');

            // Normalize cover to a browser-usable URL
            $cover = $m->cover_url;
            if ($cover) {
                // if it's not absolute, resolve via storage
                if (!preg_match('#^https?://#i', $cover) && !str_starts_with($cover, '/')) {
                    $cover = Storage::url(ltrim($cover, '/')); // -> "/storage/…"
                }
            } else {
                $cover = asset('images/no-image.jpg');
            }

            return [
                'id'    => (string)$m->id,
                'type'  => $type,
                'title' => [
                    'english' => $m->title_english ?: null,
                    'romaji'  => $m->title_romaji  ?: null,
                ],

                // NEW: normalized cover the popup can use directly
                'cover' => $cover,

                // Keep old shape for any legacy client code
                'coverImage' => [
                    'extraLarge' => $cover,
                ],

                'isAdult' => $isAdult,
            ];
        })->values();

        return response()->json($results);
    }
}
