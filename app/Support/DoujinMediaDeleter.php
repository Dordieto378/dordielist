<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DoujinMediaDeleter
{
    public function __construct(
        private readonly DoujinFolderIndex $folderIndex,
    ) {
    }

    public function delete(Media $media): void
    {
        $this->deleteMany([$media]);
    }

    public function deleteMany(iterable $mediaItems): int
    {
        $items = [];

        foreach ($mediaItems as $media) {
            if ($media instanceof Media && $media->type === 'doujin') {
                $items[] = $media;
            }
        }

        if ($items === []) {
            return 0;
        }

        $disk = Storage::disk('public');
        $entries = $disk->exists('doujin')
            ? $this->folderIndex->scanDisk($disk, 'doujin')
            : [];

        $deleted = 0;

        foreach ($items as $media) {
            $this->deleteWithEntries($media, $disk, $entries);
            $deleted++;
        }

        return $deleted;
    }

    private function deleteWithEntries(Media $media, FilesystemAdapter $disk, array $entries): void
    {
        $pathsToDelete = [];

        $media->loadMissing('doujinAuthors:id,name');
        $entry = $this->folderIndex->findEntryForMedia($media, $entries);

        if ($entry && !empty($entry['path'])) {
            $pathsToDelete[] = trim((string) $entry['path'], '/');
        }

        $pathsToDelete[] = 'doujin/'.$media->id;
        $pathsToDelete = array_values(array_unique(array_filter($pathsToDelete)));

        $authorPath = null;
        if ($entry && !empty($entry['author'])) {
            $authorPath = 'doujin/'.trim((string) $entry['author'], '/');
        }

        $media->delete();

        foreach ($pathsToDelete as $path) {
            if (!$disk->exists($path)) {
                continue;
            }

            $absolutePath = $disk->path($path);

            if (File::isDirectory($absolutePath)) {
                File::deleteDirectory($absolutePath);
            } else {
                $disk->delete($path);
            }
        }

        if ($authorPath && $disk->exists($authorPath)) {
            if ($disk->directories($authorPath) === [] && $disk->files($authorPath) === []) {
                File::deleteDirectory($disk->path($authorPath));
            }
        }
    }
}
