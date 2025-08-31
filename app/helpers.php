<?php

use Illuminate\Support\Facades\Storage;

if (! function_exists('media_url')) {
    function media_url(?string $path): string {
        if (!$path) return asset('images/no-image.jpg');

        $disk = config('filesystems.media_disk', 'public'); // 'b2' or 'public'
        return Storage::disk($disk)->url($path);
    }
}
