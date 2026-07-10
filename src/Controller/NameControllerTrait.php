<?php

namespace App\Controller;

use App\Data\LanguageHeuristics;

trait NameControllerTrait
{
    private function interfaceLanguage(string $language): string
    {
        return in_array($language, ['en', 'nl', 'de', 'fr', 'es'], true) ? $language : 'en';
    }

    /**
     * @return list<string>
     */
    private function nameAffixes(): array
    {
        $affixes = [];
        foreach (LanguageHeuristics::PREFIXES as $prefixes) {
            foreach ($prefixes as $prefix) {
                $affixes[mb_strtolower($prefix)] = mb_strtolower($prefix);
            }
        }
        usort($affixes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_values($affixes);
    }
}
