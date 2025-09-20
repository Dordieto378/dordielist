<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Media;
use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Favorite;

class DoujinController extends Controller
{
    public function show(int $mediaId)
    {
        $media = Media::where('type', 'doujin')->findOrFail($mediaId);

        // Chapters
        $chapters = Chapter::where('item_type', 'doujin')
            ->where('media_fk', $media->id)
            ->orderBy('chapter_number')
            ->get(['id','chapter_number','chapter_title']);

        // Pages per chapter
        $chaptersView = $chapters->map(function (Chapter $ch) {
            $pages = ChapterPage::where('chapter_id', $ch->id)
                ->orderBy('page_number')
                ->get(['id','page_number','file_path']);

            return [
                'id'     => $ch->id,
                'number' => $ch->chapter_number,
                'title'  => $ch->chapter_title,
                'pages'  => $pages->map(fn ($p) => [
                    'id'   => $p->id,
                    'num'  => $p->page_number,
                    'url'  => Storage::url($p->file_path),
                    'path' => $p->file_path,
                ])->values()->all(),
            ];
        });

        // Cover URL
        $coverUrl = $media->cover_url ? Storage::url($media->cover_url) : asset('images/no-image.jpg');

        $isFavorited = \App\Models\Favorite::where([
            ['favoritable_type', 'doujins'],
            ['favoritable_id',   $media->id],
        ])->exists();

        $allCollections = \App\Models\Collection::orderBy('is_system','desc')
            ->orderBy('name')->get();

        $attachedIds = \App\Models\CollectionItem::where('item_type', 'doujins')
            ->where('item_id',   $media->id)
            ->pluck('collection_id')
            ->toArray();

        return view('media.doujin', [
            'media'         => $media,
            'coverUrl'      => $coverUrl,
            'chapters'      => $chaptersView,
            'isFavorited'   => $isFavorited,
            'allCollections'=> $allCollections,
            'attachedIds'   => $attachedIds,
        ]);
    }

}
