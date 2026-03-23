<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Chapter;
use App\Models\ChapterPage;

class ChapterController extends Controller
{
    public function syncFromDisk(Request $request, int $mediaId)
    {
        $mediaRow = \DB::table('media')
            ->where('id', $mediaId)
            ->select('type', 'origin')
            ->first();

        $mediaType = strtoupper($mediaRow->type ?? 'MANGA');
        $origin    = strtoupper($mediaRow->origin ?? '');

        $isManwha  = ($mediaType === 'MANGA' && $origin === 'KR') || $mediaType === 'MANWHA';
        $baseDir   = $isManwha ? 'manwha' : 'manga';
        $itemType  = $isManwha ? 'MANWHA' : 'MANGA';

        $disk = \Storage::disk('public');
        $root = "{$baseDir}/{$mediaId}";

        if (!$disk->exists($root)) {
            return back()->with('status', "Folder not found: {$root}");
        }

        $chapterDirs = collect($disk->directories($root));
        if ($chapterDirs->isEmpty()) {
            $chapterDirs = collect([$root]);
        }

        $imported = 0;

        foreach ($chapterDirs as $chapterPath) {
            $folderName = basename($chapterPath);

            $number = $this->parseChapterNumber($folderName);
            if ($number === null) {
                $max = Chapter::where('item_id', $mediaId)->max('chapter_number') ?? 0;
                $number = (float)((int)$max + 1);
            }

            $chapter = Chapter::firstOrCreate(
                [
                    'item_id'       => $mediaId,
                    'chapter_title' => $folderName,
                ],
                [
                    'item_type'      => $itemType,
                    'item_id'        => $mediaId,
                    'media_fk'       => $mediaId,
                    'chapter_number' => $number,
                ]
            );

            $dirty = false;

            if (empty($chapter->media_fk)) {
                $chapter->media_fk = $mediaId;
                $dirty = true;
            }
            if (empty($chapter->chapter_number) && $chapter->chapter_number !== 0.0) {
                $max = Chapter::where('item_id', $mediaId)->max('chapter_number') ?? 0;
                $chapter->chapter_number = (float)((int)$max + 1);
                $dirty = true;
            }
            if (empty($chapter->item_type)) {
                $chapter->item_type = $itemType;
                $dirty = true;
            }
            if ($dirty) $chapter->save();

            $files = collect($disk->files($chapterPath))
                ->filter(fn($p) => in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp']))
                ->sortBy(fn($p) => strtolower(basename($p)))
                ->values();

            if ($files->isEmpty()) {
                continue;
            }

            $existing = ChapterPage::where('chapter_id', $chapter->id)->pluck('file_path')->map(fn($p)=>ltrim($p,'/'))->toArray();
            $pageNum  = (int)(ChapterPage::where('chapter_id', $chapter->id)->max('page_number') ?? 0) + 1;

            foreach ($files as $relativePath) {
                $rel = ltrim($relativePath, '/');
                if (in_array($rel, $existing, true)) continue;

                ChapterPage::create([
                    'chapter_id'  => $chapter->id,
                    'page_number' => $pageNum++,
                    'file_path'   => $rel,
                ]);
            }

            $imported++;
        }

        return back();
    }




    private function parseChapterNumber(string $name): ?float
    {
        $n = mb_strtolower($name);

        if (preg_match('/\b(?:chapter|ch|c)[\s\-_]*([0-9]+(?:\.[0-9]+)?)/i', $n, $m)) {
            return (float) $m[1];
        }
        if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\b/', $n, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    public function readPage(Request $request, int $mediaId, $chapterParam, ?int $pageNumber = null)
    {
        $view = $request->query('view', 'one');

        if (!is_numeric($chapterParam)) {
            $byTitle = Chapter::where('item_id', $mediaId)
                ->where('chapter_title', $chapterParam)
                ->firstOrFail();

            return redirect()->route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $byTitle->chapter_number,
                'page'    => $pageNumber ?? 1,
                'view'    => $view,
            ]);
        }

        $chapterNumber = (float) $chapterParam;

        $chapter = Chapter::with(['pages' => fn($q) => $q->orderBy('page_number')])
            ->where('item_id', $mediaId)
            ->where('chapter_number', $chapterNumber)
            ->firstOrFail();

        if ($pageNumber === null) {
            $pageNumber = optional($chapter->pages->first())->page_number ?? 1;
        }

        $mediaRow = DB::table('media')
            ->where('id', $chapter->media_fk)
            ->select('id','title_english','title_romaji','title_native','slug','type','origin')
            ->first();

        $itemTitle = $mediaRow->title_english ?? $mediaRow->title_romaji ?? $mediaRow->title_native ?? 'Unknown Item';

        $isManwha = strtoupper($mediaRow->type ?? '') === 'MANWHA'
            || strtoupper($mediaRow->origin ?? '') === 'KR';

        if (strtolower($mediaRow->type ?? '') === 'doujin') {
            $itemUrl = route('doujins.show', ['media' => $chapter->media_fk]);
        } else {
            $itemUrl = route('media.show', ['id' => $chapter->media_fk]);
        }

        $pages   = $chapter->pages->values();
        $page    = $pages->firstWhere('page_number', $pageNumber);
        abort_if(!$page, 404);
        $pageUrl = asset('storage/'.$page->file_path);

        $nums = $pages->pluck('page_number')->values()->all();
        $idx  = array_search($pageNumber, $nums, true);
        $prevPageNum = ($idx !== false && $idx > 0) ? $nums[$idx-1] : null;
        $nextPageNum = ($idx !== false && $idx < count($nums)-1) ? $nums[$idx+1] : null;

        $nextChapter = Chapter::where('item_id', $mediaId)
            ->where('chapter_number', '>', $chapterNumber)
            ->orderBy('chapter_number', 'asc')
            ->first();

        $prevChapter = Chapter::where('item_id', $mediaId)
            ->where('chapter_number', '<', $chapterNumber)
            ->orderBy('chapter_number', 'desc')
            ->first();

        $prevChapterLastPage = null;
        if ($prevChapter) {
            $prevChapterLastPage = ChapterPage::where('chapter_id', $prevChapter->id)->max('page_number') ?? 1;
        }

        $prevLink = null;
        if ($prevPageNum !== null) {
            $prevLink = route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $chapter->chapter_number,
                'page'    => $prevPageNum,
                'view'    => $view,
            ]);
        } elseif ($prevChapter && $prevChapterLastPage) {
            $prevLink = route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $prevChapter->chapter_number,
                'page'    => $prevChapterLastPage,
                'view'    => $view,
            ]);
        }

        $nextLink = null;
        if ($nextPageNum !== null) {
            $nextLink = route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $chapter->chapter_number,
                'page'    => $nextPageNum,
                'view'    => $view,
            ]);
        } elseif ($nextChapter) {
            $nextLink = route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $nextChapter->chapter_number,
                'page'    => 1,
                'view'    => $view,
            ]);
        } else {
            $nextLink = $itemUrl;
        }

        $prevChapterLink = $prevChapter
            ? route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $prevChapter->chapter_number,
                'page'    => $prevChapterLastPage ?: 1,
                'view'    => $view,
            ])
            : null;

        $nextChapterLink = $nextChapter
            ? route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $nextChapter->chapter_number,
                'page'    => 1,
                'view'    => $view,
            ])
            : $itemUrl;

        return view('chapters.read', [
            'chapter'           => $chapter,
            'pages'             => $pages,
            'pageNumber'        => $pageNumber,
            'pageUrl'           => $pageUrl,
            'prevLink'          => $prevLink,
            'nextLink'          => $nextLink,
            'prevChapterLink'   => $prevChapterLink,
            'nextChapterLink'   => $nextChapterLink,
            'nextPairLink'      => null,
            'prevPairLink'      => null,
            'itemTitle'         => $itemTitle,
            'itemUrl'           => $itemUrl,
            'isManwha'          => $isManwha,
        ]);
    }


}
