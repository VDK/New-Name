<?php

namespace App\Service;

final class AffixDetector
{
    private const PREFIX_ITEMS = [
        'nl' => [
            'van der' => ['qid' => 'Q69869239', 'label' => 'van der'],
            'van de' => ['qid' => 'Q69869744', 'label' => 'van de'],
            'van' => ['qid' => 'Q1258618', 'label' => 'van'],
            'de' => ['qid' => 'Q11071485', 'label' => 'de'],
            'De' => ['qid' => 'Q69874623', 'label' => 'De'],
            'ten' => ['qid' => 'Q106043664', 'label' => 'ten'],
            'ter' => ['qid' => 'Q109323372', 'label' => 'ter'],
            "'t" => ['qid' => 'Q4540585', 'label' => "'t"],
        ],
        'de' => [
            'von der' => ['qid' => 'Q75135664', 'label' => 'von der'],
            'von dem' => ['qid' => 'Q83441628', 'label' => 'von dem'],
            'von' => ['qid' => 'Q70084230', 'label' => 'Von'],
        ],
    ];

    private const PREFIXES = [
        'nl' => ['van der', 'van den', 'van de', 'van het', 'van', 'de', 'den', 'ten', 'ter', "'t", "'s"],
        'de' => ['von und zu', 'von der', 'von dem', 'von', 'zu'],
        'fr' => ["l'", 'le', 'la', "d'", 'de la', 'du', 'des', 'de'],
        'it' => ['di', 'da', 'della', 'dello', 'degli', 'del'],
        'ar' => ['al', 'el', 'abu', 'ibn', 'bin', 'bint'],
        'pt' => ['da', 'das', 'do', 'dos', 'e'],
        'es' => ['de la', 'del', 'de', 'y'],
    ];

    private const SUFFIXES = [
        'slavic' => ['ovich', 'evich', 'owicz', 'ewicz', 'vich', 'wicz', 'vic', 'vić', 'ic', 'ić', 'ski', 'ska', 'sky', 'ov', 'ova', 'ev', 'eva', 'in', 'ina'],
        'armenian' => ['ian', 'yan'],
        'scandinavian' => ['dottir', 'dóttir', 'sen', 'sson', 'son'],
        'georgian' => ['shvili', 'dze'],
        'lithuanian' => ['aitis', 'evičius', 'avičius', 'ienė', 'ytė'],
        'persian' => ['zadeh', 'pour'],
    ];

    private const GERMAN_LANGUAGE_SUFFIXES = [
        'dorf',
        'stein',
        'berg',
        'burg',
        'feld',
        'bach',
        'wald',
        'heim',
        'thal',
        'stadt',
        'weiler',
        'kirch',
    ];

    /**
     * @return array<int, array{kind: string, group: string, value: string, confidence: string, item?: string, itemLabel?: string}>
     */
    public function detect(string $name): array
    {
        $trimmed = $this->normalizeName($name);
        $normalized = mb_strtolower($trimmed);
        $hits = [];

        foreach (self::PREFIXES as $group => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($normalized === $prefix || str_starts_with($normalized, $prefix . ' ') || str_starts_with($normalized, $prefix . '-')) {
                    $hit = ['kind' => 'prefix', 'group' => $group, 'value' => $prefix, 'confidence' => 'medium'];
                    $item = $this->prefixItem($group, $prefix, $trimmed);
                    if ($item !== null) {
                        $hit['item'] = $item['qid'];
                        $hit['itemLabel'] = $item['label'];
                    }
                    $hits[] = $hit;
                }
            }
        }

        foreach (self::SUFFIXES as $group => $suffixes) {
            foreach ($suffixes as $suffix) {
                if (mb_strlen($normalized) > mb_strlen($suffix) + 2 && str_ends_with($normalized, $suffix)) {
                    $hits[] = ['kind' => 'suffix', 'group' => $group, 'value' => $suffix, 'confidence' => 'low'];
                }
            }
        }

        foreach ($this->languageHeuristics($trimmed, $normalized) as $hit) {
            $hits[] = $hit;
        }

        return $hits;
    }

    private function normalizeName(string $name): string
    {
        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $name) ?? $name;

        return trim($normalized, " \t\n\r\0\x0B\xC2\xA0");
    }

    /**
     * @return list<array{kind: string, group: string, value: string, confidence: string}>
     */
    private function languageHeuristics(string $name, string $normalized): array
    {
        $hits = [];

        if (preg_match("/^O['\x{2019}][\p{L}]/u", $name) === 1) {
            $hits[] = ['kind' => 'language', 'group' => 'ga', 'value' => "O'", 'confidence' => 'medium'];
        }

        if (preg_match('/^Ma?c[\p{Lu}]/u', $name) === 1 || preg_match('/^ma?c[\p{Ll}]/u', $normalized) === 1) {
            $hits[] = ['kind' => 'language', 'group' => 'gd', 'value' => 'Mac/Mc', 'confidence' => 'low'];
        }

        if (mb_strlen($normalized) > 5 && str_ends_with($normalized, 'stra')) {
            $hits[] = ['kind' => 'language', 'group' => 'fy', 'value' => '-stra', 'confidence' => 'medium'];
        }

        foreach (self::GERMAN_LANGUAGE_SUFFIXES as $suffix) {
            if (mb_strlen($normalized) > mb_strlen($suffix) + 2 && str_ends_with($normalized, $suffix)) {
                $hits[] = ['kind' => 'language', 'group' => 'de', 'value' => '-' . $suffix, 'confidence' => 'medium'];
                break;
            }
        }

        return $hits;
    }

    /**
     * @return array{qid: string, label: string}|null
     */
    private function prefixItem(string $group, string $prefix, string $name): ?array
    {
        if ($group === 'nl' && $prefix === 'de' && preg_match('/^De(?:\s|-|$)/u', $name) === 1) {
            return self::PREFIX_ITEMS['nl']['De'];
        }

        return self::PREFIX_ITEMS[$group][$prefix] ?? null;
    }
}
