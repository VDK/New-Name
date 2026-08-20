<?php

namespace App\Service;

use App\Data\LanguageHeuristics;

final class AffixDetector
{
    /**
     * @return array<int, array{kind: string, group: string, value: string, confidence: string, item?: string, itemLabel?: string}>
     */
    public function detect(string $name): array
    {
        $trimmed = $this->normalizeName($name);
        $normalized = mb_strtolower($trimmed);
        $hits = [];

        foreach (LanguageHeuristics::PREFIXES as $group => $prefixes) {
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

        foreach (LanguageHeuristics::SUFFIXES as $group => $suffixes) {
            foreach ($suffixes as $suffix) {
                if (mb_strlen($normalized) > mb_strlen($suffix) + 2 && str_ends_with($normalized, $suffix)) {
                    $hits[] = ['kind' => 'suffix', 'group' => $group, 'value' => $suffix, 'confidence' => 'low'];
                }
            }
        }

        foreach ($this->languageHeuristics($trimmed, $normalized) as $hit) {
            $hits[] = $hit;
        }

        return $this->removeSubsumedPrefixes($hits);
    }

    /**
     * A compound prefix match (e.g. "van der") already covers its shorter
     * word prefix ("van"), so the shorter one should not be suggested again.
     *
     * @param list<array{kind: string, group: string, value: string, confidence: string, item?: string, itemLabel?: string}> $hits
     * @return list<array{kind: string, group: string, value: string, confidence: string, item?: string, itemLabel?: string}>
     */
    private function removeSubsumedPrefixes(array $hits): array
    {
        $prefixValues = [];
        foreach ($hits as $hit) {
            if (($hit['kind'] ?? '') === 'prefix') {
                $prefixValues[] = (string) $hit['value'];
            }
        }

        return array_values(array_filter($hits, static function (array $hit) use ($prefixValues): bool {
            if (($hit['kind'] ?? '') !== 'prefix') {
                return true;
            }
            $value = (string) $hit['value'];
            foreach ($prefixValues as $other) {
                if ($other !== $value && str_starts_with($other, $value . ' ')) {
                    return false;
                }
            }

            return true;
        }));
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
        $seen = [];

        foreach (LanguageHeuristics::LANGUAGE_PATTERNS as $pattern) {
            $subject = ($pattern['subject'] ?? 'normalized') === 'name' ? $name : $normalized;
            if (preg_match((string) $pattern['regex'], $subject) === 1) {
                $hit = [
                    'kind' => 'language',
                    'group' => (string) $pattern['group'],
                    'value' => (string) $pattern['value'],
                    'confidence' => (string) $pattern['confidence'],
                ];
                $key = implode('|', $hit);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $hits[] = $hit;
                }
            }
        }

        foreach (LanguageHeuristics::LANGUAGE_SUFFIXES as $suffixHint) {
            $suffix = (string) $suffixHint['suffix'];
            if (mb_strlen($normalized) > mb_strlen($suffix) + 2 && str_ends_with($normalized, $suffix)) {
                $hit = [
                    'kind' => 'language',
                    'group' => (string) $suffixHint['group'],
                    'value' => (string) $suffixHint['value'],
                    'confidence' => (string) $suffixHint['confidence'],
                ];
                $key = implode('|', $hit);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $hits[] = $hit;
                }
            }
        }

        return $hits;
    }

    /**
     * @return array{qid: string, label: string}|null
     */
    private function prefixItem(string $group, string $prefix, string $name): ?array
    {
        $capitalized = ucfirst($prefix);
        if (
            $capitalized !== $prefix
            && isset(LanguageHeuristics::PREFIX_ITEMS[$group][$capitalized])
            && preg_match('/^' . preg_quote($capitalized, '/') . '(?:\s|-|$)/u', $name) === 1
        ) {
            return LanguageHeuristics::PREFIX_ITEMS[$group][$capitalized];
        }

        return LanguageHeuristics::PREFIX_ITEMS[$group][$prefix] ?? null;
    }
}
