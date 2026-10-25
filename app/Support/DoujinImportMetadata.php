<?php

namespace App\Support;

use InvalidArgumentException;

class DoujinImportMetadata
{
    public static function fromRelativePath(string $path): array
    {
        $segments = explode('/', str_replace('\\', '/', $path));

        if (count($segments) !== 3) {
            throw new InvalidArgumentException('Select a folder containing author folders with ZIP files directly inside them.');
        }

        foreach ($segments as $segment) {
            if (trim($segment) === '' || in_array($segment, ['.', '..'], true) || preg_match('/[\x00-\x1F:]/', $segment)) {
                throw new InvalidArgumentException('The selected file has an invalid folder path.');
            }
        }

        $filename = $segments[2];
        $title = trim(pathinfo($filename, PATHINFO_FILENAME));
        $author = trim($segments[1]);

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'zip' || $title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Each doujin must be a ZIP file with a title of up to 255 characters.');
        }

        if (mb_strlen($author) > 255) {
            throw new InvalidArgumentException('Author names must be no longer than 255 characters.');
        }

        return ['title' => $title, 'author' => $author, 'filename' => $filename];
    }
}
