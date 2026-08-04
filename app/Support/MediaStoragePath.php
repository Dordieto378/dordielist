<?php

namespace App\Support;

use App\Models\Media;

class MediaStoragePath
{
    public static function archiveDirectory(Media $media): string
    {
        return 'media-archives/'.self::mediaTypeDirectory($media).'/'.self::stableMediaKey($media);
    }

    public static function mediaDirectory(Media $media): string
    {
        return self::mediaTypeDirectory($media).'/'.self::stableMediaKey($media);
    }

    public static function chapterDirectory(Media $media): string
    {
        if (in_array(self::mediaTypeDirectory($media), ['manga', 'manhwa', 'light_novel'], true)) {
            return self::mediaTypeDirectory($media).'/'.self::stableMediaKey($media);
        }

        return self::legacyChapterDirectory($media);
    }

    public static function legacyChapterDirectory(Media $media): string
    {
        return self::mediaTypeDirectory($media).'/'.$media->id;
    }

    public static function mediaTypeDirectory(Media $media): string
    {
        return strtolower((string) $media->type);
    }

    public static function stableMediaKey(Media $media): string
    {
        $source = strtolower(trim((string) ($media->source ?? '')));
        $sourceId = $media->source_id;

        if ($source !== '' && $sourceId !== null && $sourceId !== '') {
            return $source.'-'.$sourceId;
        }

        return 'local-'.$media->id;
    }
}
