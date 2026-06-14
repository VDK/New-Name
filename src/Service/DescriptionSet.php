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
        foreach ($descriptions as $language => $description) {
            $value = str_replace('%name%', $name, $description);
            if (!str_contains($description, '%name%') && $this->shouldAppendName($language, $nameScript, $suffixName)) {
                $value .= ' (' . $suffixName . ')';
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

    private function scriptForLanguage(string $language): string
    {
        $base = strtolower(explode('-', $language, 2)[0]);

        return [
            'ar' => 'Arabic',
            'be' => 'Cyrillic',
            'bg' => 'Cyrillic',
            'el' => 'Greek',
            'en' => 'Latin',
            'es' => 'Latin',
            'fr' => 'Latin',
            'de' => 'Latin',
            'he' => 'Hebrew',
            'hy' => 'Armenian',
            'it' => 'Latin',
            'ja' => 'Han',
            'mk' => 'Cyrillic',
            'nl' => 'Latin',
            'ru' => 'Cyrillic',
            'sr' => 'Cyrillic',
            'uk' => 'Cyrillic',
            'zh' => 'Han',
        ][$base] ?? 'Latin';
    }

    private function scriptForText(string $text): string
    {
        $counts = [];
        foreach (['Han', 'Latin', 'Cyrillic', 'Arabic', 'Hebrew', 'Hangul', 'Hiragana', 'Katakana', 'Devanagari', 'Greek', 'Georgian', 'Armenian'] as $script) {
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
