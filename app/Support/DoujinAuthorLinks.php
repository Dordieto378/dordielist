<?php

namespace App\Support;

class DoujinAuthorLinks
{
    public const PLATFORMS = [
        'twitter_url' => ['key' => 'twitter', 'label' => 'Twitter'],
        'patreon_url' => ['key' => 'patreon', 'label' => 'Patreon'],
        'fanbox_url' => ['key' => 'fanbox', 'label' => 'Fanbox'],
        'pixiv_url' => ['key' => 'pixiv', 'label' => 'Pixiv'],
    ];

    public static function urls(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_array($value)
            ? $value
            : preg_split('/\R+/', (string) $value);

        return collect($values ?: [])
            ->flatten()
            ->map(fn ($url) => trim((string) $url))
            ->filter()
            ->unique(fn ($url) => mb_strtolower($url))
            ->values()
            ->all();
    }

    public static function store(mixed $value): ?string
    {
        $urls = self::urls($value);

        return $urls === [] ? null : implode("\n", $urls);
    }

    public static function payload(object $author): array
    {
        $payload = [];

        foreach (self::PLATFORMS as $column => $meta) {
            $payload[$meta['key']] = self::urls($author->{$column} ?? null);
        }

        return $payload;
    }

    public static function displayRows(?object $author): array
    {
        if (!$author) {
            return [];
        }

        $rows = [];

        foreach (self::PLATFORMS as $column => $meta) {
            foreach (self::urls($author->{$column} ?? null) as $url) {
                $rows[] = [
                    'label' => $meta['label'],
                    'url' => $url,
                ];
            }
        }

        return $rows;
    }
}
