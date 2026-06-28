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
    public function analyze(string $name, ?string $selectedType = null, ?string $transliteration = null, ?string $selectedItemId = null): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $transliteration = trim(preg_replace('/\s+/u', ' ', $transliteration ?? '') ?? ($transliteration ?? ''));
        $selectedItemId = strtoupper(trim($selectedItemId ?? ''));
        if (preg_match('/^Q\d+$/', $selectedItemId) !== 1) {
            $selectedItemId = '';
        }
        $selectedType = $this->normalizeSelectedType($selectedType);
        $script = $this->scriptDetector->detect($name);
        $affixes = $this->affixDetector->detect($name);
        $matches = $this->wikidataClient->searchItems($name);
        if ($transliteration !== '' && $this->foldName($transliteration) !== $this->foldName($name)) {
            $matches = $this->mergeMatches($matches, $this->wikidataClient->searchItems($transliteration));
        }
        $analysisData = $this->wikidataClient->itemAnalysisData(array_column($matches, 'id'));
        $instances = $analysisData['instances'];

        if ($selectedType === NameTypes::GIVEN_SCOPE) {
            $suggestions = $this->suggestTypes($name, $script, $affixes, $matches, $instances, [
                NameTypes::GIVEN_NAME,
                NameTypes::MALE_GIVEN_NAME,
                NameTypes::FEMALE_GIVEN_NAME,
                NameTypes::UNISEX_GIVEN_NAME,
            ]);
            $type = $suggestions[0]['type'] ?? NameTypes::GIVEN_NAME;
            if ($this->shouldUseExactNameFallback($name, $affixes)) {
                $matches = $this->mergeMatches($matches, $this->wikidataClient->exactNameMatches($name, $this->rootTypeItem($type), $matches, $instances));
            }
        } elseif ($selectedType && isset(NameTypes::TYPE_ITEMS[$selectedType])) {
            $type = $selectedType;
            $suggestions = [];
            $matches = $this->mergeMatches($matches, $this->wikidataClient->exactNameMatches($name, $this->rootTypeItem($type), $matches, $instances));
            if ($this->isNameType($type)) {
                $oppositeRootType = $this->isGivenNameType($type)
                    ? NameTypes::TYPE_ITEMS[NameTypes::FAMILY_NAME]
                    : NameTypes::TYPE_ITEMS[NameTypes::GIVEN_NAME];
                $matches = $this->mergeMatches($matches, $this->wikidataClient->exactNameMatches($name, $oppositeRootType, $matches, $instances, false));
            }
        } else {
            $suggestions = $this->suggestTypes($name, $script, $affixes, $matches, $instances);
            $type = $suggestions[0]['type'] ?? NameTypes::GIVEN_NAME;
            if ($this->shouldUseExactNameFallback($name, $affixes)) {
                $matches = $this->mergeMatches($matches, $this->wikidataClient->exactNameMatches($name, $this->rootTypeItem($type), $matches, $instances));
            }
        }

        $missingDataIds = array_values(array_diff(array_column($matches, 'id'), array_keys($analysisData['instances'])));
        if ($missingDataIds) {
            $extraData = $this->wikidataClient->itemAnalysisData($missingDataIds);
            foreach ($analysisData as $key => $values) {
                $analysisData[$key] += $extraData[$key] ?? [];
            }
        }
        $matchIds = array_column($matches, 'id');
        $instances = $analysisData['instances'];
        $scripts = $analysisData['scripts'];
        $nativeLabels = $analysisData['nativeLabels'];
        $mulLabels = $analysisData['mulLabels'];
        foreach ($matches as $match) {
            $id = (string) ($match['id'] ?? '');
            $embeddedNativeLabel = $this->nativeLabelFromDescription(
                (string) ($match['description'] ?? ''),
                (string) ($match['label'] ?? ''),
                $script
            );
            if ($id !== '' && $embeddedNativeLabel !== null) {
                $nativeLabels[$id] = [$embeddedNativeLabel];
            }
        }

        return [
            'name' => $name,
            'selectedType' => $type,
            'selectedTypeLabel' => NameTypes::LABELS[$type] ?? $type,
            'suggestions' => $suggestions,
            'script' => $script,
            'affixes' => $affixes,
            'matches' => $this->shapeMatches($matches, $instances, $scripts, $nativeLabels, $mulLabels, $script),
            'sameTypeMatches' => $this->sameTypeMatches($matches, $instances, $scripts, $nativeLabels, $mulLabels, $type, $name, $script),
            'descriptions' => $this->descriptionSet->forType($type, $name),
            'claims' => $this->claimsFor($type, $script),
            'relationshipSuggestions' => $this->relationshipSuggestions(
                $name,
                $type,
                $affixes,
                $this->relationshipCandidateMatches($matches, $instances, $type),
                $instances,
                $scripts,
                is_array($script) ? (string) ($script['qid'] ?? '') : '',
                $transliteration !== '' ? [$transliteration] : [],
                $selectedItemId
            ),
        ];
    }

    private function normalizeSelectedType(?string $selectedType): ?string
    {
        return NameTypes::fromUrl($selectedType);
    }

    /**
     * @param array<string, string>|null $script
     * @param list<array<string, string>> $affixes
     * @param list<array{id: string, label: string, description: string}> $matches
     * @param array<string, list<string>> $instances
     * @return list<array{type: string, label: string, confidence: string, reasons: list<string>}>
     */
    private function suggestTypes(string $name, ?array $script, array $affixes, array $matches, array $instances, ?array $candidateTypes = null): array
    {
        $candidateTypes ??= NameTypes::ACTIVE_TYPES;
        $scores = [];
        foreach ($candidateTypes as $type) {
            $scores[$type] = ['score' => 0, 'reasons' => []];
        }

        foreach ($matches as $match) {
            $id = $match['id'];
            foreach ($instances[$id] ?? [] as $instance) {
                foreach ($candidateTypes as $type) {
                    if ($instance === (NameTypes::TYPE_ITEMS[$type] ?? '')) {
                        $scores[$type]['score'] += 50;
                        $scores[$type]['reasons'][] = $id . ' is already a ' . (NameTypes::LABELS[$type] ?? $type);
                    } elseif (in_array($instance, $this->compatibleTypeItems($type), true)) {
                        $scores[$type]['score'] += 10;
                        $scores[$type]['reasons'][] = $id . ' has a compatible name type';
                    }
                }
            }
        }

        if ($affixes && isset($scores[NameTypes::FAMILY_NAME])) {
            $scores[NameTypes::FAMILY_NAME]['score'] += 20;
            $scores[NameTypes::FAMILY_NAME]['reasons'][] = 'family-name affix pattern detected';
        }
        if (
            isset($scores[NameTypes::FEMALE_GIVEN_NAME])
            && preg_match('/a$/iu', $name) === 1
            && mb_strlen($name) >= 3
        ) {
            $scores[NameTypes::FEMALE_GIVEN_NAME]['score'] += 20;
            $scores[NameTypes::FEMALE_GIVEN_NAME]['reasons'][] = 'name ending in -a is commonly feminine';
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

        usort($out, function (array $a, array $b): int {
            return ($b['score'] <=> $a['score'])
                ?: ($this->typeSpecificity($b['type']) <=> $this->typeSpecificity($a['type']));
        });

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
     * @param array<string, list<string>> $scripts
     * @param array<string, list<string>> $nativeLabels
     * @return list<array<string, mixed>>
     */
    private function shapeMatches(array $matches, array $instances, array $scripts = [], array $nativeLabels = [], array $mulLabels = [], ?array $detectedScript = null): array
    {
        return array_map(function (array $match) use ($instances, $scripts, $nativeLabels, $mulLabels, $detectedScript): array {
            $id = $match['id'];

            return $match + [
                'description' => $this->descriptionWithNativeLabel((string) ($match['description'] ?? ''), (string) ($match['label'] ?? ''), $nativeLabels[$id] ?? [], $detectedScript),
                'instanceOf' => $instances[$id] ?? [],
                'instanceLabels' => array_values(array_map(
                    static fn (string $qid): string => NameTypes::ITEM_LABELS[$qid] ?? $qid,
                    $instances[$id] ?? []
                )),
                'scripts' => $scripts[$id] ?? [],
                'nativeLabels' => $nativeLabels[$id] ?? [],
                'mulLabel' => $mulLabels[$id] ?? '',
            ];
        }, $matches);
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

    private function typeSpecificity(string $type): int
    {
        return match ($type) {
            NameTypes::MALE_GIVEN_NAME, NameTypes::FEMALE_GIVEN_NAME, NameTypes::UNISEX_GIVEN_NAME => 3,
            NameTypes::CHINESE_FAMILY_NAME, NameTypes::CHINESE_GIVEN_NAME => 2,
            NameTypes::FAMILY_NAME => 1,
            default => 0,
        };
    }

    /**
     * @param list<string> $nativeLabels
     */
    private function descriptionWithNativeLabel(string $description, string $label, array $nativeLabels, ?array $detectedScript): string
    {
        $detectedScriptName = is_array($detectedScript) ? (string) ($detectedScript['script'] ?? '') : '';
        if ($detectedScriptName === '') {
            return $description;
        }

        foreach ($nativeLabels as $nativeLabel) {
            if ($nativeLabel === '' || $nativeLabel === $label) {
                continue;
            }
            if (str_contains($description, $nativeLabel)) {
                return $description;
            }
            $nativeScript = $this->scriptDetector->detect($nativeLabel);
            if (is_array($nativeScript) && (string) ($nativeScript['script'] ?? '') !== $detectedScriptName) {
                $description = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);

                return $description !== '' ? $description . ' (' . $nativeLabel . ')' : $nativeLabel;
            }
        }

        return $description;
    }

    private function nativeLabelFromDescription(string $description, string $label, ?array $detectedScript): ?string
    {
        $detectedScriptName = is_array($detectedScript) ? (string) ($detectedScript['script'] ?? '') : '';
        if ($detectedScriptName === '' || $detectedScriptName === 'Latin' || $description === '') {
            return null;
        }

        preg_match_all('/[\(\x{FF08}]([^\(\)\x{FF08}\x{FF09}]+)[\)\x{FF09}]/u', $description, $matches);
        foreach ($matches[1] ?? [] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || $candidate === $label) {
                continue;
            }
            $candidateScript = $this->scriptDetector->detect($candidate);
            if (is_array($candidateScript) && (string) ($candidateScript['script'] ?? '') === $detectedScriptName) {
                return $candidate;
            }
        }

        return null;
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
     * @param array<string, list<string>> $scripts
     * @param array<string, list<string>> $nativeLabels
     * @return list<array<string, mixed>>
     */
    private function sameTypeMatches(array $matches, array $instances, array $scripts, array $nativeLabels, array $mulLabels, string $selectedType, string $name, ?array $detectedScript): array
    {
        $compatibleItems = $this->compatibleTypeItems($selectedType);
        if (!$compatibleItems) {
            return [];
        }

        $foldedName = $this->foldName($name);
        $detectedScriptQid = is_array($detectedScript) ? (string) ($detectedScript['qid'] ?? '') : '';
        $sameType = array_values(array_filter($matches, function (array $match) use ($instances, $scripts, $compatibleItems, $foldedName, $detectedScriptQid): bool {
            $matchScripts = $scripts[$match['id']] ?? [];

            return array_intersect($compatibleItems, $instances[$match['id']] ?? []) !== []
                && ($detectedScriptQid === '' || in_array($detectedScriptQid, $matchScripts, true))
                && $this->foldName((string) $match['label']) === $foldedName;
        }));

        return $this->shapeMatches($sameType, $instances, $scripts, $nativeLabels, $mulLabels, $detectedScript);
    }

    /**
     * @param list<array{id: string, label: string, description: string}> $matches
     * @param array<string, list<string>> $instances
     * @return list<array{id: string, label: string, description: string}>
     */
    private function relationshipCandidateMatches(array $matches, array $instances, string $selectedType): array
    {
        $sameType = [];
        $otherNameType = [];
        $disambiguation = [];
        $other = [];
        foreach ($matches as $match) {
            $matchInstances = $instances[$match['id']] ?? [];
            if ($this->isSameType($selectedType, $matchInstances)) {
                $sameType[] = $match;
            } elseif (
                ($this->isGivenNameType($selectedType) && $this->hasFamilyNameInstance($matchInstances))
                || ($this->isFamilyNameType($selectedType) && $this->hasGivenNameInstance($matchInstances))
            ) {
                $otherNameType[] = $match;
            } elseif ($this->isNameType($selectedType) && in_array('Q4167410', $matchInstances, true)) {
                $disambiguation[] = $match;
            } else {
                $other[] = $match;
            }
        }

        return array_slice([...$sameType, ...$otherNameType, ...$disambiguation, ...array_slice($other, 0, 3)], 0, 10);
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
     * @return list<array{target: string, targetLabel: string, targetTypes: list<string>, property: string, propertyLabel: string, value: string, reason: string, direction?: string, qualifierProperty?: string, qualifierPropertyLabel?: string, qualifierValue?: string, qualifierValueLabel?: string}>
     */
    private function relationshipSuggestions(string $name, string $selectedType, array $affixes, array $matches, array $instances, array $scripts, string $selectedScriptQid, array $alternativeNames = [], string $selectedItemId = ''): array
    {
        $suggestions = [];
        $foldedName = $this->foldName($name);
        $comparisonNames = array_values(array_unique(array_filter([$name, ...$alternativeNames])));

        foreach ($matches as $match) {
            $id = $match['id'];
            if ($id === $selectedItemId) {
                continue;
            }
            $label = $match['label'];
            $matchInstances = $instances[$id] ?? [];
            $foldedLabel = $this->foldName($label);

            $sameSpelling = false;
            foreach ($comparisonNames as $comparisonName) {
                if ($foldedLabel === $this->foldName($comparisonName)) {
                    $sameSpelling = true;
                    break;
                }
            }
            $relatedFamilySpelling = $this->isFamilyNameType($selectedType)
                && $this->isRelatedFamilyNameCandidate($name, $label, $affixes);

            if (
                $label !== $name
                && $this->isSameType($selectedType, $matchInstances)
                && ($sameSpelling || $relatedFamilySpelling)
            ) {
                $suggestions[] = [
                    'target' => $id,
                    'targetLabel' => $label,
                    'targetTypes' => $this->instanceLabels($matchInstances),
                    'property' => 'P460',
                    'propertyLabel' => 'said to be the same as',
                    'value' => 'new item',
                    'reason' => $sameSpelling ? 'same folded spelling or transliteration' : 'related surname spelling found in Wikidata search results',
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

            if (
                $foldedLabel === $foldedName
                && $this->isFamilyNameType($selectedType)
                && $this->hasGivenNameInstance($matchInstances)
            ) {
                $suggestions[] = [
                    'target' => $id,
                    'targetLabel' => $label,
                    'targetTypes' => $this->instanceLabels($matchInstances),
                    'property' => 'P1533',
                    'propertyLabel' => 'given name equivalent',
                    'value' => 'new item',
                    'direction' => 'target',
                    'reason' => 'This statement is stored on the given-name item. After saving this family name, the given-name item will be updated to point to it.',
                ];
            }

            if (
                $this->isGivenNameType($selectedType)
                && $selectedScriptQid !== ''
                && in_array($selectedScriptQid, $scripts[$id] ?? [], true)
                && $this->isOtherGenderGivenName($selectedType, $matchInstances)
            ) {
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

        if ($this->isGivenNameType($selectedType)) {
            foreach ($this->genderedVariantMatches($name, $selectedType, $matches) as $variant) {
                $variantInstances = $variant['instances'];
                if (
                    $selectedScriptQid !== ''
                    && in_array($selectedScriptQid, $variant['scripts'] ?? [], true)
                    && $this->isOtherGenderGivenName($selectedType, $variantInstances)
                ) {
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
        if ($this->isFamilyNameType($selectedType) && $this->hasIcelandicDottirHint($affixes)) {
            foreach ($this->genderedVariantMatches($name, $selectedType, $matches, true) as $variant) {
                $variantInstances = $variant['instances'];
                if ($this->hasFamilyNameInstance($variantInstances)) {
                    $suggestions[] = [
                        'target' => $variant['id'],
                        'targetLabel' => $variant['label'],
                        'targetTypes' => $this->instanceLabels($variantInstances),
                        'property' => 'P5278',
                        'propertyLabel' => 'surname for other gender',
                        'value' => 'new item',
                        'reason' => 'Icelandic -dóttir family name counterpart',
                        'checked' => true,
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
     * @param list<array<string, string>> $affixes
     */
    private function isRelatedFamilyNameCandidate(string $name, string $label, array $affixes): bool
    {
        $nameVariants = $this->familyNameComparisonVariants($name, $affixes);
        $labelVariants = $this->familyNameComparisonVariants($label, []);

        foreach ($nameVariants as $nameVariant) {
            $foldedNameVariant = $this->foldName($nameVariant);
            foreach ($labelVariants as $labelVariant) {
                $foldedLabelVariant = $this->foldName($labelVariant);
                if ($foldedNameVariant !== '' && $foldedNameVariant === $foldedLabelVariant) {
                    return true;
                }
                if (min(strlen($foldedNameVariant), strlen($foldedLabelVariant)) >= 6 && levenshtein($foldedNameVariant, $foldedLabelVariant) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, string>> $affixes
     * @return list<string>
     */
    private function familyNameComparisonVariants(string $name, array $affixes): array
    {
        $variants = [trim(preg_replace('/\s+/u', ' ', $name) ?? $name)];
        foreach ($this->affixlessFamilyNameVariants($name, $affixes) as $variant) {
            $variants[] = $variant;
        }
        foreach ($variants as $variant) {
            foreach ($this->familyNameSpellingVariants($variant) as $spellingVariant) {
                $variants[] = $spellingVariant;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * @return list<string>
     */
    private function familyNameSpellingVariants(string $name): array
    {
        $variants = [];

        if (preg_match('/ff$/iu', $name) === 1) {
            $variants[] = preg_replace('/ff$/iu', 'f', $name) ?? $name;
        } elseif (preg_match('/f$/iu', $name) === 1) {
            $variants[] = $name . 'f';
        }

        if (preg_match('/mann$/iu', $name) === 1) {
            $variants[] = preg_replace('/mann$/iu', 'man', $name) ?? $name;
        } elseif (preg_match('/man$/iu', $name) === 1) {
            $variants[] = $name . 'n';
        }

        return array_values(array_unique(array_filter(
            $variants,
            static fn (string $variant): bool => $variant !== $name
        )));
    }

    /**
     * @param list<array{id: string, label: string, description: string}> $currentMatches
     * @return list<array{id: string, label: string, description: string, instances: list<string>, scripts: list<string>}>
     */
    private function genderedVariantMatches(string $name, string $selectedType, array $currentMatches, bool $exactLabel = false): array
    {
        $variants = $this->genderedNameVariants($name, $selectedType);
        if (!$variants) {
            return [];
        }

        $currentIds = array_flip(array_column($currentMatches, 'id'));
        $out = [];
        foreach ($variants as $variant) {
            foreach ($this->wikidataClient->searchItems($variant, 'en', 5) as $match) {
                $labelMatches = $exactLabel
                    ? mb_strtolower((string) $match['label']) === mb_strtolower($variant)
                    : $this->foldName($match['label']) === $this->foldName($variant);
                if (isset($currentIds[$match['id']]) || !$labelMatches) {
                    continue;
                }
                $out[$match['id']] = $match;
            }
        }

        if (!$out) {
            return [];
        }

        $instances = $this->wikidataClient->instanceOf(array_keys($out));
        $scripts = $this->wikidataClient->itemClaims(array_keys($out), 'P282');

        return array_values(array_map(static fn (array $match): array => $match + [
            'instances' => $instances[$match['id']] ?? [],
            'scripts' => $scripts[$match['id']] ?? [],
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
            $icelandicSonVariant = $this->icelandicDottirToSonVariant($name);
            if ($icelandicSonVariant !== null) {
                $variants[] = $icelandicSonVariant;
            }
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

    private function icelandicDottirToSonVariant(string $name): ?string
    {
        $variant = preg_replace('/(?:dottir|d\x{00F3}ttir)$/iu', 'son', $name);

        return is_string($variant) && $variant !== $name ? $variant : null;
    }

    /**
     * @param list<array<string, string>> $affixes
     */
    private function hasIcelandicDottirHint(array $affixes): bool
    {
        foreach ($affixes as $affix) {
            if (($affix['kind'] ?? '') === 'language' && ($affix['group'] ?? '') === 'is' && str_contains((string) ($affix['value'] ?? ''), 'dottir')) {
                return true;
            }
            if (($affix['kind'] ?? '') === 'language' && ($affix['group'] ?? '') === 'is' && str_contains((string) ($affix['value'] ?? ''), 'dóttir')) {
                return true;
            }
        }

        return false;
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
     * @param list<string> $instances
     */
    private function hasGivenNameInstance(array $instances): bool
    {
        return array_intersect(NameTypes::COMPATIBLE_TYPE_ITEMS[NameTypes::GIVEN_NAME], $instances) !== [];
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
