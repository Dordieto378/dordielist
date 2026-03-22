<?php

namespace App\Http\Controllers;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Media;
use App\Support\MediaMetadataSyncer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DoujinController extends Controller
{
    public function __construct(private readonly MediaMetadataSyncer $metadataSyncer)
    {
    }

    public function show(int $mediaId)
    {
        $media = Media::with('doujinAuthors:id,name')
            ->where('type', 'doujin')
            ->findOrFail($mediaId);

        $chapters = Chapter::where('item_type', 'doujin')
            ->where('media_fk', $media->id)
            ->orderBy('chapter_number')
            ->get(['id', 'chapter_number', 'chapter_title']);

        $chaptersView = $chapters->map(function (Chapter $chapter) {
            $pages = ChapterPage::where('chapter_id', $chapter->id)
                ->orderBy('page_number')
                ->get(['id', 'page_number', 'file_path']);

            return [
                'id' => $chapter->id,
                'number' => $chapter->chapter_number,
                'title' => $chapter->chapter_title,
                'pages' => $pages->map(fn ($page) => [
                    'id' => $page->id,
                    'num' => $page->page_number,
                    'url' => Storage::url($page->file_path),
                    'path' => $page->file_path,
                ])->values()->all(),
            ];
        });

        $coverUrl = $media->cover_url ? Storage::url($media->cover_url) : asset('images/no-image.jpg');

        $isFavorited = \App\Models\Favorite::where([
            ['favoritable_type', 'doujins'],
            ['favoritable_id', $media->id],
        ])->exists();

        $allCollections = \App\Models\Collection::orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();

        $attachedIds = \App\Models\CollectionItem::where('item_type', 'doujins')
            ->where('item_id', $media->id)
            ->pluck('collection_id')
            ->toArray();

        return view('media.doujin', [
            'media' => $media,
            'coverUrl' => $coverUrl,
            'chapters' => $chaptersView,
            'isFavorited' => $isFavorited,
            'allCollections' => $allCollections,
            'attachedIds' => $attachedIds,
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
                $entries[] = ['author' => $author, 'title' => basename($doujinPath), 'path' => $doujinPath];
            }
        }
        if (!$entries) {
            return back()->with('status', 'No doujin folders found.');
        }

        $mediaByTitle = [];
        Media::where('type', 'doujin')
            ->get(['id', 'title_romaji', 'title_english', 'slug'])
            ->each(function ($media) use (&$mediaByTitle) {
                foreach ([$media->title_romaji, $media->title_english, $media->slug] as $title) {
                    if ($title) {
                        $mediaByTitle[$this->normKey($title)] = $media->id;
                    }
                }
            });

        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            $key = $this->normKey($entry['title']);
            $mediaId = $mediaByTitle[$key] ?? null;

            if (!$mediaId) {
                try {
                    $media = new Media();
                    $media->type = 'doujin';
                    $media->title_romaji = $entry['title'];
                    $media->slug = Str::slug($entry['title']);
                    $media->cover_url = null;
                    $media->chapters_cnt = 0;

                    if (\Schema::hasColumn('media', 'isNsfw')) {
                        $media->isNsfw = 1;
                    }

                    $media->save();
                    $this->metadataSyncer->syncDoujin($media, [$entry['author']]);

                    $mediaId = $media->id;
                    $mediaByTitle[$key] = $mediaId;
                    $created++;
                } catch (\Throwable $ex) {
                    $failed++;
                    continue;
                }
            }

            try {
                DB::transaction(function () use ($disk, $entry, $mediaId) {
                    $media = Media::findOrFail($mediaId);
                    $this->metadataSyncer->syncDoujin($media, [$entry['author']]);
                    $this->mirrorDoujin($disk, $entry['path'], $mediaId);
                });
                $updated++;
            } catch (\Throwable $ex) {
                $failed++;
            }
        }

        $msg = "Sync complete - created: {$created}, updated: {$updated}".($failed ? ", failed: {$failed}" : '');
        return back()->with($failed ? 'error' : 'status', $msg);
    }

    private function mirrorDoujin($disk, string $doujinPath, int $mediaId): void
    {
        $chapterDirs = $disk->directories($doujinPath);
        if (!$chapterDirs) {
            $chapterDirs = [$doujinPath];
        }
        usort($chapterDirs, 'strnatcasecmp');

        $desired = [];
        foreach ($chapterDirs as $idx => $chapterPath) {
            $title = basename($chapterPath);
            $number = $this->parseChapterNumber($title) ?? (float) ($idx + 1);
            $numberKey = $this->normNum($number);

            $files = $disk->files($chapterPath);
            $images = array_values(array_filter($files, fn ($file) => $this->isImage($file)));
            usort($images, 'strnatcasecmp');

            $desired[$numberKey] = ['title' => $title, 'images' => $images];
        }

        $existing = Chapter::where('media_fk', $mediaId)
            ->get(['id', 'chapter_number', 'chapter_title'])
            ->keyBy(fn ($chapter) => $this->normNum((float) $chapter->chapter_number));

        $toDropKeys = array_diff(array_keys($existing->all()), array_keys($desired));
        if ($toDropKeys) {
            $dropIds = $existing->only($toDropKeys)->pluck('id')->all();
            ChapterPage::whereIn('chapter_id', $dropIds)->delete();
            Chapter::whereIn('id', $dropIds)->delete();
            foreach ($toDropKeys as $dropKey) {
                unset($existing[$dropKey]);
            }
        }

        $firstCover = null;

        foreach ($desired as $numberKey => $info) {
            if (!isset($existing[$numberKey])) {
                $chapter = Chapter::create([
                    'media_fk' => $mediaId,
                    'item_type' => 'doujin',
                    'item_id' => $mediaId,
                    'chapter_number' => $numberKey,
                    'chapter_title' => $info['title'],
                ]);
                $existing[$numberKey] = $chapter;
            } else {
                $chapter = $existing[$numberKey];
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
            if (!$media->cover_url && $firstCover) {
                $media->cover_url = $firstCover;
            }
            $media->chapters_cnt = Chapter::where('media_fk', $mediaId)->count();
            $media->save();
        }

        $chapters = Chapter::where('media_fk', $mediaId)->get(['id', 'chapter_title']);
        foreach ($chapters as $chapter) {
            $pages = ChapterPage::where('chapter_id', $chapter->id)->get(['id', 'file_path']);
            if ($pages->isEmpty()) {
                Chapter::where('id', $chapter->id)->delete();
                continue;
            }

            $allMissing = true;
            foreach ($pages as $page) {
                if (Storage::disk('public')->exists($page->file_path)) {
                    $allMissing = false;
                    break;
                }
            }
            if ($allMissing) {
                ChapterPage::where('chapter_id', $chapter->id)->delete();
                Chapter::where('id', $chapter->id)->delete();
                continue;
            }

            $first = $pages->first()->file_path;
            $dir = preg_replace('#/[^/]+$#', '', $first) ?: '';
            if ($dir !== '' && !Storage::disk('public')->exists($dir)) {
                ChapterPage::where('chapter_id', $chapter->id)->delete();
                Chapter::where('id', $chapter->id)->delete();
                continue;
            }

            $danglingIds = [];
            foreach ($pages as $page) {
                if (!Storage::disk('public')->exists($page->file_path)) {
                    $danglingIds[] = $page->id;
                }
            }
            if ($danglingIds) {
                ChapterPage::whereIn('id', $danglingIds)->delete();
                if (!ChapterPage::where('chapter_id', $chapter->id)->exists()) {
                    Chapter::where('id', $chapter->id)->delete();
                }
            }
        }
    }

    private function mirrorPages($disk, int $chapterId, array $images): void
    {
        $desired = [];
        foreach ($images as $index => $rel) {
            $desired[$index + 1] = ltrim($rel, '/');
        }

        $existing = ChapterPage::where('chapter_id', $chapterId)
            ->get(['id', 'page_number', 'file_path'])
            ->keyBy('page_number');

        $toDeleteNums = array_diff(array_keys($existing->all()), array_keys($desired));
        if ($toDeleteNums) {
            ChapterPage::where('chapter_id', $chapterId)->whereIn('page_number', $toDeleteNums)->delete();
            foreach ($toDeleteNums as $number) {
                unset($existing[$number]);
            }
        }

        $danglingIds = [];
        foreach ($existing as $number => $row) {
            if (!$disk->exists($row->file_path)) {
                $danglingIds[] = $row->id;
                unset($existing[$number]);
            }
        }
        if ($danglingIds) {
            ChapterPage::whereIn('id', $danglingIds)->delete();
        }

        $insert = [];
        foreach ($desired as $number => $path) {
            if (!isset($existing[$number])) {
                $insert[] = [
                    'chapter_id' => $chapterId,
                    'page_number' => $number,
                    'file_path' => $path,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        if ($insert) {
            ChapterPage::insert($insert);
        }

        foreach ($desired as $number => $path) {
            if (isset($existing[$number]) && $existing[$number]->file_path !== $path) {
                ChapterPage::where('id', $existing[$number]->id)
                    ->update(['file_path' => $path, 'updated_at' => now()]);
            }
        }
    }

    private function parseChapterNumber(string $name): ?float
    {
        if (preg_match('/(\d+(?:[\._]\d+)?)/', strtolower($name), $matches)) {
            $number = strtr($matches[1], ['_' => '.', ',' => '.']);
            return (float) $number;
        }

        return null;
    }

    private function normNum(float $number): string
    {
        return number_format($number, 2, '.', '');
    }

    private function normKey(string $value): string
    {
        return trim(mb_strtolower($value));
    }

    private function isImage(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }
}
