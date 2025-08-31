<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Doujin;

class BuildDoujinsIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage: php artisan doujins:build-index
     */
    protected $signature = 'doujins:build-index';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan the Backblaze B2 "doujins" folder (via S3) and populate the doujins table (author, name, cover_url).';

    public function handle()
    {
        $this->info("→ Rebuilding the doujins index from B2…");

        // 1) TRUNCATE the doujins table so we rebuild from scratch:
        Doujin::truncate();
        $this->info("   – Cleared existing rows in `doujins` table.");

        $inserted = 0;

        //
        // 2) LIST ALL CONTENTS UNDER "doujins/" ON B2, THEN CONVERT TO A COLLECTION
        //
        $allContentsCollection = collect(
            Storage::disk('b2')->listContents('doujins', $recursive = true)
        );

        //
        // 3) FILTER ONLY FILES (skip any "dir" entries)
        //
        $fileEntries = $allContentsCollection
            ->filter(fn($item) => $item['type'] === 'file');

        //
        // 4) FILTER TO IMAGE EXTENSIONS (jpg, jpeg, png, gif, webp)
        //
        $imageEntries = $fileEntries->filter(function($item) {
            $ext = strtolower(pathinfo($item['path'], PATHINFO_EXTENSION));
            return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        });

        //
        // 5) GROUP IMAGE FILES BY THEIR DOUJIN FOLDER (dirname "doujins/AuthorName/DoujinName")
        //
        $byDoujinDir = $imageEntries
            ->groupBy(fn($item) => dirname($item['path']));

        //
        // 6) INSERT ONE ROW PER DIRECTORY THAT HAS IMAGES
        //
        foreach ($byDoujinDir as $doujinFolder => $imagesInThatFolder) {
            // Example: $doujinFolder = "doujins/Wakamatsu/A Boss Who's Totally Different on Weekdays and Weekends"
            $segments = explode('/', $doujinFolder);
            if (count($segments) < 3) {
                // If there's no author/cover subfolder (e.g. a file directly under "doujins/"), skip.
                continue;
            }

            // Remove the leading "doujins" segment, leaving ["AuthorName", "DoujinName", ...]
            array_shift($segments);
            $authorName = $segments[0];
            $doujinName = implode('/', array_slice($segments, 1));

            // Sort images by numeric filename (so "01.jpg" → 1, "02.png" → 2, etc.)
            $chosenCover = collect($imagesInThatFolder)
                ->sortBy(function($item) {
                    $filename = basename($item['path']);            // e.g. "01.jpg"
                    $nameOnly = pathinfo($filename, PATHINFO_FILENAME); // e.g. "01"
                    return intval(preg_replace('/\D/', '', $nameOnly)); // extract integer
                })
                ->first();

            if ($chosenCover) {
                // 6a) We found a valid image file under that folder
                $coverKey = $chosenCover['path']; 
            } else {
                // 6b) (Should not happen, because $byDoujinDir only has folders with at least one image)
                $coverKey = "images/no-image.jpg";
            }

            Doujin::create([
                'author_name' => $authorName,
                'doujin_name' => $doujinName,
                'folder'      => "{$authorName}/{$doujinName}",
                'cover_url'   => $coverKey,
            ]);

            $inserted++;
        }

        //
        // 7) HANDLE DIRECTORIES THAT CONTAIN ZERO IMAGES AT ALL
        //    (They won’t appear in $byDoujinDir, because $imageEntries filtered them out.)
        //
        //    First, find all "doujins/AuthorName/DoujinName" directories at depth=3.
        $allDirPaths = $allContentsCollection
            ->filter(fn($item) => $item['type'] === 'dir')
            ->pluck('path');

        $depth3DoujinDirs = $allDirPaths->filter(function($path) {
            $segments = explode('/', $path);
            // Exactly three segments: ["doujins", "AuthorName", "DoujinName"]
            return count($segments) === 3;
        });

        foreach ($depth3DoujinDirs as $doujinFolder) {
            if ($byDoujinDir->has($doujinFolder)) {
                // Already inserted above (because that folder had at least one image).
                continue;
            }

            // This folder had zero image files (only desktop.ini or other). Insert placeholder:
            $segments = explode('/', $doujinFolder);
            array_shift($segments);
            $authorName = $segments[0];
            $doujinName = implode('/', array_slice($segments, 1));

            Doujin::create([
                'author_name' => $authorName,
                'doujin_name' => $doujinName,
                'folder'      => "{$authorName}/{$doujinName}",
                'cover_url'   => "images/no-image.jpg",
            ]);

            $inserted++;
        }

        $this->info("→ Completed. Inserted {$inserted} doujins into the database.");
        return 0;
    }
}
