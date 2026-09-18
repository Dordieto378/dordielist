<?php

namespace App\Support;

class VndbLanguages
{
    private const LABELS = [
        'ar' => 'Arabic',
        'eu' => 'Basque',
        'be' => 'Belarusian',
        'bg' => 'Bulgarian',
        'bs' => 'Bosnian',
        'ca' => 'Catalan',
        'ck' => 'Cherokee',
        'zh' => 'Chinese',
        'zh-Hans' => 'Chinese (simplified)',
        'zh-Hant' => 'Chinese (traditional)',
        'hr' => 'Croatian',
        'cs' => 'Czech',
        'da' => 'Danish',
        'nl' => 'Dutch',
        'en' => 'English',
        'eo' => 'Esperanto',
        'et' => 'Estonian',
        'fi' => 'Finnish',
        'fr' => 'French',
        'gl' => 'Galician',
        'de' => 'German',
        'el' => 'Greek',
        'he' => 'Hebrew',
        'hi' => 'Hindi',
        'hu' => 'Hungarian',
        'ga' => 'Irish',
        'id' => 'Indonesian',
        'it' => 'Italian',
        'iu' => 'Inuktitut',
        'ja' => 'Japanese',
        'kk' => 'Kazakh',
        'ko' => 'Korean',
        'la' => 'Latin',
        'lv' => 'Latvian',
        'lt' => 'Lithuanian',
        'mk' => 'Macedonian',
        'ms' => 'Malay',
        'ne' => 'Nepali',
        'no' => 'Norwegian',
        'fa' => 'Persian',
        'pl' => 'Polish',
        'pt-br' => 'Portuguese (Brazil)',
        'pt-pt' => 'Portuguese (Portugal)',
        'ro' => 'Romanian',
        'ru' => 'Russian',
        'gd' => 'Scottish Gaelic',
        'sr' => 'Serbian',
        'sk' => 'Slovak',
        'sl' => 'Slovene',
        'es' => 'Spanish',
        'sv' => 'Swedish',
        'ta' => 'Tagalog',
        'th' => 'Thai',
        'tr' => 'Turkish',
        'uk' => 'Ukrainian',
        'ur' => 'Urdu',
        'vi' => 'Vietnamese',
    ];

    private const FLAG_COUNTRIES = [
        'ar' => 'sa',
        'eu' => 'es-pv',
        'be' => 'by',
        'bg' => 'bg',
        'bs' => 'ba',
        'ca' => 'es-ct',
        'ck' => 'us',
        'zh' => 'cn',
        'zh-Hans' => 'cn',
        'zh-Hant' => 'tw',
        'hr' => 'hr',
        'cs' => 'cz',
        'da' => 'dk',
        'nl' => 'nl',
        'en' => 'gb',
        'et' => 'ee',
        'fi' => 'fi',
        'fr' => 'fr',
        'gl' => 'es-ga',
        'de' => 'de',
        'el' => 'gr',
        'he' => 'il',
        'hi' => 'in',
        'hu' => 'hu',
        'ga' => 'ie',
        'id' => 'id',
        'it' => 'it',
        'iu' => 'ca',
        'ja' => 'jp',
        'kk' => 'kz',
        'ko' => 'kr',
        'la' => 'va',
        'lv' => 'lv',
        'lt' => 'lt',
        'mk' => 'mk',
        'ms' => 'my',
        'ne' => 'np',
        'no' => 'no',
        'fa' => 'ir',
        'pl' => 'pl',
        'pt-br' => 'br',
        'pt-pt' => 'pt',
        'ro' => 'ro',
        'ru' => 'ru',
        'gd' => 'gb-sct',
        'sr' => 'rs',
        'sk' => 'sk',
        'sl' => 'si',
        'es' => 'es',
        'sv' => 'se',
        'ta' => 'ph',
        'th' => 'th',
        'tr' => 'tr',
        'uk' => 'ua',
        'ur' => 'pk',
        'vi' => 'vn',
    ];

    public static function label(string $code): string
    {
        $code = trim($code);

        return self::LABELS[self::normalizeCode($code)] ?? strtoupper($code);
    }

    public static function codeLabel(string $code): string
    {
        $code = trim($code);

        return $code === '' ? '' : strtoupper($code);
    }

    public static function flag(string $code): string
    {
        $code = trim($code);
        if (self::normalizeCode($code) === 'eo') {
            return '🌐';
        }

        $countryCode = self::countryCode($code);
        $emojiCountryCode = $countryCode !== null ? substr($countryCode, 0, 2) : null;

        return $emojiCountryCode !== null
            ? implode('', array_map(
                fn (string $letter) => mb_chr(127397 + ord($letter), 'UTF-8'),
                str_split(strtoupper($emojiCountryCode))
            ))
            : self::codeLabel($code);
    }

    public static function countryCode(string $code): ?string
    {
        $code = trim($code);

        return self::FLAG_COUNTRIES[self::normalizeCode($code)] ?? null;
    }

    public static function options(iterable $codes): array
    {
        $options = [];

        foreach ($codes as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }

            $options[] = [
                'value' => $code,
                'label' => self::label($code),
                'flag' => self::flag($code),
                'country_code' => self::countryCode($code),
            ];
        }

        usort($options, fn (array $a, array $b) => strcasecmp($a['label'], $b['label'])
            ?: strcasecmp($a['value'], $b['value']));

        return $options;
    }

    private static function normalizeCode(string $code): string
    {
        return match (strtolower($code)) {
            'zh-hans' => 'zh-Hans',
            'zh-hant' => 'zh-Hant',
            default => strtolower($code),
        };
    }
}
