<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class VnLaunchController extends Controller
{
    public function detect(Media $media)
    {
        abort_unless($media->type === 'vn', 404);

        $baseDir = storage_path('app/public/games/'.(int)$media->id);
        if (!is_dir($baseDir)) {
            return back()->with('error', "Game folder not found: {$baseDir}");
        }

        $candidates = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            if ($f->isFile() && Str::endsWith(Str::lower($f->getFilename()), '.exe')) {
                $rel = ltrim(str_replace(['\\','/'], DIRECTORY_SEPARATOR, Str::after($f->getPathname(), $baseDir)), DIRECTORY_SEPARATOR);
                $candidates[] = $rel;
            }
        }

        if (empty($candidates)) {
            return back()->with('error', 'No .exe found under game folder.');
        }

        $pick = collect($candidates)
            ->sortBy(fn($p) => strlen($p))
            ->first();

        $media->launch_rel_exe = $pick;
        $media->save();

        return back()->with('status', "Detected launcher: {$pick}");
    }

    public function launch(Media $media)
    {
        abort_unless($media->type === 'vn', 404);

        $rel = $media->launch_rel_exe;
        if (!$rel) {
            return back()->with('error', 'No launcher saved. Click "Add Game Files" first.');
        }

        $baseDir = storage_path('app/public/games/'.(int)$media->id);
        $exe     = $baseDir.DIRECTORY_SEPARATOR.str_replace(['\\','/'], DIRECTORY_SEPARATOR, $rel);

        if (!file_exists($exe)) {
            return back()->with('error', "Launcher not found:\n{$exe}");
        }

        $workdir = dirname($exe);
        $args = $media->launch_args ? preg_split('/\s+/', trim($media->launch_args)) : [];

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $cmd = 'start "" ' . escapeshellarg($exe);
                foreach ($args as $a) {
                    $cmd .= ' ' . escapeshellarg($a);
                }
                $process = Process::fromShellCommandline('cmd /c ' . $cmd, $workdir);
                $process->disableOutput();
                $process->run();
            } else {
                $parts = array_merge([escapeshellarg($exe)], array_map('escapeshellarg', $args));
                $cmd = 'nohup ' . implode(' ', $parts) . ' >/dev/null 2>&1 &';
                $process = Process::fromShellCommandline($cmd, $workdir);
                $process->disableOutput();
                $process->run();
            }
        } catch (\Throwable $e) {
            \Log::error('VN launch failed', ['id' => $media->id, 'exe' => $exe, 'err' => $e->getMessage()]);
            return back()->with('error', "Failed to launch:\n".$e->getMessage());
        }

        return back()->with('status', 'Launching game…');
    }

}
