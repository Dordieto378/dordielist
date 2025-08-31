<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\Doujin;
use App\Models\DoujinPage;

class ImportDoujinPages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --disk         Which filesystem disk to read from (e.g. "b2" or "public")
     * --path-prefix  The base prefix, default "doujins", so it reads from "doujins/{author}/{doujin}/"
     */
    protected $signature = 'doujins:import-pages
                            {--disk=b2 : Which filesystem disk to read from (e.g. "b2" or "public")}
                            {--path-prefix=doujins : The base folder prefix where doujin images live }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wipe and rebuild all DoujinPage rows from B2.';

    public function handle()
    {
        $disk   = $this->option('disk');         // e.g. "b2"
        $prefix = rtrim($this->option('path-prefix'), '/'); // e.g. "doujins"

        // 0) Truncate the entire doujin_pages table before re‐importing
        $this->info("⏳ Truncating `doujin_pages` table...");
        DoujinPage::truncate();
        $this->info("✅ doujin_pages table emptied.");

        $this->info("Starting import from disk '{$disk}', prefix '{$prefix}/'…");

        $doujins = Doujin::all();
        $bar = $this->output->createProgressBar($doujins->count());
        $bar->start();

        foreach ($doujins as $doujin) {
            $bar->advance();

            // Build the folder path on B2 (or local disk):
            //    doujins/{author_name}/{doujin_name}/
            //
            // IMPORTANT: this assumes your Doujin->author_name and ->doujin_name exactly
            // match the folder names on B2 (spaces, capitalization, etc.).
            $authorFolder = $doujin->author_name;
            $doujinFolder = $doujin->doujin_name;

            $folder = "{$prefix}/{$authorFolder}/{$doujinFolder}/";

            // Check if that folder exists on the disk:
            if (! Storage::disk($disk)->exists($folder)) {
                $this->warn("\n[Warning] Folder '{$folder}' not found (Doujin ID {$doujin->id}). Skipping.");
                continue;
            }

            // List all “files” (not subdirectories) in that folder:
            $allPaths = Storage::disk($disk)->files($folder);

            if (count($allPaths) === 0) {
                $this->warn("\n[Notice] No image files found in '{$folder}' for Doujin ID {$doujin->id}.");
                continue;
            }

            // Sort them “naturally” so that 1.jpg < 2.jpg < … < 10.jpg
            natsort($allPaths);
            $sortedPaths = collect($allPaths)->values();

            // Loop through each file, assign a page number, and insert:
            foreach ($sortedPaths as $index => $path) {
                $pageNumber = $index + 1;

                DoujinPage::create([
                    'doujin_id'   => $doujin->id,
                    'page_number' => $pageNumber,
                    'file_path'   => $path,
                ]);
            }
        }

        $bar->finish();
        $this->info("\nImport complete! All doujin_pages have been rebuilt.");
        return 0;
    }
}
