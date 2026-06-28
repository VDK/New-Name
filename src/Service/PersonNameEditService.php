<?php

namespace App\Service;

use RuntimeException;

final class PersonNameEditService
{
    public function __construct(private readonly WikimediaOAuthClient $oauthClient)
    {
    }

    /**
     * @return array{changed: bool}
     */
    public function add(string $personId, string $nameItemId, string $property, ?string $ordinal): array
    {
        if (!preg_match('/^Q\d+$/', $personId) || !preg_match('/^Q\d+$/', $nameItemId)) {
            throw new RuntimeException('Invalid Wikidata item.');
        }
        if (!in_array($property, ['P735', 'P734', 'P1950', 'P9139'], true)) {
            throw new RuntimeException('Unsupported name property.');
        }
        if ($property !== 'P735') {
            $ordinal = null;
        }
        if ($ordinal !== null && !preg_match('/^[1-9]\d*$/', $ordinal)) {
            throw new RuntimeException('Series ordinal must be a positive number.');
        }

        $claims = $this->existingClaims($personId);
        if (!$this->hasItemClaim($claims, 'P31', WikidataClient::HUMAN)) {
            throw new RuntimeException('The target item is not a human.');
        }
        if ($this->hasClaim($claims, $property, $nameItemId, $ordinal)) {
            return ['changed' => false];
        }

        $claim = [
            'mainsnak' => $this->itemSnak($property, $nameItemId),
            'type' => 'statement',
            'rank' => 'normal',
            'references' => [[
                'snaks' => [
                    'P887' => [
                        $this->itemSnak('P887', 'Q97033143'),
                    ],
                ],
                'snaks-order' => ['P887'],
            ]],
        ];
        if ($ordinal !== null) {
            $claim['qualifiers']['P1545'][] = [
                'snaktype' => 'value',
                'property' => 'P1545',
                'datavalue' => ['value' => $ordinal, 'type' => 'string'],
            ];
        }

        $this->oauthClient->signedPost(WikimediaOAuthClient::WIKIDATA_API_URL, [
            'action' => 'wbeditentity',
            'format' => 'json',
            'id' => $personId,
            'data' => json_encode(['claims' => [$claim]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'token' => $this->oauthClient->getCsrfToken(),
            'summary' => '[[Help:New Name|New Name]]: add name to person',
        ]);

        return ['changed' => true];
    }

    /**
     * @return array{changed: bool}
     */
    public function remove(string $personId, string $nameItemId, string $property, ?string $ordinal): array
    {
        if (!preg_match('/^Q\d+$/', $personId) || !preg_match('/^Q\d+$/', $nameItemId)) {
            throw new RuntimeException('Invalid Wikidata item.');
        }
        if (!in_array($property, ['P735', 'P734', 'P1950', 'P9139'], true)) {
            throw new RuntimeException('Unsupported name property.');
        }
        if ($property !== 'P735') {
            $ordinal = null;
        }
        if ($ordinal !== null && !preg_match('/^[1-9]\d*$/', $ordinal)) {
            throw new RuntimeException('Series ordinal must be a positive number.');
        }

        $claimId = $this->newNameClaimId($this->existingClaims($personId), $property, $nameItemId, $ordinal);
        if ($claimId === null) {
            return ['changed' => false];
        }

        $this->oauthClient->signedPost(WikimediaOAuthClient::WIKIDATA_API_URL, [
            'action' => 'wbremoveclaims',
            'format' => 'json',
            'claim' => $claimId,
            'token' => $this->oauthClient->getCsrfToken(),
            'summary' => '[[Help:New Name|New Name]]: undo adding name to person',
        ]);

        return ['changed' => true];
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
    private function hasClaim(array $claims, string $property, string $nameItemId, ?string $ordinal): bool
    {
        foreach ($claims[$property] ?? [] as $claim) {
            if (($claim['mainsnak']['datavalue']['value']['id'] ?? '') !== $nameItemId) {
                continue;
            }
            if ($ordinal === null) {
                return true;
            }
            foreach ($claim['qualifiers']['P1545'] ?? [] as $qualifier) {
                if (($qualifier['datavalue']['value'] ?? '') === $ordinal) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function newNameClaimId(array $claims, string $property, string $nameItemId, ?string $ordinal): ?string
    {
        foreach ($claims[$property] ?? [] as $claim) {
            if (($claim['mainsnak']['datavalue']['value']['id'] ?? '') !== $nameItemId) {
                continue;
            }
            if ($ordinal !== null && !$this->claimHasOrdinal($claim, $ordinal)) {
                continue;
            }
            if (!$this->hasNewNameReference($claim)) {
                continue;
            }
            $claimId = (string) ($claim['id'] ?? '');
            if ($claimId !== '') {
                return $claimId;
            }
        }

        return null;
    }

    private function claimHasOrdinal(array $claim, string $ordinal): bool
    {
        foreach ($claim['qualifiers']['P1545'] ?? [] as $qualifier) {
            if (($qualifier['datavalue']['value'] ?? '') === $ordinal) {
                return true;
            }
        }

        return false;
    }

    private function hasNewNameReference(array $claim): bool
    {
        foreach ($claim['references'] ?? [] as $reference) {
            foreach ($reference['snaks']['P887'] ?? [] as $snak) {
                if (($snak['datavalue']['value']['id'] ?? '') === 'Q97033143') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function hasItemClaim(array $claims, string $property, string $itemId): bool
    {
        foreach ($claims[$property] ?? [] as $claim) {
            if (($claim['mainsnak']['datavalue']['value']['id'] ?? '') === $itemId) {
                return true;
            }
        }

        return false;
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
}
