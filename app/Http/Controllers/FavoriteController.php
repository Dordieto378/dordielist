<?php
namespace App\Http\Controllers;

use App\Models\Favorite;
use Illuminate\Http\Request;
use App\Http\Controllers\CollectionController; 
use Illuminate\Support\Facades\Storage;

class FavoriteController extends Controller
{
    // app/Http/Controllers/FavoriteController.php
    public function toggle(Request $request)
    {
        $rawId   = $request->input('favoritable_id'); // "v11" or "123"
        $cleanId = (int) ltrim($rawId, 'v');          // 11

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
            // fetch cover + title exactly once:
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
