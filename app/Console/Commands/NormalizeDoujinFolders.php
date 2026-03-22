<?php

namespace App\Console\Commands;

use App\Support\DoujinFolderIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class NormalizeDoujinFolders extends Command
{
    protected $signature = 'doujin:normalize-folders {--dry-run : Show planned renames without changing anything}';

    protected $description = 'Rename doujin folders to media ids under storage/app/public/doujin/<author>/<media_id>.';

    public function __construct(private readonly DoujinFolderIndex $folderIndex)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $root = 'doujin';

        if (!$disk->exists($root)) {
            $this->error("Folder not found: storage/app/public/{$root}");
            return self::FAILURE;
        }

        $entries = $this->folderIndex->scanDisk($disk, $root);
        if (!$entries) {
            $this->warn('No doujin folders found.');
            return self::SUCCESS;
        }

        $lookup = $this->folderIndex->buildMediaLookup();
        $dryRun = (bool) $this->option('dry-run');

        $renamed = 0;
        $alreadyNormalized = 0;
        $unmatched = 0;
        $collisions = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            $currentPath = $entry['path'];
            $currentFolder = $entry['folder'];

            if (!empty($entry['media_id']) && isset($lookup['by_id'][(int) $entry['media_id']])) {
                $alreadyNormalized++;
                continue;
            }

            $mediaId = $this->folderIndex->resolveMediaId($entry, $lookup);
            if (!$mediaId) {
                $this->warn("Unmatched: {$currentPath}");
                $unmatched++;
                continue;
            }

            $targetPath = dirname($currentPath).'/'.$mediaId;
            if ($targetPath === $currentPath) {
                $alreadyNormalized++;
                continue;
            }

            if ($disk->exists($targetPath)) {
                $this->warn("Collision: {$currentPath} -> {$targetPath}");
                $collisions++;
                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] {$currentFolder} -> {$mediaId}");
                $renamed++;
                continue;
            }

            $from = $disk->path($currentPath);
            $to = $disk->path($targetPath);

            if (!File::isDirectory($from)) {
                $this->warn("Missing directory: {$currentPath}");
                $failed++;
                continue;
            }

            if (!@rename($from, $to)) {
                $this->warn("Rename failed: {$currentPath} -> {$targetPath}");
                $failed++;
                continue;
            }

            $this->info("Renamed {$currentFolder} -> {$mediaId}");
            $renamed++;
        }

        $this->newLine();
        $this->line("Summary: renamed={$renamed}, already_normalized={$alreadyNormalized}, unmatched={$unmatched}, collisions={$collisions}, failed={$failed}");

        return ($unmatched || $collisions || $failed) ? self::FAILURE : self::SUCCESS;
    }
}
