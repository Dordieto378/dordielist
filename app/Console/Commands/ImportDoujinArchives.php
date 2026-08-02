<?php

namespace App\Console\Commands;

use App\Models\DoujinAuthor;
use App\Models\Media;
use App\Support\MediaMetadataSyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class ImportDoujinArchives extends Command
{
    protected $signature = 'doujin:import-archives
                            {source=Artist : Folder containing one directory per author and ZIP files inside}
                            {--dry-run : Validate the collection without changing files or the database}';

    protected $description = 'Import <author>/<title>.zip collections as doujins, leaving author social links unchanged.';

    public function __construct(
        private readonly MediaMetadataSyncer $metadataSyncer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $source = $this->resolveSourcePath((string) $this->argument('source'));
        if ($source === null) {
            $this->error('Source folder not found: '.(string) $this->argument('source'));

            return self::FAILURE;
        }

        $archives = $this->findArchives($source);
        if ($archives === []) {
            $this->warn("No ZIP archives found below {$source}.");

            return self::SUCCESS;
        }

        $existingKeys = $this->existingAuthorTitleKeys();
        $fallbackValidation = null;

        if (!class_exists(ZipArchive::class) && PHP_OS_FAMILY === 'Windows') {
            try {
                $fallbackValidation = $this->validateArchiveCollectionWithPowerShell($source);
            } catch (Throwable $exception) {
                $this->error('Could not validate the archive collection: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        $validated = [];
        $validationErrors = [];
        $skippable = 0;
        $totalImages = 0;

        $this->info('Validating '.count($archives).' archive(s)...');

        foreach ($archives as $archive) {
            $key = $this->authorTitleKey($archive['author'], $archive['title']);

            if (isset($existingKeys[$key])) {
                $archive['existing_media_id'] = $existingKeys[$key];
                $validated[] = $archive;
                $skippable++;
                continue;
            }

            try {
                $archive += $fallbackValidation === null
                    ? $this->validateArchive($archive['path'])
                    : $this->validationResultFor($archive['path'], $fallbackValidation);
                $totalImages += $archive['images'];
                $validated[] = $archive;
            } catch (Throwable $exception) {
                $validationErrors[] = $archive['author'].' / '.$archive['title'].': '.$exception->getMessage();
            }
        }

        if ($validationErrors !== []) {
            foreach ($validationErrors as $error) {
                $this->error($error);
            }

            $this->error('Validation failed. Nothing was imported.');

            return self::FAILURE;
        }

        $toImport = count($validated) - $skippable;
        $this->line(
            'Validation complete: '
            .count($validated).' valid, '
            .$toImport.' new, '
            .$skippable.' already imported, '
            .number_format($totalImages).' image(s).'
        );

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run complete. No files or database records were changed.');

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $position = 0;

        foreach ($validated as $archive) {
            $position++;
            $label = $archive['author'].' / '.$archive['title'];

            if (isset($archive['existing_media_id'])) {
                $skipped++;
                $this->line("[{$position}/".count($validated)."] Skipped {$label} (media {$archive['existing_media_id']})");
                continue;
            }

            try {
                $media = $this->importArchive($archive);
                $existingKeys[$this->authorTitleKey($archive['author'], $archive['title'])] = $media->id;
                $imported++;
                $this->info(
                    "[{$position}/".count($validated)."] Imported {$label} "
                    ."(media {$media->id}, {$media->chapters_cnt} chapter(s))"
                );
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->error("[{$position}/".count($validated)."] Failed {$label}: {$exception->getMessage()}");
            }
        }

        $this->line("Archive import complete: imported={$imported}, skipped={$skipped}, failed={$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveSourcePath(string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return null;
        }

        $candidate = $this->isAbsolutePath($source)
            ? $source
            : base_path($source);

        $resolved = realpath($candidate);

        return $resolved !== false && File::isDirectory($resolved)
            ? $resolved
            : null;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('#^(?:[A-Za-z]:[\\\\/]|[\\\\/]{2}|/)#', $path) === 1;
    }

    private function findArchives(string $source): array
    {
        $archives = [];
        $authorDirectories = File::directories($source);
        natcasesort($authorDirectories);

        foreach ($authorDirectories as $authorDirectory) {
            $author = trim(basename($authorDirectory));
            if ($author === '') {
                continue;
            }

            $files = File::files($authorDirectory);
            usort($files, fn ($left, $right) => strnatcasecmp($left->getFilename(), $right->getFilename()));

            foreach ($files as $file) {
                if (strtolower($file->getExtension()) !== 'zip') {
                    continue;
                }

                $title = trim(pathinfo($file->getFilename(), PATHINFO_FILENAME));
                if ($title === '') {
                    continue;
                }

                $archives[] = [
                    'author' => $author,
                    'title' => $title,
                    'path' => $file->getRealPath(),
                ];
            }
        }

        return $archives;
    }

    private function validateArchiveCollectionWithPowerShell(string $source): array
    {
        $script = base_path('scripts/Validate-DoujinArchives.ps1');
        if (!File::isFile($script)) {
            throw new RuntimeException("Validation script not found: {$script}");
        }

        $process = new Process([
            'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $script,
            '-Source',
            $source,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            $details = trim($process->getErrorOutput().' '.$process->getOutput());
            throw new RuntimeException($details !== '' ? $details : 'Archive validation failed.');
        }

        $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        $results = [];

        foreach ($decoded as $result) {
            $results[$this->normalizedFilesystemPath((string) ($result['path'] ?? ''))] = $result;
        }

        return $results;
    }

    private function validationResultFor(string $path, array $results): array
    {
        $result = $results[$this->normalizedFilesystemPath($path)] ?? null;
        if ($result === null) {
            throw new RuntimeException('The archive was not included in the validation result.');
        }

        $error = trim((string) ($result['error'] ?? ''));
        if ($error !== '') {
            throw new RuntimeException($error);
        }

        return [
            'images' => (int) ($result['images'] ?? 0),
            'chapters' => (int) ($result['chapters'] ?? 0),
        ];
    }

    private function normalizedFilesystemPath(string $path): string
    {
        return mb_strtolower(str_replace('\\', '/', trim($path)));
    }

    private function existingAuthorTitleKeys(): array
    {
        $keys = [];

        Media::with('doujinAuthors:id,name')
            ->where('type', 'doujin')
            ->get(['id', 'title_english'])
            ->each(function (Media $media) use (&$keys) {
                foreach ($media->doujinAuthors as $author) {
                    $keys[$this->authorTitleKey($author->name, (string) $media->title_english)] = $media->id;
                }
            });

        return $keys;
    }

    private function authorTitleKey(string $author, string $title): string
    {
        return mb_strtolower(trim($author))."\0".mb_strtolower(trim($title));
    }

    private function validateArchive(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            return $this->validateArchiveWithPhar($path);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new RuntimeException('Could not open the ZIP archive.');
        }

        try {
            $images = 0;
            $chapters = [];
            $seenPaths = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

                if ($name === '' || $this->isUnsafeArchivePath($name)) {
                    throw new RuntimeException("Unsafe archive entry: {$name}");
                }

                $normalizedPath = mb_strtolower(rtrim($name, '/'));
                if ($normalizedPath !== '') {
                    if (isset($seenPaths[$normalizedPath])) {
                        throw new RuntimeException("Duplicate archive entry: {$name}");
                    }
                    $seenPaths[$normalizedPath] = true;
                }

                if (str_ends_with($name, '/')) {
                    continue;
                }

                $this->countArchiveImage($name, $images, $chapters);
            }

            if ($images === 0 || $chapters === []) {
                throw new RuntimeException('The archive does not contain any chapter images.');
            }

            return [
                'images' => $images,
                'chapters' => count($chapters),
            ];
        } finally {
            $zip->close();
        }
    }

    private function validateArchiveWithPhar(string $path): array
    {
        $archive = new PharData($path);
        $archivePath = str_replace('\\', '/', realpath($path) ?: $path);
        $prefix = 'phar://'.$archivePath.'/';
        $images = 0;
        $chapters = [];
        $seenPaths = [];

        foreach (new RecursiveIteratorIterator($archive) as $entryPath => $file) {
            if ($file->isDir()) {
                continue;
            }

            $entryPath = str_replace('\\', '/', (string) $entryPath);
            $name = str_starts_with($entryPath, $prefix)
                ? substr($entryPath, strlen($prefix))
                : $entryPath;

            if ($name === '' || $this->isUnsafeArchivePath($name)) {
                throw new RuntimeException("Unsafe archive entry: {$name}");
            }

            $normalizedPath = mb_strtolower(rtrim($name, '/'));
            if (isset($seenPaths[$normalizedPath])) {
                throw new RuntimeException("Duplicate archive entry: {$name}");
            }
            $seenPaths[$normalizedPath] = true;

            $this->countArchiveImage($name, $images, $chapters);
        }

        if ($images === 0 || $chapters === []) {
            throw new RuntimeException('The archive does not contain any chapter images.');
        }

        return [
            'images' => $images,
            'chapters' => count($chapters),
        ];
    }

    private function countArchiveImage(string $name, int &$images, array &$chapters): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return;
        }

        $segments = array_values(array_filter(explode('/', trim($name, '/')), fn ($part) => $part !== ''));
        if (count($segments) !== 2) {
            throw new RuntimeException(
                "Images must be inside one chapter folder; invalid entry: {$name}"
            );
        }

        $chapters[mb_strtolower($segments[0])] = true;
        $images++;
    }

    private function isUnsafeArchivePath(string $path): bool
    {
        if (
            str_contains($path, "\0")
            || str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:/#', $path) === 1
        ) {
            return true;
        }

        return in_array('..', explode('/', $path), true);
    }

    private function importArchive(array $archive): Media
    {
        $media = null;
        $targetAbsolutePath = null;
        $authorExisted = DoujinAuthor::where('name', $archive['author'])->exists();

        try {
            $media = DB::transaction(function () use ($archive) {
                $media = new Media();
                $media->type = 'doujin';
                $media->title_english = $archive['title'];
                $media->title_romaji = null;
                $media->title_native = null;
                $media->slug = $this->makeUniqueMediaSlug($archive['title']);
                $media->cover_url = null;
                $media->chapters_cnt = 0;
                $media->save();

                $this->metadataSyncer->syncDoujin($media, [$archive['author']]);

                return $media;
            });

            $targetRelativePath = 'doujin/'.$media->id;
            $disk = Storage::disk('public');
            $targetAbsolutePath = $disk->path($targetRelativePath);

            if (File::exists($targetAbsolutePath)) {
                throw new RuntimeException("Target folder already exists: {$targetRelativePath}");
            }

            File::ensureDirectoryExists($targetAbsolutePath);
            $this->extractArchive($archive['path'], $targetAbsolutePath);

            $exitCode = Artisan::call('doujin:import', [
                'mediaId' => $media->id,
                '--path' => $targetRelativePath,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $details = trim(Artisan::output());
                throw new RuntimeException($details !== '' ? $details : 'The extracted pages could not be imported.');
            }

            $media->refresh();
            if ((int) $media->chapters_cnt < 1) {
                throw new RuntimeException('The extracted archive did not produce any chapters.');
            }

            return $media;
        } catch (Throwable $exception) {
            if ($targetAbsolutePath !== null && File::isDirectory($targetAbsolutePath)) {
                File::deleteDirectory($targetAbsolutePath);
            }

            if ($media?->exists) {
                $media->delete();
            }

            if (!$authorExisted) {
                $author = DoujinAuthor::where('name', $archive['author'])->first();
                if ($author && !$author->media()->exists()) {
                    $author->delete();
                }
            }

            throw $exception;
        }
    }

    private function extractArchive(string $archivePath, string $targetPath): void
    {
        if (!class_exists(ZipArchive::class)) {
            $script = base_path('scripts/Expand-DoujinArchive.ps1');
            if (!File::isFile($script)) {
                throw new RuntimeException("Extraction script not found: {$script}");
            }

            $process = new Process([
                'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe',
                '-NoProfile',
                '-NonInteractive',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                $script,
                '-Source',
                $archivePath,
                '-Destination',
                $targetPath,
            ]);
            $process->setTimeout((float) config('filesystems.archive_extract_timeout', 600));
            $process->run();

            if (!$process->isSuccessful()) {
                $details = trim($process->getErrorOutput().' '.$process->getOutput());
                throw new RuntimeException($details !== '' ? $details : 'Could not extract the ZIP archive.');
            }

            return;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($archivePath);

        if ($opened !== true) {
            throw new RuntimeException('Could not reopen the ZIP archive for extraction.');
        }

        try {
            if (!$zip->extractTo($targetPath)) {
                throw new RuntimeException('Could not extract the ZIP archive.');
            }
        } finally {
            $zip->close();
        }
    }

    private function makeUniqueMediaSlug(string $title): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'doujin';
        }

        $slug = $base;
        $suffix = 2;

        while (Media::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
