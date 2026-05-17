<?php

namespace App\Console\Commands;

use App\Models\Episode;
use App\Models\Media;
use App\Support\EpisodeThumbnailer;
use Illuminate\Console\Command;

class GenerateEpisodeThumbnails extends Command
{
    protected $signature = 'episodes:thumbnails {--media= : Limit to a single media id} {--force : Regenerate even when thumbnail exists}';

    protected $description = 'Generate static thumbnail images for episode cards';

    public function handle(): int
    {
        $mediaId = $this->option('media');
        $force = (bool) $this->option('force');

        $query = Episode::query()->orderBy('id');
        if ($mediaId !== null && $mediaId !== '') {
            $query->where('media_fk', (int) $mediaId);
        }
        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('thumbnail_path')->orWhere('thumbnail_path', '');
            });
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No episodes need thumbnail generation.');
            return self::SUCCESS;
        }

        $this->info("Generating thumbnails for {$total} episode(s)...");

        $generated = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById(100, function ($episodes) use (&$generated, &$failed, $force, $bar) {
            $mediaById = Media::whereIn('id', $episodes->pluck('media_fk')->filter()->unique())
                ->get()
                ->keyBy('id');

            foreach ($episodes as $ep) {
                $media = $mediaById->get($ep->media_fk);

                $thumb = EpisodeThumbnailer::generate(
                    $media ?: (int) $ep->media_fk,
                    (int) $ep->episode_number,
                    (string) $ep->file_path,
                    $force
                );

                if ($thumb) {
                    $ep->thumbnail_path = $thumb;
                    $ep->save();
                    $generated++;
                } else {
                    $failed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. generated={$generated}, failed={$failed}");

        return self::SUCCESS;
    }
}
