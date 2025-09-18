<?php
namespace App\Http\Controllers;

use App\Models\Favorite;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function toggle(Request $request)
    {
        $rawId   = $request->input('favoritable_id');
        $cleanId = (int) ltrim($rawId, 'v');

        $data = $request->validate([
            'favoritable_type' => 'required|in:animes,mangas,manwhas,hentais,doujins,visual-novel',
            'favoritable_id'   => 'required',
        ]);

        $type = $data['favoritable_type'];
        $id   = $cleanId;

        $existing = Favorite::where('favoritable_type',$type)
                            ->where('favoritable_id',$id)
                            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $media = app(CollectionController::class)
                    ->fetchMedia($type, $id);

            $thumb = $media['coverImage']['extraLarge']
                ?? ($media['image']['url'] ?? null);

            if (isset($media['title']['english'])) {
                $title = $media['title']['english'];
            } elseif (isset($media['title']['romaji'])) {
                $title = $media['title']['romaji'];
            } else {
                $title = is_string($media['title'] ?? null)
                    ? $media['title']
                    : null;
            }

            Favorite::create([
                'favoritable_type' => $type,
                'favoritable_id'   => $id,
                'thumbnail_url'    => $thumb,
                'title'            => $title,
            ]);
        }

        return back();
    }
}
