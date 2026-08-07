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

    private const FLAGS = [
        'ar' => '🇸🇦',
        'eu' => '🇪🇸',
        'be' => '🇧🇾',
        'bg' => '🇧🇬',
        'bs' => '🇧🇦',
        'ca' => '🇪🇸',
        'ck' => '🇺🇸',
        'zh' => '🇨🇳',
        'zh-Hans' => '🇨🇳',
        'zh-Hant' => '🇹🇼',
        'hr' => '🇭🇷',
        'cs' => '🇨🇿',
        'da' => '🇩🇰',
        'nl' => '🇳🇱',
        'en' => '🇬🇧',
        'eo' => '🌐',
        'et' => '🇪🇪',
        'fi' => '🇫🇮',
        'fr' => '🇫🇷',
        'gl' => '🇪🇸',
        'de' => '🇩🇪',
        'el' => '🇬🇷',
        'he' => '🇮🇱',
        'hi' => '🇮🇳',
        'hu' => '🇭🇺',
        'ga' => '🇮🇪',
        'id' => '🇮🇩',
        'it' => '🇮🇹',
        'iu' => '🇨🇦',
        'ja' => '🇯🇵',
        'kk' => '🇰🇿',
        'ko' => '🇰🇷',
        'la' => '🇻🇦',
        'lv' => '🇱🇻',
        'lt' => '🇱🇹',
        'mk' => '🇲🇰',
        'ms' => '🇲🇾',
        'ne' => '🇳🇵',
        'no' => '🇳🇴',
        'fa' => '🇮🇷',
        'pl' => '🇵🇱',
        'pt-br' => '🇧🇷',
        'pt-pt' => '🇵🇹',
        'ro' => '🇷🇴',
        'ru' => '🇷🇺',
        'gd' => '🇬🇧',
        'sr' => '🇷🇸',
        'sk' => '🇸🇰',
        'sl' => '🇸🇮',
        'es' => '🇪🇸',
        'sv' => '🇸🇪',
        'ta' => '🇵🇭',
        'th' => '🇹🇭',
        'tr' => '🇹🇷',
        'uk' => '🇺🇦',
        'ur' => '🇵🇰',
        'vi' => '🇻🇳',
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

        return self::FLAGS[self::normalizeCode($code)] ?? self::codeLabel($code);
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
