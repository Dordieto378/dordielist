<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\MediaMetadataSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class MigrateMediaMetadata extends Command
{
    protected $signature = 'media:migrate-metadata {--chunk=100 : Number of media rows to process per chunk}';

    protected $description = 'Backfill normalized metadata tables from the existing media JSON columns';

    public function __construct(private readonly MediaMetadataSyncer $metadataSyncer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $legacyColumns = $this->availableLegacyColumns();

        if ($legacyColumns === []) {
            $this->warn('Legacy metadata columns are no longer present on media. Nothing to backfill.');
            return self::SUCCESS;
        }

        $total = Media::count();

        if ($total === 0) {
            $this->info('No media rows found.');
            return self::SUCCESS;
        }

        $this->info("Backfilling metadata for {$total} media rows using chunks of {$chunkSize}.");

        $processed = 0;
        $synced = 0;
        $skipped = 0;

        Media::query()
            ->select(array_merge(['id', 'type'], $legacyColumns))
            ->orderBy('id')
            ->chunkById($chunkSize, function ($mediaRows) use (&$processed, &$synced, &$skipped): void {
                foreach ($mediaRows as $media) {
                    $didSync = $this->syncMediaRow($media);
                    $processed++;

                    if ($didSync) {
                        $synced++;
                    } else {
                        $skipped++;
                    }
                }

                $lastId = optional($mediaRows->last())->id;
                $this->line("Processed {$processed} rows so far (last media id: {$lastId}).");
            });

        $this->newLine();
        $this->info("Metadata backfill complete. processed={$processed}, synced={$synced}, skipped={$skipped}.");

        return self::SUCCESS;
    }

    private function syncMediaRow(Media $media): bool
    {
        $type = strtolower((string) $media->type);

        return match ($type) {
            'anime', 'hentai' => $this->syncAnilistMedia(
                $media,
                $this->decodeJsonArray($media, 'genres'),
                $this->decodeJsonArray($media, 'tags'),
                $this->decodeJsonArray($media, 'publisher'),
                null
            ),
            'manga', 'manwha' => $this->syncAnilistMedia(
                $media,
                $this->decodeJsonArray($media, 'genres'),
                $this->decodeJsonArray($media, 'tags'),
                null,
                $this->decodeJsonArray($media, 'publisher')
            ),
            'doujin' => $this->syncDoujinMedia($media, $this->decodeJsonArray($media, 'publisher')),
            'vn' => $this->syncVnMedia(
                $media,
                $this->decodeJsonArray($media, 'tags'),
                $this->decodeJsonArray($media, 'languages'),
                $this->decodeJsonArray($media, 'publisher')
            ),
            default => false,
        };
    }

    private function syncAnilistMedia(Media $media, ?array $genres, ?array $tags, ?array $studios, ?array $authors): bool
    {
        if ($genres === null && $tags === null && $studios === null && $authors === null) {
            return false;
        }

        $this->metadataSyncer->syncAnilist($media, $genres, $tags, $studios, $authors);

        return true;
    }

    private function syncDoujinMedia(Media $media, ?array $authors): bool
    {
        if ($authors === null) {
            return false;
        }

        $this->metadataSyncer->syncDoujin($media, $authors);

        return true;
    }

    private function syncVnMedia(Media $media, ?array $tags, ?array $languages, ?array $developers): bool
    {
        if ($tags === null && $languages === null && $developers === null) {
            return false;
        }

        $this->metadataSyncer->syncVn($media, $tags, $languages, $developers);

        return true;
    }

    private function decodeJsonArray(Media $media, string $field): ?array
    {
        if (!Schema::hasColumn('media', $field)) {
            return null;
        }

        $raw = $media->getAttribute($field);

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            $values = array_values(array_filter(array_map([$this, 'normalizeString'], $raw)));
            return $values === [] ? null : $values;
        }

        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $values = array_values(array_filter(array_map([$this, 'normalizeString'], $decoded)));

        return $values === [] ? null : $values;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function availableLegacyColumns(): array
    {
        return array_values(array_filter(
            ['genres', 'tags', 'publisher', 'languages'],
            fn (string $column) => Schema::hasColumn('media', $column)
        ));
    }
}
