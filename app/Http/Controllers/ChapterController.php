<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Models\Chapter;
use App\Models\ChapterPage;
use Illuminate\Support\Str;

class ChapterController extends Controller
{
    public function syncFromDisk(Request $request, int $mediaId)
    {
        // Figure out where to read from based on media row
        $mediaRow = \DB::table('media')
            ->where('id', $mediaId)
            ->select('type', 'origin')
            ->first();

        $mediaType = strtoupper($mediaRow->type ?? 'MANGA');
        $origin    = strtoupper($mediaRow->origin ?? '');

        // Convention: Korean MANGA -> manwha folder; else manga folder
        $isManwha  = ($mediaType === 'MANGA' && $origin === 'KR') || $mediaType === 'MANWHA';
        $baseDir   = $isManwha ? 'manwha' : 'manga';   // <- your folder names
        $itemType  = $isManwha ? 'MANWHA' : 'MANGA';   // <- what we store in DB

        $disk = \Storage::disk('public');
        $root = "{$baseDir}/{$mediaId}";

        if (!$disk->exists($root)) {
            return back()->with('status', "Folder not found: {$root}");
        }

        $chapterDirs = collect($disk->directories($root));
        if ($chapterDirs->isEmpty()) {
            $chapterDirs = collect([$root]); // single-chapter folder
        }

        $imported = 0;

        foreach ($chapterDirs as $chapterPath) {
            $folderName = basename($chapterPath);  // chapter_title shown in UI

            // Parse number; if none, assign next sequential number (max + 1)
            $number = $this->parseChapterNumber($folderName);
            if ($number === null) {
                $max = Chapter::where('item_id', $mediaId)->max('chapter_number') ?? 0;
                $number = (float)((int)$max + 1);
            }

            // Create or get by (item_id, chapter_title). Then ensure media_fk & number exist.
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

            // Collect image files
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

        return back()->with('status', "Synced {$imported} chapter(s) from {$root}");
    }




    private function parseChapterNumber(string $name): ?float
    {
        $n = mb_strtolower($name);

        // chapter / ch / c + number (allow decimals)
        if (preg_match('/\b(?:chapter|ch|c)[\s\-_]*([0-9]+(?:\.[0-9]+)?)/i', $n, $m)) {
            return (float) $m[1];
        }
        // bare number (allow decimals)
        if (preg_match('/\b([0-9]+(?:\.[0-9]+)?)\b/', $n, $m)) {
            return (float) $m[1];
        }
        return null; // no numeric hint
    }

// Show by chapter_number OR chapter_title (slug)
// ChapterController.php

    public function readPage(Request $request, int $mediaId, $chapterParam, ?int $pageNumber = null)
    {
        $view = $request->query('view', 'one');

        // 1) Canonicalize: if chapterParam is a title, resolve it to a number and redirect
        if (!is_numeric($chapterParam)) {
            $byTitle = Chapter::where('item_id', $mediaId)
                ->where('chapter_title', $chapterParam)
                ->firstOrFail();

            return redirect()->route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $byTitle->chapter_number,   // canonical: use number
                'page'    => $pageNumber ?? 1,
                'view'    => $view,
            ]);
        }

        // From here on, chapterParam is numeric (chapter_number)
        $chapterNumber = (float) $chapterParam;

        $chapter = Chapter::with(['pages' => fn($q) => $q->orderBy('page_number')])
            ->where('item_id', $mediaId)
            ->where('chapter_number', $chapterNumber)
            ->firstOrFail();

        // Default page = first page
        if ($pageNumber === null) {
            $pageNumber = optional($chapter->pages->first())->page_number ?? 1;
        }

        $mediaRow = DB::table('media')
            ->where('id', $chapter->media_fk)
            ->select('id','title_english','title_romaji','slug','type','origin','publisher')
            ->first();

        $itemTitle = $mediaRow->title_english ?? $mediaRow->title_romaji ?? 'Unknown Item';

        $isManwha = strtoupper($mediaRow->type ?? '') === 'MANWHA'
            || strtoupper($mediaRow->origin ?? '') === 'KR';

// IMPORTANT: build the URL by media id for doujins
        if (strtolower($mediaRow->type ?? '') === 'doujin') {
            $itemUrl = route('doujins.show', ['media' => $chapter->media_fk]);  // /doujin/{media}
        } else {
            $itemUrl = route('media.show', ['id' => $chapter->media_fk]);       // /media/{id}
        }

        $pages   = $chapter->pages->values();
        $page    = $pages->firstWhere('page_number', $pageNumber);
        abort_if(!$page, 404);
        $pageUrl = asset('storage/'.$page->file_path);

        // In-chapter prev/next
        $nums = $pages->pluck('page_number')->values()->all();
        $idx  = array_search($pageNumber, $nums, true);
        $prevPageNum = ($idx !== false && $idx > 0) ? $nums[$idx-1] : null;
        $nextPageNum = ($idx !== false && $idx < count($nums)-1) ? $nums[$idx+1] : null;

        // Neighbor chapters by NUMBER (strictly)
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

        // Build numeric prev/next links
        $prevLink = null;
        if ($prevPageNum !== null) {
            $prevLink = route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $chapter->chapter_number,   // number
                'page'    => $prevPageNum,
                'view'    => $view,
            ]);
        } elseif ($prevChapter && $prevChapterLastPage) {
            $prevLink = route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $prevChapter->chapter_number, // number
                'page'    => $prevChapterLastPage,
                'view'    => $view,
            ]);
        }

        $nextLink = null;
        if ($nextPageNum !== null) {
            $nextLink = route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $chapter->chapter_number,   // number
                'page'    => $nextPageNum,
                'view'    => $view,
            ]);
        } elseif ($nextChapter) {
            $nextLink = route('chapters.page', [
                'media'   => $mediaId,
                'chapter' => $nextChapter->chapter_number, // number
                'page'    => 1,
                'view'    => $view,
            ]);
        }

        // Explicit chapter jumps for Scroll view (numeric)
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
            : null;

        return view('chapters.read', [
            'chapter'          => $chapter,   // use chapter_title ONLY for display in Blade
            'pages'            => $pages,
            'pageNumber'       => $pageNumber,
            'pageUrl'          => $pageUrl,

            'prevLink'         => $prevLink,
            'nextLink'         => $nextLink,
            'prevChapterLink'  => $prevChapterLink,
            'nextChapterLink'  => $nextChapterLink,

            // For your double-view math
            'nextPairLink'     => null, // Blade computes pair pages; these can be left null or set similarly if you prefer server-side
            'prevPairLink'     => null,
            'itemTitle'        => $itemTitle,
            'itemUrl'   => $itemUrl,
            'isManwha'         => $isManwha,
        ]);
    }


}
