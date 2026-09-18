<?php

namespace App\Support;

use App\Models\VnPublisher;

class VndbPublisherData
{
    public static function releaseFields(): string
    {
        return implode(',', [
            'vns.id',
            'languages.lang',
            'producers.id',
            'producers.name',
            'producers.lang',
            'producers.publisher',
        ]);
    }

    public static function releaseFilter(array $vnIds): array
    {
        $predicates = array_values(array_filter(
            array_map(fn ($id) => self::vnIdPredicate((int) $id), $vnIds)
        ));

        if (count($predicates) === 1) {
            return ['vn', '=', $predicates[0]];
        }

        if (empty($predicates)) {
            return ['id', '=', 'r0'];
        }

        return ['vn', '=', array_merge(['or'], $predicates)];
    }

    public static function groupByVn(array $releases): array
    {
        $grouped = [];

        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $vnIds = self::releaseVnIds($release);
            if (empty($vnIds)) {
                continue;
            }

            $releaseLanguages = self::releaseLanguages($release);
            foreach ($release['producers'] ?? [] as $producer) {
                if (empty($producer['publisher'])) {
                    continue;
                }

                $name = trim((string) ($producer['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $sourceId = self::numericVndbId($producer['id'] ?? null, 'p');
                $languages = $releaseLanguages;
                if (empty($languages)) {
                    $producerLanguage = trim((string) ($producer['lang'] ?? ''));
                    $languages = $producerLanguage !== '' ? [$producerLanguage] : [''];
                }

                foreach ($vnIds as $vnId) {
                    foreach ($languages as $language) {
                        $grouped[$vnId][] = [
                            'name' => $name,
                            'source_id' => $sourceId,
                            'language' => $language,
                        ];
                    }
                }
            }
        }

        return self::dedupeGrouped($grouped);
    }

    public static function mergeGrouped(array $left, array $right): array
    {
        foreach ($right as $vnId => $publishers) {
            $left[$vnId] = array_merge($left[$vnId] ?? [], $publishers);
        }

        return self::dedupeGrouped($left);
    }

    public static function displayRows(iterable $publishers): array
    {
        $rows = [];

        foreach ($publishers as $publisher) {
            $name = $publisher instanceof VnPublisher
                ? $publisher->name
                : ($publisher['name'] ?? '');
            $language = $publisher instanceof VnPublisher
                ? $publisher->language
                : ($publisher['language'] ?? '');

            $name = trim((string) $name);
            $language = trim((string) $language);
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'language' => $language,
                'language_code' => $language !== '' ? strtoupper($language) : '',
                'language_label' => $language !== '' ? VndbLanguages::label($language) : '',
                'language_flag' => $language !== '' ? VndbLanguages::flag($language) : '',
                'language_country_code' => $language !== '' ? VndbLanguages::countryCode($language) : null,
            ];
        }

        $rows = self::dedupePublishers($rows);
        usort($rows, fn (array $a, array $b) => strcasecmp($a['language_label'], $b['language_label'])
            ?: strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    private static function vnIdPredicate(int $id): ?array
    {
        return $id > 0 ? ['id', '=', 'v'.$id] : null;
    }

    private static function releaseVnIds(array $release): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($vn) => is_array($vn) ? self::numericVndbId($vn['id'] ?? null, 'v') : null,
            $release['vns'] ?? []
        ))));
    }

    private static function releaseLanguages(array $release): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($language) => is_array($language) ? trim((string) ($language['lang'] ?? '')) : '',
            $release['languages'] ?? []
        ), fn ($language) => $language !== '')));
    }

    private static function numericVndbId(mixed $value, string $prefix): ?int
    {
        if (is_string($value)) {
            $value = ltrim($value, $prefix.strtoupper($prefix));
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function dedupeGrouped(array $grouped): array
    {
        foreach ($grouped as $vnId => $publishers) {
            $grouped[$vnId] = self::dedupePublishers($publishers);
        }

        return $grouped;
    }

    private static function dedupePublishers(array $publishers): array
    {
        $seen = [];
        $out = [];

        foreach ($publishers as $publisher) {
            $name = trim((string) ($publisher['name'] ?? ''));
            $language = trim((string) ($publisher['language'] ?? ''));
            if ($name === '') {
                continue;
            }

            $sourceId = $publisher['source_id'] ?? null;
            $key = ($sourceId ? 'id:'.$sourceId : 'name:'.mb_strtolower($name)).'|lang:'.mb_strtolower($language);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = [
                'name' => $name,
                'source_id' => is_numeric($sourceId) ? (int) $sourceId : null,
                'language' => $language,
                ...array_diff_key($publisher, ['name' => true, 'source_id' => true, 'language' => true]),
            ];
        }

        return $out;
    }
}
