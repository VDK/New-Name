<?php

namespace App\Service;

use App\Data\LanguageHeuristics;

final class WikidataClient
{
    public const HUMAN = 'Q5';

    /**
     * @return array<int, array{id: string, label: string, description: string}>
     */
    public function searchItems(string $search, string $language = 'en', int $limit = 10): array
    {
        if (trim($search) === '') {
            return [];
        }

        $items = [];
        $seen = [];
        foreach ($this->searchLanguages($language) as $searchLanguage) {
            $data = $this->api([
                'action' => 'wbsearchentities',
                'format' => 'json',
                'type' => 'item',
                'search' => $search,
                'language' => $searchLanguage,
                'uselang' => $searchLanguage,
                'limit' => max(1, min(50, $limit)),
            ]);

            foreach ($data['search'] ?? [] as $hit) {
                $id = (string) ($hit['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $items[] = [
                    'id' => $id,
                    'label' => (string) ($hit['label'] ?? ''),
                    'description' => (string) ($hit['description'] ?? ''),
                ];
            }
        }

        return array_slice($items, 0, max(1, min(50, $limit)));
    }

    /**
     * @return list<string>
     */
    private function searchLanguages(string $language): array
    {
        $languages = [$language];
        if ($language === 'en') {
            $languages[] = 'nl';
        }

        return array_values(array_unique(array_filter($languages)));
    }

    /**
     * @param list<string> $ids
     * @return array<string, list<string>>
     */
    public function instanceOf(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => preg_match('/^Q\d+$/', $id) === 1));
        if (!$ids) {
            return [];
        }
        sort($ids, SORT_NATURAL);

        $data = $this->api([
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => implode('|', $ids),
            'props' => 'claims',
        ]);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
            foreach ($data['entities'][$id]['claims']['P31'] ?? [] as $claim) {
                $value = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
                if (is_string($value)) {
                    $out[$id][] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return array{instances: array<string, list<string>>, scripts: array<string, list<string>>, nativeLabels: array<string, list<string>>, mulLabels: array<string, string>}
     */
    public function itemAnalysisData(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => preg_match('/^Q\d+$/', $id) === 1));
        $out = [
            'instances' => [],
            'scripts' => [],
            'nativeLabels' => [],
            'mulLabels' => [],
        ];
        if (!$ids) {
            return $out;
        }
        sort($ids, SORT_NATURAL);

        $data = $this->api([
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => implode('|', $ids),
            'props' => 'labels|claims',
            'languages' => 'mul',
        ]);

        foreach ($ids as $id) {
            $entity = $data['entities'][$id] ?? [];
            $out['instances'][$id] = $this->claimItemIds($entity['claims']['P31'] ?? []);
            $out['scripts'][$id] = $this->claimItemIds($entity['claims']['P282'] ?? []);
            $out['nativeLabels'][$id] = [];
            foreach ($entity['claims']['P1705'] ?? [] as $claim) {
                $value = $claim['mainsnak']['datavalue']['value']['text'] ?? null;
                if (is_string($value) && $value !== '') {
                    $out['nativeLabels'][$id][] = $value;
                }
            }
            $out['nativeLabels'][$id] = array_values(array_unique($out['nativeLabels'][$id]));
            $out['mulLabels'][$id] = (string) ($entity['labels']['mul']['value'] ?? '');
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return array<string, list<string>>
     */
    public function itemClaims(array $ids, string $property): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => preg_match('/^Q\d+$/', $id) === 1));
        if (!$ids || !preg_match('/^P\d+$/', $property)) {
            return [];
        }
        sort($ids, SORT_NATURAL);

        $data = $this->api([
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => implode('|', $ids),
            'props' => 'claims',
        ]);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
            foreach ($data['entities'][$id]['claims'][$property] ?? [] as $claim) {
                $value = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
                if (is_string($value)) {
                    $out[$id][] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return array<string, list<string>>
     */
    public function nativeLabels(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => preg_match('/^Q\d+$/', $id) === 1));
        if (!$ids) {
            return [];
        }
        sort($ids, SORT_NATURAL);

        $data = $this->api([
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => implode('|', $ids),
            'props' => 'claims',
        ]);

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
            foreach ($data['entities'][$id]['claims']['P1705'] ?? [] as $claim) {
                $value = $claim['mainsnak']['datavalue']['value']['text'] ?? null;
                if (is_string($value) && $value !== '') {
                    $out[$id][] = $value;
                }
            }
            $out[$id] = array_values(array_unique($out[$id]));
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return array<string, string>
     */
    public function mulLabels(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => preg_match('/^Q\d+$/', $id) === 1));
        if (!$ids) {
            return [];
        }

        $data = $this->api([
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => implode('|', $ids),
            'props' => 'labels',
            'languages' => 'mul',
        ]);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = (string) ($data['entities'][$id]['labels']['mul']['value'] ?? '');
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     * @return array<string, list<string>>
     */
    public function terms(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (string $id): bool => preg_match('/^Q\d+$/', $id) === 1));
        if (!$ids) {
            return [];
        }
        sort($ids, SORT_NATURAL);

        $data = $this->api([
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => implode('|', $ids),
            'props' => 'labels|aliases',
        ]);

        $out = [];
        foreach ($ids as $id) {
            $terms = [];
            foreach ($data['entities'][$id]['labels'] ?? [] as $label) {
                if (is_array($label) && is_string($label['value'] ?? null)) {
                    $terms[] = $label['value'];
                }
            }
            foreach ($data['entities'][$id]['aliases'] ?? [] as $aliases) {
                foreach ($aliases as $alias) {
                    if (is_array($alias) && is_string($alias['value'] ?? null)) {
                        $terms[] = $alias['value'];
                    }
                }
            }
            $out[$id] = array_values(array_unique($terms));
        }

        return $out;
    }

    /**
     * @return array{id: string, label: string, description: string, type: string, script: string, languageCode: string, instanceOf: list<string>, nativeLabels: list<string>, mulLabel: string, sameAs: list<string>, equivalents: list<array{id: string, label: string, description: string, type: string, nativeLabel: string, script: string, scriptLabel: string, sameAs: list<string>}>}|null
     */
    public function nameItem(string $id, bool $fresh = false): ?array
    {
        if (!preg_match('/^Q\d+$/', $id)) {
            return null;
        }

        $data = $this->api([
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => $id,
            'props' => 'labels|descriptions|claims',
            'languages' => 'mul|en',
            'languagefallback' => 1,
        ], $fresh ? 0 : 3600);
        $entity = $data['entities'][$id] ?? null;
        if (!is_array($entity) || isset($entity['missing'])) {
            return null;
        }

        $type = '';
        $instanceOf = [];
        foreach ($entity['claims']['P31'] ?? [] as $claim) {
            $qid = $claim['mainsnak']['datavalue']['value']['id'] ?? '';
            if (is_string($qid) && preg_match('/^Q\d+$/', $qid)) {
                $instanceOf[] = $qid;
            }
            if (is_string($qid) && in_array($qid, ['Q101352', 'Q66480858', 'Q60558422'], true)) {
                $type = 'family';
                break;
            }
            if (is_string($qid) && in_array($qid, ['Q202444', 'Q12308941', 'Q11879590', 'Q3409032'], true)) {
                $type = 'given';
            }
        }
        if ($type === '') {
            return null;
        }

        $nativeLabel = '';
        foreach ($entity['claims']['P1705'] ?? [] as $claim) {
            $text = $claim['mainsnak']['datavalue']['value']['text'] ?? '';
            if (is_string($text) && $text !== '') {
                $nativeLabel = $text;
                break;
            }
        }
        $mulLabel = (string) ($entity['labels']['mul']['value'] ?? '');
        $label = $mulLabel !== '' ? $mulLabel : ($nativeLabel ?: (string) ($entity['labels']['en']['value'] ?? ''));
        $script = (string) ($entity['claims']['P282'][0]['mainsnak']['datavalue']['value']['id'] ?? '');
        $languageQid = (string) ($entity['claims']['P407'][0]['mainsnak']['datavalue']['value']['id'] ?? '');
        $equivalentIds = [];
        $inferredEquivalentIds = [];
        if ($type === 'given') {
            foreach ($entity['claims']['P1533'] ?? [] as $claim) {
                $equivalentId = (string) ($claim['mainsnak']['datavalue']['value']['id'] ?? '');
                if (preg_match('/^Q\d+$/', $equivalentId)) {
                    $equivalentIds[$equivalentId] = $equivalentId;
                }
            }
            if ($equivalentIds === []) {
                foreach ($this->exactNameMatches($label, 'Q101352', [], [], false) as $match) {
                    $equivalentId = (string) ($match['id'] ?? '');
                    if (preg_match('/^Q\d+$/', $equivalentId)) {
                        $equivalentIds[$equivalentId] = $equivalentId;
                        $inferredEquivalentIds[$equivalentId] = true;
                    }
                }
            }
        } else {
            $search = $this->api([
                'action' => 'query',
                'format' => 'json',
                'list' => 'search',
                'srsearch' => 'haswbstatement:P1533=' . $id,
                'srnamespace' => 0,
                'srlimit' => 10,
                'srprop' => '',
            ], $fresh ? 0 : 3600);
            foreach ($search['query']['search'] ?? [] as $hit) {
                $equivalentId = (string) ($hit['title'] ?? '');
                if (preg_match('/^Q\d+$/', $equivalentId)) {
                    $equivalentIds[$equivalentId] = $equivalentId;
                    $inferredEquivalentIds[$equivalentId] = true;
                }
            }
            foreach ($this->exactNameMatches($label, 'Q202444', [], [], false) as $match) {
                $equivalentId = (string) ($match['id'] ?? '');
                if (preg_match('/^Q\d+$/', $equivalentId)) {
                    $equivalentIds[$equivalentId] = $equivalentId;
                    $inferredEquivalentIds[$equivalentId] = true;
                }
            }
        }

        $equivalents = [];
        if ($equivalentIds) {
            $equivalentData = $this->api([
                'action' => 'wbgetentities',
                'format' => 'json',
                'ids' => implode('|', $equivalentIds),
                'props' => 'labels|descriptions|claims',
                'languages' => 'mul|en',
                'languagefallback' => 1,
            ]);
            foreach ($equivalentIds as $equivalentId) {
                $equivalentEntity = $equivalentData['entities'][$equivalentId] ?? [];
                if (
                    isset($inferredEquivalentIds[$equivalentId])
                    && !$this->isMatchingNameEquivalent(
                        $equivalentEntity,
                        [$label, $mulLabel, $nativeLabel],
                        $script,
                        $type === 'given' ? 'family' : 'given'
                    )
                ) {
                    continue;
                }
                $equivalentType = $type === 'given' ? 'family' : 'given';
                $equivalentDescription = (string) ($equivalentEntity['descriptions']['mul']['value']
                    ?? $equivalentEntity['descriptions']['en']['value']
                    ?? '');
                if ($equivalentDescription === '') {
                    $equivalentDescription = $this->nameTypeDescription(
                        $equivalentEntity['claims']['P31'] ?? [],
                        $equivalentType
                    );
                }
                $equivalentNativeLabel = '';
                foreach ($equivalentEntity['claims']['P1705'] ?? [] as $claim) {
                    $text = (string) ($claim['mainsnak']['datavalue']['value']['text'] ?? '');
                    if ($text !== '') {
                        $equivalentNativeLabel = $text;
                        break;
                    }
                }
                $equivalentScript = (string) ($equivalentEntity['claims']['P282'][0]['mainsnak']['datavalue']['value']['id'] ?? '');
                $equivalents[] = [
                    'id' => $equivalentId,
                    'label' => (string) ($equivalentEntity['labels']['mul']['value']
                        ?? $equivalentEntity['labels']['en']['value']
                        ?? $equivalentId),
                    'description' => $equivalentDescription,
                    'type' => $equivalentType,
                    'nativeLabel' => $equivalentNativeLabel,
                    'script' => $equivalentScript,
                    'scriptLabel' => $this->scriptName($equivalentScript),
                    'sameAs' => $this->claimItemIds($equivalentEntity['claims']['P460'] ?? []),
                ];
                if ($type === 'family' || isset($inferredEquivalentIds[$equivalentId])) {
                    break;
                }
            }
        }

        return [
            'id' => $id,
            'label' => $label,
            'description' => (string) ($entity['descriptions']['mul']['value'] ?? $entity['descriptions']['en']['value'] ?? ''),
            'type' => $type,
            'script' => $script,
            'languageCode' => $this->languageCode($languageQid),
            'instanceOf' => array_values(array_unique($instanceOf)),
            'nativeLabels' => $nativeLabel !== '' ? [$nativeLabel] : [],
            'mulLabel' => $mulLabel,
            'sameAs' => $this->claimItemIds($entity['claims']['P460'] ?? []),
            'equivalents' => $equivalents,
        ];
    }

    /**
     * @param array<string, mixed> $entity
     * @param list<string> $sourceLabels
     */
    private function isMatchingNameEquivalent(array $entity, array $sourceLabels, string $sourceScript, string $candidateType): bool
    {
        $compatibleTypes = $candidateType === 'family'
            ? ['Q101352', 'Q66480858', 'Q60558422']
            : ['Q202444', 'Q12308941', 'Q11879590', 'Q3409032'];
        $candidateTypes = $this->claimItemIds($entity['claims']['P31'] ?? []);
        if (array_intersect($compatibleTypes, $candidateTypes) === []) {
            return false;
        }

        $candidateScript = (string) ($entity['claims']['P282'][0]['mainsnak']['datavalue']['value']['id'] ?? '');
        if ($sourceScript === '' || $candidateScript === '' || $candidateScript !== $sourceScript) {
            return false;
        }

        $candidateLabels = [
            (string) ($entity['labels']['mul']['value'] ?? ''),
            (string) ($entity['labels']['en']['value'] ?? ''),
        ];
        foreach ($entity['claims']['P1705'] ?? [] as $claim) {
            $candidateLabels[] = (string) ($claim['mainsnak']['datavalue']['value']['text'] ?? '');
        }

        $normalize = static function (string $value): string {
            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

            return mb_strtolower($value);
        };
        $normalizedSourceLabels = array_values(array_unique(array_filter(array_map($normalize, $sourceLabels))));
        $normalizedCandidateLabels = array_values(array_unique(array_filter(array_map($normalize, $candidateLabels))));

        return array_intersect($normalizedSourceLabels, $normalizedCandidateLabels) !== [];
    }

    /**
     * @return list<string>
     */
    private function claimItemIds(array $claims): array
    {
        $ids = [];
        foreach ($claims as $claim) {
            $id = (string) ($claim['mainsnak']['datavalue']['value']['id'] ?? '');
            if (preg_match('/^Q\d+$/', $id)) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function nameTypeDescription(array $instanceClaims, string $fallbackType): string
    {
        foreach ($instanceClaims as $claim) {
            $qid = (string) ($claim['mainsnak']['datavalue']['value']['id'] ?? '');
            $label = [
                'Q11879590' => 'female given name',
                'Q12308941' => 'male given name',
                'Q3409032' => 'unisex given name',
                'Q202444' => 'given name',
                'Q101352' => 'family name',
                'Q66480858' => 'affixed family name',
                'Q60558422' => 'Chinese family name',
            ][$qid] ?? '';
            if ($label !== '') {
                return $label;
            }
        }

        return $fallbackType === 'given' ? 'given name' : 'family name';
    }

    private function scriptName(string $qid): string
    {
        return [
            'Q8201' => 'Chinese characters',
            'Q8229' => 'Latin script',
            'Q8209' => 'Cyrillic script',
            'Q8196' => 'Arabic script',
            'Q33513' => 'Hebrew alphabet',
            'Q8222' => 'Hangul',
            'Q48332' => 'hiragana',
            'Q82946' => 'katakana',
            'Q38592' => 'Devanagari',
            'Q8216' => 'Greek alphabet',
            'Q8301' => 'Georgian scripts',
            'Q8221' => 'Armenian alphabet',
        ][$qid] ?? $qid;
    }

    /**
     * @return list<array{id: string, label: string, type: string, script: string, languageCode: string, description: string}>
     */
    public function searchNameItems(string $query, string $language = 'en', int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $types = [
            'Q202444' => 'given',
            'Q12308941' => 'given',
            'Q11879590' => 'given',
            'Q3409032' => 'given',
            'Q101352' => 'family',
            'Q66480858' => 'family',
            'Q60558422' => 'family',
        ];
        $matches = [];
        foreach ($types as $instanceQid => $type) {
            $data = $this->api([
                'action' => 'query',
                'format' => 'json',
                'list' => 'search',
                'srsearch' => $query . ' haswbstatement:P31=' . $instanceQid,
                'srnamespace' => 0,
                'srlimit' => max(1, min(5, $limit)),
                'srprop' => 'titlesnippet|snippet',
            ]);
            foreach ($data['query']['search'] ?? [] as $rank => $hit) {
                $id = (string) ($hit['title'] ?? '');
                if (!preg_match('/^Q\d+$/', $id) || isset($matches[$id])) {
                    continue;
                }
                $matches[$id] = [
                    'type' => $type,
                    'rank' => (int) $rank,
                    'label' => $this->searchResultText((string) ($hit['titlesnippet'] ?? '')) ?: $query,
                    'description' => $this->searchResultText((string) ($hit['snippet'] ?? '')),
                ];
            }
        }
        if (!$matches) {
            return [];
        }

        $out = [];
        foreach ($matches as $id => $match) {
            $out[] = [
                'id' => $id,
                'label' => $match['label'],
                'type' => $match['type'],
                'script' => '',
                'languageCode' => '',
                'description' => $match['description'],
                '_rank' => $match['rank'],
            ];
        }

        $foldedQuery = mb_strtolower($query);
        usort($out, static function (array $a, array $b) use ($foldedQuery): int {
            $aLabel = mb_strtolower($a['label']);
            $bLabel = mb_strtolower($b['label']);
            $aScore = $aLabel === $foldedQuery ? 0 : (str_starts_with($aLabel, $foldedQuery) ? 1 : 2);
            $bScore = $bLabel === $foldedQuery ? 0 : (str_starts_with($bLabel, $foldedQuery) ? 1 : 2);

            return ($aScore <=> $bScore)
                ?: ($a['_rank'] <=> $b['_rank'])
                ?: strnatcasecmp($a['label'], $b['label']);
        });
        $out = array_slice($out, 0, $limit);
        foreach ($out as &$item) {
            unset($item['_rank']);
        }
        unset($item);

        return $out;
    }

    private function searchResultText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return list<array{id: string, label: string, description: string, matchedLabel: string}>
     */
    public function personMatches(
        string $query,
        string $name,
        string $scriptQid,
        string $languageCode = 'en',
        ?string $nameItemId = null,
        ?string $nameType = null,
        int $limit = 12,
        int $offset = 0,
        array $excludedIds = [],
        array $equivalents = [],
        array $sameAs = [],
    ): array
    {
        return $this->personMatchesPage(
            $query,
            $name,
            $scriptQid,
            $languageCode,
            $nameItemId,
            $nameType,
            $limit,
            $offset,
            $excludedIds,
            $equivalents,
            $sameAs
        )['matches'];
    }

    /**
     * @return array{matches: list<array{id: string, label: string, description: string, matchedLabel: string}>, nextOffset: int, hasMore: bool}
     */
    public function personMatchesPage(
        string $query,
        string $name,
        string $scriptQid,
        string $languageCode = 'en',
        ?string $nameItemId = null,
        ?string $nameType = null,
        int $limit = 12,
        int $offset = 0,
        array $excludedIds = [],
        array $equivalents = [],
        array $sameAs = [],
    ): array
    {
        $query = trim($query);
        $name = trim($name);
        if ($query === '' || $name === '') {
            return ['matches' => [], 'nextOffset' => $offset, 'hasMore' => false];
        }

        $scriptLanguages = $this->languagesForScript($scriptQid);
        $searchQuery = $query . ' haswbstatement:P31=' . self::HUMAN;
        if (is_string($nameItemId) && preg_match('/^Q\d+$/', $nameItemId)) {
            $properties = $nameType === 'given'
                ? ['P735']
                : ($nameType === 'family' ? ['P734'] : []);
            foreach ($properties as $property) {
                $searchQuery .= ' -haswbstatement:' . $property . '=' . $nameItemId;
                foreach ($sameAs as $sameAsId) {
                    if (is_string($sameAsId) && preg_match('/^Q\d+$/', $sameAsId)) {
                        $searchQuery .= ' -haswbstatement:' . $property . '=' . $sameAsId;
                    }
                }
            }
            foreach ($equivalents as $equivalent) {
                $equivalentId = (string) ($equivalent['id'] ?? '');
                $equivalentType = (string) ($equivalent['type'] ?? '');
                if (!preg_match('/^Q\d+$/', $equivalentId)) {
                    continue;
                }
                $equivalentProperty = $equivalentType === 'given' ? 'P735' : ($equivalentType === 'family' ? 'P734' : '');
                if ($equivalentProperty !== '') {
                    $searchQuery .= ' -haswbstatement:' . $equivalentProperty . '=' . $equivalentId;
                    foreach ($equivalent['sameAs'] ?? [] as $sameAsId) {
                        if (is_string($sameAsId) && preg_match('/^Q\d+$/', $sameAsId)) {
                            $searchQuery .= ' -haswbstatement:' . $equivalentProperty . '=' . $sameAsId;
                        }
                    }
                }
            }
        }

        $searchData = $this->api([
            'action' => 'query',
            'format' => 'json',
            'list' => 'search',
            'srsearch' => $searchQuery,
            'srnamespace' => 0,
            'srlimit' => max(1, min(50, $limit * 3)),
            'sroffset' => max(0, $offset),
            'srprop' => '',
        ]);
        $excluded = array_fill_keys(array_values(array_filter($excludedIds, static fn (mixed $id): bool => is_string($id) && preg_match('/^Q\d+$/', $id) === 1)), true);
        $ids = [];
        foreach ($searchData['query']['search'] ?? [] as $hit) {
            $id = (string) ($hit['title'] ?? '');
            if (preg_match('/^Q\d+$/', $id) && !isset($excluded[$id])) {
                $ids[$id] = $id;
            }
        }
        $nextOffset = (int) ($searchData['continue']['sroffset'] ?? ($offset + count($searchData['query']['search'] ?? [])));
        $hasMore = isset($searchData['continue']['sroffset']);
        if (!$ids) {
            return ['matches' => [], 'nextOffset' => $nextOffset, 'hasMore' => $hasMore];
        }

        $data = $this->api([
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => implode('|', array_values($ids)),
            'props' => 'labels|descriptions|claims',
            'languages' => implode('|', array_values(array_unique(array_filter(['mul', 'en', $languageCode, ...$scriptLanguages])))),
            'languagefallback' => 1,
        ]);

        $matches = [];
        foreach ($ids as $id) {
            $entity = $data['entities'][$id] ?? [];
            $isHuman = false;
            foreach ($entity['claims']['P31'] ?? [] as $claim) {
                if (($claim['mainsnak']['datavalue']['value']['id'] ?? '') === self::HUMAN) {
                    $isHuman = true;
                    break;
                }
            }
            if (!$isHuman) {
                continue;
            }

            $matchedLabel = '';
            foreach ($entity['labels'] ?? [] as $labelLanguage => $label) {
                $value = is_array($label) ? (string) ($label['value'] ?? '') : '';
                if (
                    $value === ''
                    || !$this->labelCanMatch((string) $labelLanguage, $value, $scriptQid)
                    || !$this->containsName($value, $name)
                    || $this->hasLeadingNameAffix($value, $name)
                ) {
                    continue;
                }
                $matchedLabel = $value;
                break;
            }
            if ($matchedLabel === '') {
                continue;
            }

            $displayLabel = (string) ($entity['labels'][$languageCode]['value']
                ?? $entity['labels']['mul']['value']
                ?? $entity['labels']['en']['value']
                ?? $matchedLabel);
            $description = (string) ($entity['descriptions'][$languageCode]['value']
                ?? $entity['descriptions']['en']['value']
                ?? '');
            $matches[] = [
                'id' => $id,
                'label' => $displayLabel,
                'description' => $description,
                'matchedLabel' => $matchedLabel,
            ];
            if (count($matches) >= $limit) {
                break;
            }
        }

        return ['matches' => $matches, 'nextOffset' => $nextOffset, 'hasMore' => $hasMore];
    }

    /**
     * @return list<string>
     */
    private function languagesForScript(string $scriptQid): array
    {
        return [
            'Q8209' => ['ru', 'uk', 'be', 'bg', 'sr', 'mk'],
            'Q8196' => ['ar'],
            'Q33513' => ['he'],
            'Q8222' => ['ko'],
            'Q48332' => ['ja'],
            'Q82946' => ['ja'],
            'Q38592' => ['hi'],
            'Q8216' => ['el'],
            'Q8301' => ['ka'],
            'Q8221' => ['hy'],
            'Q8201' => ['zh', 'ja'],
        ][$scriptQid] ?? [];
    }

    private function labelMatchesScript(string $label, string $scriptQid): bool
    {
        $scriptProperty = [
            'Q8229' => 'Latin',
            'Q8209' => 'Cyrillic',
            'Q8196' => 'Arabic',
            'Q33513' => 'Hebrew',
            'Q8222' => 'Hangul',
            'Q48332' => 'Hiragana',
            'Q82946' => 'Katakana',
            'Q38592' => 'Devanagari',
            'Q8216' => 'Greek',
            'Q8301' => 'Georgian',
            'Q8221' => 'Armenian',
            'Q8201' => 'Han',
        ][$scriptQid] ?? null;
        if ($scriptProperty === null) {
            return true;
        }

        return preg_match('/\p{' . $scriptProperty . '}/u', $label) === 1;
    }

    private function labelCanMatch(string $language, string $label, string $scriptQid): bool
    {
        if ($language === 'mul' && $scriptQid !== 'Q8229') {
            return false;
        }

        return $this->labelMatchesScript($label, $scriptQid);
    }

    private function containsName(string $label, string $name): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $label) === 1;
    }

    private function hasLeadingNameAffix(string $label, string $name): bool
    {
        $affixes = [];
        foreach (LanguageHeuristics::PREFIXES as $prefixes) {
            foreach ($prefixes as $prefix) {
                $normalized = trim(mb_strtolower($prefix));
                if ($normalized !== '') {
                    $affixes[$normalized] = $normalized;
                }
            }
        }
        if ($affixes === []) {
            return false;
        }

        usort($affixes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = implode('|', array_map(
            static fn (string $affix): string => preg_quote($affix, '/'),
            array_values($affixes)
        ));

        return preg_match(
            '/(?<![\p{L}\p{N}])(?:' . $pattern . ')(?:[\s-]+)' . preg_quote(trim($name), '/') . '(?![\p{L}\p{N}])/iu',
            $label
        ) === 1;
    }

    private function languageCode(string $qid): string
    {
        return [
            'Q1860' => 'en', 'Q7411' => 'nl', 'Q188' => 'de', 'Q8798' => 'uk',
            'Q7918' => 'bg', 'Q9299' => 'sr', 'Q9296' => 'mk', 'Q9091' => 'be',
            'Q150' => 'fr', 'Q1321' => 'es', 'Q652' => 'it', 'Q5146' => 'pt',
            'Q809' => 'pl', 'Q9056' => 'cs', 'Q9027' => 'sv', 'Q9035' => 'da',
            'Q9043' => 'no', 'Q294' => 'is', 'Q7850' => 'zh', 'Q9192' => 'cmn',
            'Q5287' => 'ja', 'Q9176' => 'ko', 'Q13955' => 'ar', 'Q8785' => 'hy',
            'Q8108' => 'ka', 'Q9129' => 'el', 'Q7737' => 'ru', 'Q9288' => 'he',
            'Q1568' => 'hi',
        ][$qid] ?? '';
    }

    /**
     * @return list<array{id: string, label: string, description: string}>
     */
    public function exactNameMatches(string $name, string $rootTypeQid, array $searchMatches = [], array $instances = [], bool $useSparqlFallback = true): array
    {
        if (trim($name) === '' || !preg_match('/^Q\d+$/', $rootTypeQid)) {
            return [];
        }

        $compatibleTypes = match ($rootTypeQid) {
            'Q101352' => ['Q101352', 'Q66480858', 'Q60558422'],
            'Q202444' => ['Q202444', 'Q12308941', 'Q11879590', 'Q3409032'],
            default => [$rootTypeQid],
        };
        $normalizedName = mb_strtolower(trim($name));
        $apiMatches = array_values(array_filter(
            $searchMatches,
            static fn (array $match): bool =>
                mb_strtolower(trim((string) ($match['label'] ?? ''))) === $normalizedName
                && array_intersect($compatibleTypes, $instances[(string) ($match['id'] ?? '')] ?? []) !== []
        ));
        if ($apiMatches !== []) {
            return $apiMatches;
        }

        $searchMatches = $this->searchItems($name, 'en', 50);
        $instances = $this->instanceOf(array_column($searchMatches, 'id'));
        $apiMatches = array_values(array_filter(
            $searchMatches,
            static fn (array $match): bool =>
                mb_strtolower(trim((string) ($match['label'] ?? ''))) === $normalizedName
                && array_intersect($compatibleTypes, $instances[(string) ($match['id'] ?? '')] ?? []) !== []
        ));
        if ($apiMatches !== []) {
            return $apiMatches;
        }
        if (!$useSparqlFallback) {
            return [];
        }

        $escapedName = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);
        $query = <<<SPARQL
SELECT ?item ?itemLabel WHERE {
  ?item rdfs:label ?label.
  FILTER(STR(?label) = "$escapedName")
  FILTER(LANG(?label) IN ("mul", "en", "nl", "de", "fr", "es"))
  ?item wdt:P31/wdt:P279* wd:$rootTypeQid.
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en,nl,de,fr,es,mul". }
}
LIMIT 10
SPARQL;

        $data = $this->sparql($query);
        $matches = [];
        foreach ($data['results']['bindings'] ?? [] as $binding) {
            $id = basename((string) ($binding['item']['value'] ?? ''));
            if (!preg_match('/^Q\d+$/', $id)) {
                continue;
            }
            $matches[$id] = [
                'id' => $id,
                'label' => (string) ($binding['itemLabel']['value'] ?? $name),
                'description' => '',
            ];
        }

        return array_values($matches);
    }

    /**
     * @param array<string, scalar> $params
     * @return array<string, mixed>
     */
    private function api(array $params, int $ttl = 3600): array
    {
        $url = 'https://www.wikidata.org/w/api.php?' . http_build_query($params);
        $context = stream_context_create([
            'http' => [
                'header' => "Accept: application/json\r\nUser-Agent: New-Name/0.1 (https://new-name.toolforge.org/)\r\n",
                'timeout' => 8,
            ],
        ]);

        $json = $this->cachedGet($url, $context, $ttl);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function sparql(string $query): array
    {
        $url = 'https://query.wikidata.org/sparql?' . http_build_query([
            'query' => $query,
            'format' => 'json',
        ]);
        $context = stream_context_create([
            'http' => [
                'header' => "Accept: application/sparql-results+json\r\nUser-Agent: New-Name/0.1 (https://new-name.toolforge.org/)\r\n",
                'timeout' => 6,
            ],
        ]);

        $json = $this->cachedGet($url, $context, 86400);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private function cachedGet(string $url, mixed $context, int $ttl): string|false
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'new-name-wikidata-cache';
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . sha1($url) . '.json';
        if (is_file($path) && time() - filemtime($path) < $ttl) {
            $cached = @file_get_contents($path);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $json = @file_get_contents($url, false, $context);
        if (is_string($json) && $json !== '') {
            @file_put_contents($path, $json, LOCK_EX);
        }

        return $json;
    }
}
