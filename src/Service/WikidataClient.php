<?php

namespace App\Service;

final class WikidataClient
{
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
     * @return list<array{id: string, label: string, description: string}>
     */
    public function exactNameMatches(string $name, string $rootTypeQid): array
    {
        if (trim($name) === '' || !preg_match('/^Q\d+$/', $rootTypeQid)) {
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
    private function api(array $params): array
    {
        $url = 'https://www.wikidata.org/w/api.php?' . http_build_query($params);
        $context = stream_context_create([
            'http' => [
                'header' => "Accept: application/json\r\nUser-Agent: New-Name/0.1 (https://new-name.toolforge.org/)\r\n",
                'timeout' => 8,
            ],
        ]);

        $json = $this->cachedGet($url, $context, 3600);
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
