<?php

namespace App\Service;

use App\Data\NameTypes;
use RuntimeException;

final class WikidataEditService
{
    public function __construct(
        private readonly WikimediaOAuthClient $oauthClient,
        private readonly DescriptionSet $descriptionSet,
        private readonly ScriptLanguageLookup $scriptLanguages,
    ) {
    }

    /**
     * @param list<string> $apply
     * @param list<array{target: string, targetLabel: string, property: string, propertyLabel: string, value: string, reason: string, direction?: string, qualifierProperty?: string, qualifierValue?: string}> $relationships
     * @return array{entityId: string, mode: string, relatedUpdates: int, inverseRelationshipUpdates: int, warnings: list<string>}
     */
    public function save(string $name, string $displayLabel, string $type, ?string $scriptQid, ?string $languageQid, string $nativeLabelLanguage, ?string $existingItem, array $apply, array $relationships, bool $updateTransliteration = false): array
    {
        $token = $this->oauthClient->getCsrfToken();
        $entityId = $existingItem ?: null;
        $existingClaims = $entityId ? $this->existingClaims($entityId) : [];
        $data = ['claims' => []];
        if (!$entityId) {
            $data['labels'] = $this->labels($name, $displayLabel, $scriptQid);
        } elseif ($updateTransliteration) {
            $data['labels'] = [
                'mul' => ['language' => 'mul', 'value' => $displayLabel],
            ];
        }
        if (!$entityId || in_array('overwrite_descriptions', $apply, true)) {
            $data['descriptions'] = $this->descriptions($type, $name, $name);
        }

        $typeQid = NameTypes::TYPE_ITEMS[$type] ?? NameTypes::TYPE_ITEMS[NameTypes::GIVEN_NAME];
        if (!$this->hasCompatibleTypeClaim($existingClaims, $type)) {
            $data['claims'][] = $this->itemClaim('P31', $typeQid);
        }
        if ($scriptQid && !$this->hasItemClaim($existingClaims, 'P282', $scriptQid)) {
            $data['claims'][] = $this->itemClaim('P282', $scriptQid);
        }
        if (!$this->hasAnyMonolingualTextClaim($existingClaims, 'P1705')) {
            $data['claims'][] = $this->monolingualTextClaim('P1705', $name, $nativeLabelLanguage !== '' ? $nativeLabelLanguage : 'mul');
        }
        if (
            $languageQid
            && in_array('claim_P407', $apply, true)
            && !$this->hasItemClaim($existingClaims, 'P407', $languageQid)
        ) {
            $data['claims'][] = $this->itemClaim('P407', $languageQid);
        }
        foreach ($this->appliedItemClaims($apply, 'P7377') as $infixQid) {
            if (!$this->hasItemClaim($existingClaims, 'P7377', $infixQid)) {
                $data['claims'][] = $this->itemClaim('P7377', $infixQid);
            }
        }
        foreach ($relationships as $relationship) {
            $applyKey = 'related_' . $relationship['target'] . '_' . $relationship['property'];
            if (!in_array($applyKey, $apply, true) || !$this->isSupportedNameProperty($relationship['property'])) {
                continue;
            }
            if (($relationship['direction'] ?? '') === 'target') {
                continue;
            }
            if ($this->hasItemClaim($existingClaims, $relationship['property'], $relationship['target'])) {
                continue;
            }
            $data['claims'][] = $this->relationshipClaim($relationship, $relationship['target']);
        }

        $params = [
            'action' => 'wbeditentity',
            'format' => 'json',
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'token' => $token,
            'summary' => '[[Help:New Name|New Name]]: create or update name item',
        ];

        if ($entityId) {
            $params['id'] = $entityId;
            $mode = 'updated';
        } else {
            $params['new'] = 'item';
            $mode = 'created';
        }

        $result = $this->oauthClient->signedPost(WikimediaOAuthClient::WIKIDATA_API_URL, $params);
        $entityId = $result['entity']['id'] ?? $entityId;
        if (!is_string($entityId) || $entityId === '') {
            throw new RuntimeException('Wikidata edit succeeded but no entity id was returned.');
        }

        $relatedUpdates = 0;
        $inverseRelationshipUpdates = 0;
        $warnings = [];
        foreach ($relationships as $relationship) {
            $applyKey = 'related_' . $relationship['target'] . '_' . $relationship['property'];
            $targetDirected = ($relationship['direction'] ?? '') === 'target';
            if (
                !in_array($applyKey, $apply, true)
                || (!$targetDirected && !$this->isSymmetricNameProperty($relationship['property']))
                || ($targetDirected && $relationship['property'] !== 'P1533')
                || $relationship['target'] === $entityId
            ) {
                continue;
            }
            try {
                $targetClaims = $this->existingClaims($relationship['target']);
                if ($this->hasItemClaim($targetClaims, $relationship['property'], $entityId)) {
                    continue;
                }
                $this->oauthClient->signedPost(WikimediaOAuthClient::WIKIDATA_API_URL, [
                    'action' => 'wbeditentity',
                    'format' => 'json',
                    'id' => $relationship['target'],
                    'data' => json_encode(['claims' => [$this->relationshipClaim($relationship, $entityId)]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'token' => $token,
                    'summary' => '[[Help:New Name|New Name]]: link related name item',
                ]);
                $relatedUpdates++;
                if ($targetDirected) {
                    $inverseRelationshipUpdates++;
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Could not update ' . $relationship['target'] . ' with ' . $relationship['property'] . ': ' . $e->getMessage();
            }
        }

        return [
            'entityId' => $entityId,
            'mode' => $mode,
            'relatedUpdates' => $relatedUpdates,
            'inverseRelationshipUpdates' => $inverseRelationshipUpdates,
            'warnings' => $warnings,
        ];
    }

    private function isSymmetricNameProperty(string $property): bool
    {
        return in_array($property, ['P460', 'P1560', 'P5278', 'P1889'], true);
    }

    private function isSupportedNameProperty(string $property): bool
    {
        return $property === 'P1533' || $this->isSymmetricNameProperty($property);
    }

    /**
     * @return array<string, array{language: string, value: string}>
     */
    private function labels(string $name, string $displayLabel, ?string $scriptQid): array
    {
        $labels = [
            'mul' => ['language' => 'mul', 'value' => $displayLabel],
        ];

        foreach ($this->scriptLanguages->languageCodesForScript($scriptQid) as $scriptLanguage) {
            $labels[$scriptLanguage] = ['language' => $scriptLanguage, 'value' => $name];
        }

        return $labels;
    }

    /**
     * @param list<string> $apply
     * @return list<string>
     */
    private function appliedItemClaims(array $apply, string $property): array
    {
        $claims = [];
        $prefix = 'claim_' . $property . '_';
        foreach ($apply as $value) {
            if (!str_starts_with($value, $prefix)) {
                continue;
            }
            $qid = substr($value, strlen($prefix));
            if (preg_match('/^Q\d+$/', $qid)) {
                $claims[] = $qid;
            }
        }

        return array_values(array_unique($claims));
    }

    /**
     * @return array<string, mixed>
     */
    private function existingClaims(string $entityId): array
    {
        $data = $this->oauthClient->signedPost(WikimediaOAuthClient::WIKIDATA_API_URL, [
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => $entityId,
            'props' => 'claims',
        ]);

        $claims = $data['entities'][$entityId]['claims'] ?? [];

        return is_array($claims) ? $claims : [];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function hasItemClaim(array $claims, string $property, string $qid): bool
    {
        foreach ($claims[$property] ?? [] as $claim) {
            $value = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($value === $qid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function hasCompatibleTypeClaim(array $claims, string $type): bool
    {
        foreach (NameTypes::COMPATIBLE_TYPE_ITEMS[$type] ?? [NameTypes::TYPE_ITEMS[$type] ?? ''] as $qid) {
            if ($qid !== '' && $this->hasItemClaim($claims, 'P31', $qid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function hasAnyMonolingualTextClaim(array $claims, string $property): bool
    {
        return !empty($claims[$property] ?? []);
    }

    /**
     * @return array<string, array{language: string, value: string}>
     */
    private function descriptions(string $type, string $name, ?string $descriptionName): array
    {
        $descriptions = [];
        foreach ($this->descriptionSet->forType($type, $name, $descriptionName) as $language => $value) {
            $descriptions[$language] = ['language' => $language, 'value' => $value];
        }

        return $descriptions;
    }

    /**
     * @param array{property: string, qualifierProperty?: string, qualifierValue?: string} $relationship
     * @return array<string, mixed>
     */
    private function relationshipClaim(array $relationship, string $targetQid): array
    {
        $qualifiers = [];
        $qualifierProperty = (string) ($relationship['qualifierProperty'] ?? '');
        $qualifierValue = (string) ($relationship['qualifierValue'] ?? '');
        if (preg_match('/^P\d+$/', $qualifierProperty) && preg_match('/^Q\d+$/', $qualifierValue)) {
            $qualifiers[] = ['property' => $qualifierProperty, 'value' => $qualifierValue];
        }

        return $this->itemClaim($relationship['property'], $targetQid, $qualifiers);
    }

    /**
     * @param list<array{property: string, value: string}> $qualifiers
     * @return array<string, mixed>
     */
    private function itemClaim(string $property, string $qid, array $qualifiers = []): array
    {
        $claim = [
            'mainsnak' => $this->itemSnak($property, $qid),
            'type' => 'statement',
            'rank' => 'normal',
        ];

        foreach ($qualifiers as $qualifier) {
            $claim['qualifiers'][$qualifier['property']][] = $this->itemSnak($qualifier['property'], $qualifier['value']);
        }

        return $claim;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemSnak(string $property, string $qid): array
    {
        return [
            'snaktype' => 'value',
            'property' => $property,
            'datavalue' => [
                'value' => [
                    'entity-type' => 'item',
                    'numeric-id' => (int) substr($qid, 1),
                    'id' => $qid,
                ],
                'type' => 'wikibase-entityid',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function monolingualTextClaim(string $property, string $text, string $language): array
    {
        return [
            'mainsnak' => [
                'snaktype' => 'value',
                'property' => $property,
                'datavalue' => [
                    'value' => [
                        'text' => $text,
                        'language' => $language,
                    ],
                    'type' => 'monolingualtext',
                ],
            ],
            'type' => 'statement',
            'rank' => 'normal',
        ];
    }
}
