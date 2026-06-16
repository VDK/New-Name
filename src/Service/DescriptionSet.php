<?php

namespace App\Service;

use App\Data\NameTypes;

final class DescriptionSet
{
    /**
     * @return array<string, string>
     */
    public function forType(string $type, string $name, ?string $descriptionName = null): array
    {
        $descriptions = $this->descriptionsForType($type);
        $suffixName = $descriptionName !== null && $descriptionName !== '' ? $descriptionName : $name;
        $nameScript = $this->scriptForText($suffixName);

        $out = [];
        foreach ($this->descriptionOverrides($type) as $language => $description) {
            $descriptions[$language] = $description;
        }

        foreach ($descriptions as $language => $description) {
            $value = trim(str_replace('%name%', $name, $description));
            if (!str_contains($description, '%name%') && $this->shouldAppendName($language, $nameScript, $suffixName)) {
                $value = $this->withNameSuffix($value, $suffixName, $language);
            }
            $out[$language] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function descriptionsForType(string $type): array
    {
        $external = $this->externalDescriptions();
        $key = match ($type) {
            NameTypes::FAMILY_NAME, NameTypes::CHINESE_FAMILY_NAME => 'surname',
            NameTypes::GIVEN_NAME, NameTypes::CHINESE_GIVEN_NAME, NameTypes::UNISEX_GIVEN_NAME => 'given name',
            NameTypes::MALE_GIVEN_NAME => 'male given name',
            NameTypes::FEMALE_GIVEN_NAME => 'female given name',
            default => '',
        };

        if ($key !== '' && isset($external[$key]) && $external[$key] !== []) {
            return $external[$key];
        }

        return NameTypes::DESCRIPTIONS[$type] ?? NameTypes::DESCRIPTIONS[NameTypes::GIVEN_NAME];
    }

    /**
     * @return array<string, string>
     */
    private function descriptionOverrides(string $type): array
    {
        return match ($type) {
            NameTypes::FAMILY_NAME, NameTypes::CHINESE_FAMILY_NAME => [
                'nl' => 'achternaam',
                'pt-br' => 'nome de família',
            ],
            default => [],
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function externalDescriptions(): array
    {
        static $sets = null;
        if (is_array($sets)) {
            return $sets;
        }

        $sets = [];
        $file = dirname(__DIR__, 2) . '/data/autoedit-descriptions.js';
        if (!is_file($file)) {
            return $sets;
        }

        $source = file_get_contents($file);
        if (!is_string($source) || !preg_match('/window\.desclist\s*=\s*\{(.*?)\n\};/su', $source, $match)) {
            return $sets;
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]\s*:\s*\{(.*?)\n\s*\}/su', $match[1], $rawSets, PREG_SET_ORDER);
        foreach ($rawSets as $rawSet) {
            $setName = stripcslashes($rawSet[1]);
            preg_match_all('/[\'"]([^\'"]+)[\'"]\s*:\s*[\'"]((?:\\\\.|[^\'"])*)[\'"]\s*,?/su', $rawSet[2], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $sets[$setName][stripcslashes($pair[1])] = stripcslashes($pair[2]);
            }
        }

        return $sets;
    }

    private function shouldAppendName(string $language, string $nameScript, string $name): bool
    {
        if ($name === '' || $nameScript === '') {
            return false;
        }

        $descriptionScript = $this->scriptForLanguage($language);
        if ($descriptionScript === '') {
            return false;
        }

        return $descriptionScript !== $nameScript;
    }

    private function withNameSuffix(string $description, string $name, string $language): string
    {
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
        $name = trim($name);
        if ($description === '' || $name === '') {
            return $description;
        }

        $quotedName = preg_quote($name, '/');
        $description = preg_replace('/\s*-\s*' . $quotedName . '$/u', '', $description) ?? $description;
        $description = preg_replace('/\s*\(\s*' . $quotedName . '\s*\)$/u', '', $description) ?? $description;

        if (in_array(strtolower($language), ['uk', 'ru', 'be', 'sr', 'sr-ec'], true)) {
            return trim($description) . ' - ' . $name;
        }

        return trim($description) . ' (' . $name . ')';
    }

    private function scriptForLanguage(string $language): string
    {
        $language = strtolower($language);
        $base = explode('-', $language, 2)[0];

        $script = [
            'crh-cyrl' => 'Cyrillic',
            'crh-latn' => 'Latin',
            'gan-hans' => 'Han',
            'gan-hant' => 'Han',
            'hif-latn' => 'Latin',
            'ike-cans' => 'Canadian_Aboriginal',
            'ike-latn' => 'Latin',
            'kk-arab' => 'Arabic',
            'kk-cyrl' => 'Cyrillic',
            'kk-latn' => 'Latin',
            'ks-arab' => 'Arabic',
            'ks-deva' => 'Devanagari',
            'ku-arab' => 'Arabic',
            'ku-latn' => 'Latin',
            'shi-latn' => 'Latin',
            'shi-tfng' => 'Tifinagh',
            'sr-ec' => 'Cyrillic',
            'sr-el' => 'Latin',
            'tg-cyrl' => 'Cyrillic',
            'tg-latn' => 'Latin',
            'tt-cyrl' => 'Cyrillic',
            'tt-latn' => 'Latin',
            'ug-arab' => 'Arabic',
            'ug-latn' => 'Latin',
            'zh-classical' => 'Han',
            'zh-cn' => 'Han',
            'zh-hans' => 'Han',
            'zh-hant' => 'Han',
            'zh-hk' => 'Han',
            'zh-min-nan' => 'Han',
            'zh-mo' => 'Han',
            'zh-my' => 'Han',
            'zh-sg' => 'Han',
            'zh-tw' => 'Han',
            'zh-yue' => 'Han',
        ][$language] ?? null;

        if ($script !== null) {
            return $script;
        }

        return [
            'am' => 'Ethiopic',
            'ar' => 'Arabic',
            'as' => 'Bengali',
            'be' => 'Cyrillic',
            'bg' => 'Cyrillic',
            'bo' => 'Tibetan',
            'bn' => 'Bengali',
            'ce' => 'Cyrillic',
            'ckb' => 'Arabic',
            'cv' => 'Cyrillic',
            'dv' => 'Thaana',
            'dz' => 'Tibetan',
            'fa' => 'Arabic',
            'gan' => 'Han',
            'gu' => 'Gujarati',
            'el' => 'Greek',
            'en' => 'Latin',
            'es' => 'Latin',
            'fr' => 'Latin',
            'de' => 'Latin',
            'he' => 'Hebrew',
            'hi' => 'Devanagari',
            'hy' => 'Armenian',
            'it' => 'Latin',
            'ja' => 'Han',
            'ka' => 'Georgian',
            'km' => 'Khmer',
            'kn' => 'Kannada',
            'kk' => 'Cyrillic',
            'ko' => 'Hangul',
            'ky' => 'Cyrillic',
            'lo' => 'Lao',
            'lzh' => 'Han',
            'mhr' => 'Cyrillic',
            'mk' => 'Cyrillic',
            'ml' => 'Malayalam',
            'mn' => 'Cyrillic',
            'my' => 'Myanmar',
            'myv' => 'Cyrillic',
            'nan' => 'Han',
            'ne' => 'Devanagari',
            'new' => 'Devanagari',
            'nl' => 'Latin',
            'or' => 'Oriya',
            'os' => 'Cyrillic',
            'pa' => 'Gurmukhi',
            'pnb' => 'Arabic',
            'ps' => 'Arabic',
            'ru' => 'Cyrillic',
            'rue' => 'Cyrillic',
            'sah' => 'Cyrillic',
            'sat' => 'Ol_Chiki',
            'sd' => 'Arabic',
            'si' => 'Sinhala',
            'sr' => 'Cyrillic',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'th' => 'Thai',
            'tg' => 'Cyrillic',
            'tt' => 'Cyrillic',
            'uk' => 'Cyrillic',
            'ur' => 'Arabic',
            'yi' => 'Hebrew',
            'yue' => 'Han',
            'zh' => 'Han',
        ][$base] ?? 'Latin';
    }

    private function scriptForText(string $text): string
    {
        $counts = [];
        foreach (['Han', 'Latin', 'Cyrillic', 'Arabic', 'Hebrew', 'Hangul', 'Hiragana', 'Katakana', 'Devanagari', 'Greek', 'Georgian', 'Armenian', 'Bengali', 'Gujarati', 'Gurmukhi', 'Malayalam', 'Myanmar', 'Oriya', 'Sinhala', 'Tamil', 'Telugu', 'Thai', 'Ethiopic', 'Tibetan', 'Thaana', 'Khmer', 'Kannada', 'Lao', 'Tifinagh'] as $script) {
            preg_match_all('/\p{' . $script . '}/u', $text, $matches);
            $count = count($matches[0] ?? []);
            if ($count > 0) {
                $counts[$script] = $count;
            }
        }

        if ($counts === []) {
            return '';
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }
}
