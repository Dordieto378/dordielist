<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class UploadedArchive
{
    public function extractArchiveToTemporaryRoot(UploadedFile $archive, string $prefix = 'media-upload-'): string
    {
        $extractRoot = storage_path('app/tmp/'.$prefix.Str::uuid());
        File::ensureDirectoryExists($extractRoot);

        try {
            $this->extractArchive($archive, $extractRoot);

            return $extractRoot;
        } catch (\Throwable $e) {
            if (File::isDirectory($extractRoot)) {
                File::deleteDirectory($extractRoot);
            }

            throw $e;
        }
    }

    public function resolveContentRoot(string $extractRoot): string
    {
        $current = realpath($extractRoot) ?: $extractRoot;

        for ($depth = 0; $depth < 5; $depth++) {
            $childDirectories = $this->listDirectories($current);
            $visibleFiles = $this->listFiles($current);

            if ($visibleFiles !== [] || count($childDirectories) !== 1) {
                break;
            }

            $current = $childDirectories[0];
        }

        return $current;
    }

    public function listDirectories(string $path): array
    {
        $entries = glob($path.DIRECTORY_SEPARATOR.'*') ?: [];
        $directories = array_values(array_filter($entries, function ($entry) {
            return is_dir($entry) && $this->isVisibleName(basename($entry));
        }));

        natcasesort($directories);

        return array_values($directories);
    }

    public function listFiles(string $path): array
    {
        $entries = glob($path.DIRECTORY_SEPARATOR.'*') ?: [];
        $files = array_values(array_filter($entries, function ($entry) {
            return is_file($entry) && $this->isVisibleName(basename($entry));
        }));

        natcasesort($files);

        return array_values($files);
    }

    public function listImageFiles(string $path, bool $recursive = false): array
    {
        return $this->listFilesByExtension($path, ['jpg', 'jpeg', 'png', 'webp', 'gif'], $recursive);
    }

    public function listVideoFiles(string $path, bool $recursive = false): array
    {
        return $this->listFilesByExtension($path, ['mp4', 'webm', 'mkv'], $recursive);
    }

    public function sanitizePathSegment(string $value, string $fallback): string
    {
        $segment = Str::slug($value);

        return $segment !== '' ? $segment : $fallback;
    }

    private function extractArchive(UploadedFile $archive, string $extractRoot): void
    {
        $workingZip = $extractRoot.DIRECTORY_SEPARATOR.'upload.zip';

        if (!@copy((string) $archive->getRealPath(), $workingZip)) {
            throw new \RuntimeException('Could not prepare the uploaded ZIP archive.');
        }

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $opened = $zip->open($workingZip);

            if ($opened !== true) {
                @unlink($workingZip);
                throw new \RuntimeException('Could not open the ZIP archive.');
            }

            if (!$zip->extractTo($extractRoot)) {
                $zip->close();
                @unlink($workingZip);
                throw new \RuntimeException('Could not extract the ZIP archive.');
            }

            $zip->close();
            @unlink($workingZip);

            return;
        }

        $timeout = $this->archiveExtractionTimeout();
        $tarPath = 'C:\Windows\System32\tar.exe';

        if (is_file($tarPath)) {
            $process = new Process([
                $tarPath,
                '-xf',
                $workingZip,
                '-C',
                $extractRoot,
            ]);
            $process->setTimeout($timeout);
            $process->run();

            if ($process->isSuccessful()) {
                @unlink($workingZip);

                return;
            }
        }

        $process = new Process([
            'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            sprintf(
                "Expand-Archive -LiteralPath '%s' -DestinationPath '%s' -Force",
                str_replace("'", "''", $workingZip),
                str_replace("'", "''", $extractRoot)
            ),
        ]);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($workingZip);
            $errorOutput = trim($process->getErrorOutput().' '.$process->getOutput());

            throw new \RuntimeException($errorOutput !== '' ? $errorOutput : 'Could not extract the ZIP archive.');
        }

        @unlink($workingZip);
    }

    private function archiveExtractionTimeout(): ?int
    {
        $timeout = (int) config('filesystems.archive_extract_timeout', 600);

        return $timeout > 0 ? $timeout : null;
    }

    private function listFilesByExtension(string $path, array $extensions, bool $recursive): array
    {
        $files = $recursive ? $this->listFilesRecursively($path) : $this->listFiles($path);
        $extensionMap = array_fill_keys(array_map('strtolower', $extensions), true);

        $filtered = array_values(array_filter($files, function ($file) use ($extensionMap) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            return isset($extensionMap[$ext]);
        }));

        natcasesort($filtered);

        return array_values($filtered);
    }

    private function listFilesRecursively(string $path): array
    {
        $files = $this->listFiles($path);

        foreach ($this->listDirectories($path) as $directory) {
            $files = array_merge($files, $this->listFilesRecursively($directory));
        }

        natcasesort($files);

        return array_values($files);
    }

    private function isVisibleName(string $name): bool
    {
        return $name !== '__MACOSX' && !str_starts_with($name, '.');
    }
}
