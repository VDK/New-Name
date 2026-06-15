<?php

namespace App\Service;

use App\Data\NameTypes;
use RuntimeException;

final class WikidataEditService
{
    public function __construct(
        private readonly WikimediaOAuthClient $oauthClient,
        private readonly DescriptionSet $descriptionSet,
    ) {
    }

    /**
     * @param list<string> $apply
     * @param list<array{target: string, targetLabel: string, property: string, propertyLabel: string, value: string, reason: string}> $relationships
     * @return array{entityId: string, mode: string, relatedUpdates: int, warnings: list<string>}
     */
    public function save(string $name, string $displayLabel, string $type, ?string $scriptQid, ?string $languageQid, string $nativeLabelLanguage, ?string $existingItem, array $apply, array $relationships): array
    {
        $token = $this->oauthClient->getCsrfToken();
        $entityId = $existingItem ?: null;
        $existingClaims = $entityId ? $this->existingClaims($entityId) : [];
        $data = [
            'labels' => $this->labels($name, $displayLabel, $scriptQid),
            'descriptions' => $this->descriptions($type, $name, $name),
            'claims' => [],
        ];

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
        if ($languageQid && in_array('claim_P407', $apply, true) && !$this->hasItemClaim($existingClaims, 'P407', $languageQid)) {
            $data['claims'][] = $this->itemClaim('P407', $languageQid);
        }
        foreach ($this->appliedItemClaims($apply, 'P7377') as $infixQid) {
            if (!$this->hasItemClaim($existingClaims, 'P7377', $infixQid)) {
                $data['claims'][] = $this->itemClaim('P7377', $infixQid);
            }
        }
        foreach ($relationships as $relationship) {
            $applyKey = 'related_' . $relationship['target'] . '_' . $relationship['property'];
            if (!in_array($applyKey, $apply, true) || !$this->isSymmetricNameProperty($relationship['property'])) {
                continue;
            }
            if ($this->hasItemClaim($existingClaims, $relationship['property'], $relationship['target'])) {
                continue;
            }
            $data['claims'][] = $this->itemClaim($relationship['property'], $relationship['target']);
        }

        $params = [
            'action' => 'wbeditentity',
            'format' => 'json',
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'token' => $token,
            'summary' => 'New Name: create or update name item',
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
        $warnings = [];
        if ($mode === 'created') {
            foreach ($relationships as $relationship) {
                $applyKey = 'related_' . $relationship['target'] . '_' . $relationship['property'];
                if (!in_array($applyKey, $apply, true)) {
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
                        'data' => json_encode(['claims' => [$this->itemClaim($relationship['property'], $entityId)]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'token' => $token,
                        'summary' => 'New Name: link related name item',
                    ]);
                    $relatedUpdates++;
                } catch (\Throwable $e) {
                    $warnings[] = 'Could not update ' . $relationship['target'] . ' with ' . $relationship['property'] . ': ' . $e->getMessage();
                }
            }
        }

        return ['entityId' => $entityId, 'mode' => $mode, 'relatedUpdates' => $relatedUpdates, 'warnings' => $warnings];
    }

    private function isSymmetricNameProperty(string $property): bool
    {
        return in_array($property, ['P460', 'P1560', 'P5278'], true);
    }

    /**
     * @return array<string, array{language: string, value: string}>
     */
    private function labels(string $name, string $displayLabel, ?string $scriptQid): array
    {
        $labels = [
            'mul' => ['language' => 'mul', 'value' => $displayLabel],
        ];

        foreach ($this->languageCodesForScript($scriptQid) as $scriptLanguage) {
            $labels[$scriptLanguage] = ['language' => $scriptLanguage, 'value' => $name];
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    private function languageCodesForScript(?string $scriptQid): array
    {
        return match ($scriptQid) {
            'Q8209' => ['ba', 'be', 'bg', 'ce', 'cv', 'kk', 'kk-cyrl', 'ky', 'mk', 'mn', 'mhr', 'myv', 'os', 'ru', 'rue', 'sah', 'sr', 'sr-ec', 'tg', 'tg-cyrl', 'tt-cyrl', 'udm', 'uk'],
            'Q8196' => ['ar', 'ary', 'arz', 'ckb', 'fa', 'kk-arab', 'ks-arab', 'ku-arab', 'pnb', 'ps', 'sd', 'ug-arab', 'ur'],
            'Q33513' => ['he', 'yi'],
            'Q8222' => ['ko'],
            'Q38592' => ['hi', 'ks-deva', 'mai', 'mr', 'ne', 'new', 'sa'],
            'Q8216' => ['el'],
            'Q8301' => ['ka'],
            'Q8221' => ['hy'],
            'Q8201' => ['cdo', 'gan', 'gan-hans', 'gan-hant', 'lzh', 'nan', 'wuu', 'yue', 'zh', 'zh-classical', 'zh-cn', 'zh-hans', 'zh-hant', 'zh-hk', 'zh-min-nan', 'zh-mo', 'zh-my', 'zh-sg', 'zh-tw', 'zh-yue'],
            'Q48332', 'Q82946' => ['ja'],
            default => [],
        };
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
     * @return array<string, mixed>
     */
    private function itemClaim(string $property, string $qid): array
    {
        return [
            'mainsnak' => [
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
            ],
            'type' => 'statement',
            'rank' => 'normal',
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
