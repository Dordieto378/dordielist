<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use App\Models\Media;
use App\Models\Chapter;
use App\Models\ChapterPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class DoujinController extends Controller
{
    public function show(int $mediaId)
    {
        $media = Media::where('type', 'doujin')->findOrFail($mediaId);

        $chapters = Chapter::where('item_type', 'doujin')
            ->where('media_fk', $media->id)
            ->orderBy('chapter_number')
            ->get(['id','chapter_number','chapter_title']);

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

    public function syncAll(Request $request)
    {
        $disk = Storage::disk('public');
        $root = 'doujin';
        if (!$disk->exists($root)) {
            return back()->with('error', "Folder not found: storage/app/public/{$root}");
        }

        $entries = [];
        foreach ($disk->directories($root) as $authorPath) {
            $author = basename($authorPath);
            foreach ($disk->directories($authorPath) as $doujinPath) {
                $entries[] = ['author'=>$author, 'title'=>basename($doujinPath), 'path'=>$doujinPath];
            }
        }
        if (!$entries) return back()->with('status', 'No doujin folders found.');

        $mediaByTitle = [];
        Media::where('type','doujin')->get(['id','title_romaji','title_english','slug'])->each(function ($m) use (&$mediaByTitle) {
            foreach ([$m->title_romaji, $m->title_english, $m->slug] as $t) {
                if ($t) $mediaByTitle[$this->normKey($t)] = $m->id;
            }
        });

        $created=0; $updated=0; $failed=0;

        foreach ($entries as $e) {
            $key = $this->normKey($e['title']);
            $mediaId = $mediaByTitle[$key] ?? null;

            if (!$mediaId) {
                try {
                    $m = new Media();
                    $m->type          = 'doujin';
                    $m->title_romaji  = $e['title'];
                    $m->slug          = Str::slug($e['title']);
                    $m->publisher     = [$e['author']];
                    $m->cover_url     = null;
                    $m->chapters_cnt  = 0;

                    if (\Schema::hasColumn('media', 'isNsfw')) {
                        $m->isNsfw = 1;
                    }

                    $m->save();
                    $mediaId = $m->id;
                    $mediaByTitle[$key] = $mediaId;
                    $created++;
                } catch (\Throwable $ex) { $failed++; continue; }
            }

            try {
                DB::transaction(function () use ($disk, $e, $mediaId) {
                    $this->mirrorDoujin($disk, $e['path'], $mediaId);
                });
                $updated++;
            } catch (\Throwable $ex) { $failed++; }
        }

        $msg = "Sync complete — created: {$created}, updated: {$updated}" . ($failed ? ", failed: {$failed}" : '');
        return back()->with($failed ? 'error' : 'status', $msg);
    }

    private function mirrorDoujin($disk, string $doujinPath, int $mediaId): void
    {
        $chapterDirs = $disk->directories($doujinPath);
        if (!$chapterDirs) $chapterDirs = [$doujinPath];
        usort($chapterDirs, 'strnatcasecmp');

        $desired = [];
        foreach ($chapterDirs as $idx => $chPath) {
            $title  = basename($chPath);
            $num    = $this->parseChapterNumber($title) ?? (float)($idx + 1);
            $numKey = $this->normNum($num);

            $files  = $disk->files($chPath);
            $images = array_values(array_filter($files, fn($f) => $this->isImage($f)));
            usort($images, 'strnatcasecmp');

            $desired[$numKey] = ['title'=>$title, 'images'=>$images];
        }

        $existing = Chapter::where('media_fk', $mediaId)
            ->get(['id','chapter_number','chapter_title'])
            ->keyBy(fn($c) => $this->normNum((float)$c->chapter_number));

        $toDropKeys = array_diff(array_keys($existing->all()), array_keys($desired));
        if ($toDropKeys) {
            $dropIds = $existing->only($toDropKeys)->pluck('id')->all();
            ChapterPage::whereIn('chapter_id', $dropIds)->delete();
            Chapter::whereIn('id', $dropIds)->delete();
            foreach ($toDropKeys as $k) unset($existing[$k]);
        }

        $firstCover = null;

        foreach ($desired as $numKey => $info) {
            if (!isset($existing[$numKey])) {
                $chapter = Chapter::create([
                    'media_fk'       => $mediaId,
                    'item_type'      => 'doujin',
                    'item_id'        => $mediaId,
                    'chapter_number' => $numKey,
                    'chapter_title'  => $info['title'],
                ]);
                $existing[$numKey] = $chapter;
            } else {
                $chapter = $existing[$numKey];
                if (trim($chapter->chapter_title) !== trim($info['title'])) {
                    $chapter->chapter_title = $info['title'];
                    $chapter->save();
                }
            }

            $this->mirrorPages($disk, $chapter->id, $info['images']);

            if ($firstCover === null && !empty($info['images'])) {
                $firstCover = ltrim($info['images'][0], '/');
            }
        }

        $emptyChapterIds = Chapter::where('media_fk', $mediaId)
            ->whereDoesntHave('pages')
            ->pluck('id')
            ->all();
        if ($emptyChapterIds) {
            Chapter::whereIn('id', $emptyChapterIds)->delete();
        }

        $media = Media::find($mediaId);
        if ($media) {
            if (!$media->cover_url && $firstCover) $media->cover_url = $firstCover;
            $media->chapters_cnt = Chapter::where('media_fk', $mediaId)->count();
            $media->save();
        }

        $chapters = Chapter::where('media_fk', $mediaId)->get(['id','chapter_title']);
        foreach ($chapters as $ch) {
            $pages = ChapterPage::where('chapter_id', $ch->id)->get(['id','file_path']);
            if ($pages->isEmpty()) {
                Chapter::where('id', $ch->id)->delete();
                continue;
            }

            $allMissing = true;
            foreach ($pages as $p) {
                if (Storage::disk('public')->exists($p->file_path)) { $allMissing = false; break; }
            }
            if ($allMissing) {
                ChapterPage::where('chapter_id', $ch->id)->delete();
                Chapter::where('id', $ch->id)->delete();
                continue;
            }

            $first = $pages->first()->file_path;
            $dir   = preg_replace('#/[^/]+$#', '', $first) ?: '';
            if ($dir !== '' && !Storage::disk('public')->exists($dir)) {
                ChapterPage::where('chapter_id', $ch->id)->delete();
                Chapter::where('id', $ch->id)->delete();
                continue;
            }

            $danglingIds = [];
            foreach ($pages as $p) {
                if (!Storage::disk('public')->exists($p->file_path)) $danglingIds[] = $p->id;
            }
            if ($danglingIds) {
                ChapterPage::whereIn('id', $danglingIds)->delete();
                if (!ChapterPage::where('chapter_id', $ch->id)->exists()) {
                    Chapter::where('id', $ch->id)->delete();
                }
            }
        }
    }

    private function mirrorPages($disk, int $chapterId, array $images): void
    {
        $desired = [];
        foreach ($images as $i => $rel) $desired[$i+1] = ltrim($rel, '/');

        $existing = ChapterPage::where('chapter_id', $chapterId)
            ->get(['id','page_number','file_path'])
            ->keyBy('page_number');

        $toDeleteNums = array_diff(array_keys($existing->all()), array_keys($desired));
        if ($toDeleteNums) {
            ChapterPage::where('chapter_id', $chapterId)->whereIn('page_number', $toDeleteNums)->delete();
            foreach ($toDeleteNums as $n) unset($existing[$n]);
        }

        $danglingIds = [];
        foreach ($existing as $num => $row) {
            if (!$disk->exists($row->file_path)) {
                $danglingIds[] = $row->id;
                unset($existing[$num]);
            }
        }
        if ($danglingIds) {
            ChapterPage::whereIn('id', $danglingIds)->delete();
        }

        $insert = [];
        foreach ($desired as $num => $path) {
            if (!isset($existing[$num])) {
                $insert[] = [
                    'chapter_id'  => $chapterId,
                    'page_number' => $num,
                    'file_path'   => $path,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }
        }
        if ($insert) ChapterPage::insert($insert);

        foreach ($desired as $num => $path) {
            if (isset($existing[$num]) && $existing[$num]->file_path !== $path) {
                ChapterPage::where('id', $existing[$num]->id)
                    ->update(['file_path' => $path, 'updated_at' => now()]);
            }
        }
    }

    private function parseChapterNumber(string $name): ?float
    {
        if (preg_match('/(\d+(?:[\._]\d+)?)/', strtolower($name), $m)) {
            $n = strtr($m[1], ['_' => '.', ',' => '.']);
            return (float)$n;
        }
        return null;
    }
    private function normNum(float $n): string { return number_format($n, 2, '.', ''); }
    private function normKey(string $s): string { return trim(mb_strtolower($s)); }

    public function pages()
    {
        return $this->hasMany(ChapterPage::class, 'chapter_id');
    }
    private function isImage(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
    }
}
