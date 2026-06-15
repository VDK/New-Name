<?php

namespace App\Service;

use App\Data\NameTypes;

final class NameAnalyzer
{
    public function __construct(
        private readonly AffixDetector $affixDetector,
        private readonly ScriptDetector $scriptDetector,
        private readonly DescriptionSet $descriptionSet,
        private readonly WikidataClient $wikidataClient,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $name, ?string $selectedType = null): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $selectedType = $this->normalizeSelectedType($selectedType);
        $script = $this->scriptDetector->detect($name);
        $affixes = $this->affixDetector->detect($name);
        $matches = $this->wikidataClient->searchItems($name);
        $instances = [];

        if ($selectedType && isset(NameTypes::TYPE_ITEMS[$selectedType])) {
            $type = $selectedType;
            $suggestions = [];
            if ($this->shouldUseExactNameFallback($name, $affixes)) {
                $matches = $this->mergeMatches($matches, $this->wikidataClient->exactNameMatches($name, $this->rootTypeItem($type)));
            }
        } else {
            $instances = $this->wikidataClient->instanceOf(array_column($matches, 'id'));
            $suggestions = $this->suggestTypes($name, $script, $affixes, $matches, $instances);
            $type = $suggestions[0]['type'] ?? NameTypes::GIVEN_NAME;
            if ($this->shouldUseExactNameFallback($name, $affixes)) {
                $matches = $this->mergeMatches($matches, $this->wikidataClient->exactNameMatches($name, $this->rootTypeItem($type)));
            }
        }

        $missingInstanceIds = array_values(array_diff(array_column($matches, 'id'), array_keys($instances)));
        if ($missingInstanceIds) {
            $instances += $this->wikidataClient->instanceOf($missingInstanceIds);
        }

        return [
            'name' => $name,
            'selectedType' => $type,
            'selectedTypeLabel' => NameTypes::LABELS[$type] ?? $type,
            'suggestions' => $suggestions,
            'script' => $script,
            'affixes' => $affixes,
            'matches' => $this->shapeMatches($matches, $instances),
            'sameTypeMatches' => $this->sameTypeMatches($matches, $instances, $type, $name),
            'descriptions' => $this->descriptionSet->forType($type, $name),
            'claims' => $this->claimsFor($type, $script),
            'relationshipSuggestions' => $this->relationshipSuggestions($name, $type, $affixes, $this->relationshipCandidateMatches($matches, $instances, $type), $instances),
        ];
    }

    private function normalizeSelectedType(?string $selectedType): ?string
    {
        return match ($selectedType) {
            NameTypes::CHINESE_FAMILY_NAME => NameTypes::FAMILY_NAME,
            NameTypes::CHINESE_GIVEN_NAME => NameTypes::GIVEN_NAME,
            default => in_array($selectedType, NameTypes::ACTIVE_TYPES, true) ? $selectedType : null,
        };
    }

    /**
     * @param array<string, string>|null $script
     * @param list<array<string, string>> $affixes
     * @param list<array{id: string, label: string, description: string}> $matches
     * @param array<string, list<string>> $instances
     * @return list<array{type: string, label: string, confidence: string, reasons: list<string>}>
     */
    private function suggestTypes(string $name, ?array $script, array $affixes, array $matches, array $instances): array
    {
        $scores = [];
        foreach (NameTypes::ACTIVE_TYPES as $type) {
            $scores[$type] = ['score' => 0, 'reasons' => []];
        }

        foreach ($matches as $match) {
            $id = $match['id'];
            foreach ($instances[$id] ?? [] as $instance) {
                foreach (NameTypes::ACTIVE_TYPES as $type) {
                    if (in_array($instance, $this->compatibleTypeItems($type), true)) {
                        $scores[$type]['score'] += 50;
                        $scores[$type]['reasons'][] = $id . ' is already a ' . (NameTypes::LABELS[$type] ?? $type);
                    }
                }
            }
        }

        if ($affixes) {
            $scores[NameTypes::FAMILY_NAME]['score'] += 20;
            $scores[NameTypes::FAMILY_NAME]['reasons'][] = 'family-name affix pattern detected';
        }

        $out = [];
        foreach ($scores as $type => $data) {
            if ($data['score'] <= 0) {
                continue;
            }
            $out[] = [
                'type' => $type,
                'label' => NameTypes::LABELS[$type] ?? $type,
                'confidence' => $data['score'] >= 50 ? 'high' : ($data['score'] >= 20 ? 'medium' : 'low'),
                'reasons' => array_values(array_unique($data['reasons'])),
                'score' => $data['score'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        foreach ($out as &$suggestion) {
            unset($suggestion['score']);
        }
        unset($suggestion);

        return $out ?: [[
            'type' => NameTypes::GIVEN_NAME,
            'label' => NameTypes::LABELS[NameTypes::GIVEN_NAME],
            'confidence' => 'low',
            'reasons' => ['no strong Wikidata or affix evidence found'],
        ]];
    }

    /**
     * @param list<array{id: string, label: string, description: string}> $matches
     * @param array<string, list<string>> $instances
     * @return list<array<string, mixed>>
     */
    private function shapeMatches(array $matches, array $instances): array
    {
        return array_map(static fn (array $match): array => $match + [
            'instanceOf' => $instances[$match['id']] ?? [],
            'instanceLabels' => array_values(array_map(
                static fn (string $qid): string => NameTypes::ITEM_LABELS[$qid] ?? $qid,
                $instances[$match['id']] ?? []
            )),
        ], $matches);
    }

    /**
     * @param list<array{id: string, label: string, description: string}> $primary
     * @param list<array{id: string, label: string, description: string}> $additional
     * @return list<array{id: string, label: string, description: string}>
     */
    private function mergeMatches(array $primary, array $additional): array
    {
        $matches = [];
        foreach ([...$additional, ...$primary] as $match) {
            $matches[$match['id']] = $match;
        }

        return array_values($matches);
    }

    private function rootTypeItem(string $type): string
    {
        if ($this->isFamilyNameType($type)) {
            return NameTypes::TYPE_ITEMS[NameTypes::FAMILY_NAME];
        }

        if ($this->isGivenNameType($type)) {
            return NameTypes::TYPE_ITEMS[NameTypes::GIVEN_NAME];
        }

        return NameTypes::TYPE_ITEMS[$type] ?? NameTypes::TYPE_ITEMS[NameTypes::GIVEN_NAME];
    }

    /**
     * @param list<array<string, string>> $affixes
     */
    private function shouldUseExactNameFallback(string $name, array $affixes): bool
    {
        return $affixes !== [] || str_contains(trim($name), ' ');
    }

    /**
     * @param list<array{id: string, label: string, description: string}> $matches
     * @param array<string, list<string>> $instances
     * @return list<array<string, mixed>>
     */
    private function sameTypeMatches(array $matches, array $instances, string $selectedType, string $name): array
    {
        $compatibleItems = $this->compatibleTypeItems($selectedType);
        if (!$compatibleItems) {
            return [];
        }

        $foldedName = $this->foldName($name);
        $sameType = array_values(array_filter($matches, function (array $match) use ($instances, $compatibleItems, $foldedName): bool {
            return array_intersect($compatibleItems, $instances[$match['id']] ?? []) !== []
                && $this->foldName((string) $match['label']) === $foldedName;
        }));

        return $this->shapeMatches($sameType, $instances);
    }

    /**
     * @param list<array{id: string, label: string, description: string}> $matches
     * @param array<string, list<string>> $instances
     * @return list<array{id: string, label: string, description: string}>
     */
    private function relationshipCandidateMatches(array $matches, array $instances, string $selectedType): array
    {
        $sameType = [];
        $disambiguation = [];
        $other = [];
        foreach ($matches as $match) {
            $matchInstances = $instances[$match['id']] ?? [];
            if ($this->isSameType($selectedType, $matchInstances)) {
                $sameType[] = $match;
            } elseif ($this->isNameType($selectedType) && in_array('Q4167410', $matchInstances, true)) {
                $disambiguation[] = $match;
            } else {
                $other[] = $match;
            }
        }

        return array_slice([...$sameType, ...$disambiguation, ...array_slice($other, 0, 3)], 0, 8);
    }

    /**
     * @param array<string, string>|null $script
     * @return list<array{property: string, propertyLabel: string, value: string, valueLabel: string}>
     */
    private function claimsFor(string $type, ?array $script): array
    {
        $claims = [[
            'property' => 'P31',
            'propertyLabel' => 'instance of',
            'value' => NameTypes::TYPE_ITEMS[$type] ?? NameTypes::TYPE_ITEMS[NameTypes::GIVEN_NAME],
            'valueLabel' => NameTypes::LABELS[$type] ?? NameTypes::LABELS[NameTypes::GIVEN_NAME],
        ]];

        if ($script) {
            $claims[] = [
                'property' => 'P282',
                'propertyLabel' => 'writing system',
                'value' => $script['qid'],
                'valueLabel' => $script['label'],
            ];
        }

        return $claims;
    }

    /**
     * @param list<array{id: string, label: string, description: string}> $matches
     * @param array<string, list<string>> $instances
     * @param list<array<string, string>> $affixes
     * @return list<array{target: string, targetLabel: string, targetTypes: list<string>, property: string, propertyLabel: string, value: string, reason: string, qualifierProperty?: string, qualifierPropertyLabel?: string, qualifierValue?: string, qualifierValueLabel?: string}>
     */
    private function relationshipSuggestions(string $name, string $selectedType, array $affixes, array $matches, array $instances): array
    {
        $suggestions = [];
        $foldedName = $this->foldName($name);

        foreach ($matches as $match) {
            $id = $match['id'];
            $label = $match['label'];
            $matchInstances = $instances[$id] ?? [];
            $foldedLabel = $this->foldName($label);

            if (
                $foldedLabel === $foldedName
                && $label !== $name
                && $this->isSameType($selectedType, $matchInstances)
            ) {
                $suggestions[] = [
                    'target' => $id,
                    'targetLabel' => $label,
                    'targetTypes' => $this->instanceLabels($matchInstances),
                    'property' => 'P460',
                    'propertyLabel' => 'said to be the same as',
                    'value' => 'new item',
                    'reason' => 'same folded spelling; likely accent or diacritic variant',
                ];
            }

            if (
                $foldedLabel === $foldedName
                && $this->isGivenNameType($selectedType)
                && $this->hasFamilyNameInstance($matchInstances)
            ) {
                $suggestions[] = [
                    'target' => $id,
                    'targetLabel' => $label,
                    'targetTypes' => $this->instanceLabels($matchInstances),
                    'property' => 'P1533',
                    'propertyLabel' => 'family name identical to this given name',
                    'value' => 'new item',
                    'reason' => 'same spelling is already used as a family name',
                ];
            }

            if ($this->isGivenNameType($selectedType) && $this->isOtherGenderGivenName($selectedType, $matchInstances)) {
                $suggestions[] = [
                    'target' => $id,
                    'targetLabel' => $label,
                    'targetTypes' => $this->instanceLabels($matchInstances),
                    'property' => 'P1560',
                    'propertyLabel' => 'given name version for other gender',
                    'value' => 'new item',
                    'reason' => 'existing given-name item has a different gender subtype',
                ];
            }

            if (
                $foldedLabel === $foldedName
                && $this->isNameType($selectedType)
                && in_array('Q4167410', $matchInstances, true)
            ) {
                $qualifier = $this->differentFromQualifier($selectedType);
                $suggestions[] = [
                    'target' => $id,
                    'targetLabel' => $label,
                    'targetTypes' => $this->instanceLabels($matchInstances),
                    'property' => 'P1889',
                    'propertyLabel' => 'different from',
                    'value' => 'new item',
                    'reason' => 'same label is a disambiguation page',
                    'qualifierProperty' => 'P1013',
                    'qualifierPropertyLabel' => 'criterion used',
                    'qualifierValue' => $qualifier['qid'],
                    'qualifierValueLabel' => $qualifier['label'],
                ];
            }
        }

        if ($this->isFamilyNameType($selectedType)) {
            foreach ($this->affixlessFamilyNameMatches($name, $affixes, $matches) as $variant) {
                $variantInstances = $variant['instances'];
                $suggestions[] = [
                    'target' => $variant['id'],
                    'targetLabel' => $variant['label'],
                    'targetTypes' => $this->instanceLabels($variantInstances),
                    'property' => 'P1889',
                    'propertyLabel' => 'different from',
                    'value' => 'new item',
                    'reason' => 'one family name has an affix and the other does not',
                    'qualifierProperty' => 'P1013',
                    'qualifierPropertyLabel' => 'criterion used',
                    'qualifierValue' => 'Q140227247',
                    'qualifierValueLabel' => 'family name has to use a different item because one name has an affix and the other does not',
                ];
            }
        }

        if ($this->isGivenNameType($selectedType)) {
            foreach ($this->genderedVariantMatches($name, $selectedType, $matches) as $variant) {
                $variantInstances = $variant['instances'];
                if ($this->isOtherGenderGivenName($selectedType, $variantInstances)) {
                    $suggestions[] = [
                        'target' => $variant['id'],
                        'targetLabel' => $variant['label'],
                        'targetTypes' => $this->instanceLabels($variantInstances),
                        'property' => 'P1560',
                        'propertyLabel' => 'given name version for other gender',
                        'value' => 'new item',
                        'reason' => 'common -a gendered name variant',
                    ];
                }
            }
        }

        $seen = [];
        return array_values(array_filter($suggestions, static function (array $suggestion) use (&$seen): bool {
            $key = $suggestion['target'] . '|' . $suggestion['property'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));
    }

    /**
     * @param list<array<string, string>> $affixes
     * @param list<array{id: string, label: string, description: string}> $currentMatches
     * @return list<array{id: string, label: string, description: string, instances: list<string>}>
     */
    private function affixlessFamilyNameMatches(string $name, array $affixes, array $currentMatches): array
    {
        $variants = $this->affixlessFamilyNameVariants($name, $affixes);
        if (!$variants) {
            return [];
        }

        $currentIds = array_flip(array_column($currentMatches, 'id'));
        $out = [];
        foreach ($variants as $variant) {
            foreach ($this->wikidataClient->searchItems($variant, 'en', 5) as $match) {
                if (isset($currentIds[$match['id']]) || $this->foldName($match['label']) !== $this->foldName($variant)) {
                    continue;
                }
                $out[$match['id']] = $match;
            }
        }

        if (!$out) {
            return [];
        }

        $instances = $this->wikidataClient->instanceOf(array_keys($out));
        $out = array_filter($out, fn (array $match): bool => $this->hasFamilyNameInstance($instances[$match['id']] ?? []));

        return array_values(array_map(static fn (array $match): array => $match + [
            'instances' => $instances[$match['id']] ?? [],
        ], $out));
    }

    /**
     * @param list<array<string, string>> $affixes
     * @return list<string>
     */
    private function affixlessFamilyNameVariants(string $name, array $affixes): array
    {
        $variants = [];
        $normalizedName = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        foreach ($affixes as $affix) {
            if (($affix['kind'] ?? '') !== 'prefix') {
                continue;
            }
            $prefix = preg_quote((string) ($affix['value'] ?? ''), '/');
            if ($prefix === '') {
                continue;
            }
            $variant = preg_replace('/^' . $prefix . '[\s-]+/iu', '', $normalizedName, 1);
            if (is_string($variant) && $variant !== '' && $variant !== $normalizedName) {
                $variants[] = $variant;
                foreach ($this->familyNameSpellingVariants($variant) as $spellingVariant) {
                    $variants[] = $spellingVariant;
                }
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return list<string>
     */
    private function familyNameSpellingVariants(string $name): array
    {
        if (preg_match('/dorff$/iu', $name) === 1) {
            return [preg_replace('/dorff$/iu', 'dorf', $name) ?? $name];
        }
        if (preg_match('/dorf$/iu', $name) === 1) {
            return [$name . 'f'];
        }

        return [];
    }

    /**
     * @param list<array{id: string, label: string, description: string}> $currentMatches
     * @return list<array{id: string, label: string, description: string, instances: list<string>}>
     */
    private function genderedVariantMatches(string $name, string $selectedType, array $currentMatches): array
    {
        $variants = $this->genderedNameVariants($name, $selectedType);
        if (!$variants) {
            return [];
        }

        $currentIds = array_flip(array_column($currentMatches, 'id'));
        $out = [];
        foreach ($variants as $variant) {
            foreach ($this->wikidataClient->searchItems($variant, 'en', 5) as $match) {
                if (isset($currentIds[$match['id']]) || $this->foldName($match['label']) !== $this->foldName($variant)) {
                    continue;
                }
                $out[$match['id']] = $match;
            }
        }

        if (!$out) {
            return [];
        }

        $instances = $this->wikidataClient->instanceOf(array_keys($out));

        return array_values(array_map(static fn (array $match): array => $match + [
            'instances' => $instances[$match['id']] ?? [],
        ], $out));
    }

    /**
     * @return list<string>
     */
    private function genderedNameVariants(string $name, string $selectedType): array
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[\p{Latin}\p{M}\s\'’.-]+$/u', $name)) {
            return [];
        }

        $variants = [];
        $lower = mb_strtolower($name);
        $last = mb_substr($name, -1);
        $stem = mb_substr($name, 0, -1);

        if ($selectedType === NameTypes::FEMALE_GIVEN_NAME && mb_strtolower($last) === 'a' && mb_strlen($stem) >= 2) {
            $variants[] = $stem;
            $variants[] = $stem . 'o';
        } elseif ($selectedType === NameTypes::MALE_GIVEN_NAME && mb_strlen($name) >= 2) {
            $variants[] = $name . 'a';
            if (mb_strtolower($last) === 'o' && mb_strlen($stem) >= 2) {
                $variants[] = $stem . 'a';
            }
            if (str_ends_with($lower, 'us') && mb_strlen($name) >= 4) {
                $variants[] = mb_substr($name, 0, -2) . 'a';
            }
        } elseif ($this->isFamilyNameType($selectedType)) {
            foreach ($this->familyNameGenderPairs() as [$base, $gendered]) {
                if (str_ends_with($lower, $gendered) && mb_strlen($name) > mb_strlen($gendered) + 1) {
                    $variants[] = mb_substr($name, 0, -mb_strlen($gendered)) . $base;
                }
                if (str_ends_with($lower, $base) && mb_strlen($name) > mb_strlen($base) + 1) {
                    $variants[] = $name . mb_substr($gendered, mb_strlen($base));
                }
            }
        }

        return array_values(array_unique(array_filter($variants, static fn (string $variant): bool => $variant !== $name)));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function familyNameGenderPairs(): array
    {
        return [
            ['ov', 'ova'],
            ['ev', 'eva'],
            ['in', 'ina'],
            ['ski', 'ska'],
            ['cki', 'cka'],
            ['sky', 'skaya'],
            ['ský', 'ská'],
            ['ov', 'ová'],
            ['ev', 'evá'],
        ];
    }

    private function foldName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $folded = is_string($transliterated) ? $transliterated : $name;

        return preg_replace('/[^a-z0-9]+/', '', $folded) ?? $folded;
    }

    /**
     * @param list<string> $terms
     */
    private function hasFoldedTerm(string $foldedName, array $terms, string $originalName): bool
    {
        foreach ($terms as $term) {
            if ($term !== $originalName && $this->foldName($term) === $foldedName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $instances
     */
    private function isSameType(string $selectedType, array $instances): bool
    {
        return array_intersect($this->compatibleTypeItems($selectedType), $instances) !== [];
    }

    /**
     * @return list<string>
     */
    private function compatibleTypeItems(string $type): array
    {
        return NameTypes::COMPATIBLE_TYPE_ITEMS[$type] ?? array_values(array_filter([NameTypes::TYPE_ITEMS[$type] ?? null]));
    }

    /**
     * @param list<string> $instances
     * @return list<string>
     */
    private function instanceLabels(array $instances): array
    {
        return array_values(array_filter(array_map(
            static fn (string $qid): string => NameTypes::ITEM_LABELS[$qid] ?? '',
            $instances
        )));
    }

    private function isGivenNameType(string $type): bool
    {
        return in_array($type, [
            NameTypes::GIVEN_NAME,
            NameTypes::MALE_GIVEN_NAME,
            NameTypes::FEMALE_GIVEN_NAME,
            NameTypes::UNISEX_GIVEN_NAME,
            NameTypes::CHINESE_GIVEN_NAME,
        ], true);
    }

    /**
     * @param list<string> $instances
     */
    private function isOtherGenderGivenName(string $selectedType, array $instances): bool
    {
        $gendered = [
            NameTypes::MALE_GIVEN_NAME => NameTypes::TYPE_ITEMS[NameTypes::MALE_GIVEN_NAME],
            NameTypes::FEMALE_GIVEN_NAME => NameTypes::TYPE_ITEMS[NameTypes::FEMALE_GIVEN_NAME],
            NameTypes::UNISEX_GIVEN_NAME => NameTypes::TYPE_ITEMS[NameTypes::UNISEX_GIVEN_NAME],
        ];

        if (!isset($gendered[$selectedType])) {
            return false;
        }

        foreach ($gendered as $type => $qid) {
            if ($type !== $selectedType && in_array($qid, $instances, true)) {
                return true;
            }
        }

        return false;
    }

    private function isFamilyNameType(string $type): bool
    {
        return in_array($type, [
            NameTypes::FAMILY_NAME,
            NameTypes::CHINESE_FAMILY_NAME,
        ], true);
    }

    private function isNameType(string $type): bool
    {
        return $this->isFamilyNameType($type) || $this->isGivenNameType($type);
    }

    /**
     * @param list<string> $instances
     */
    private function hasFamilyNameInstance(array $instances): bool
    {
        return array_intersect(NameTypes::COMPATIBLE_TYPE_ITEMS[NameTypes::FAMILY_NAME], $instances) !== [];
    }

    /**
     * @return array{qid: string, label: string}
     */
    private function differentFromQualifier(string $selectedType): array
    {
        if ($this->isGivenNameType($selectedType)) {
            return ['qid' => 'Q23765057', 'label' => 'given name has to use a different item than disambiguation pages'];
        }

        return ['qid' => 'Q27924673', 'label' => 'family name has to use a different item than disambiguation page'];
    }
}
