<?php

namespace App\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:refresh-data',
    description: 'Refresh local TSV caches for languages and name affixes from Wikidata Query Service.'
)]
final class RefreshDataCommand extends Command
{
    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dataDir = $this->kernel->getProjectDir() . '/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new RuntimeException('Could not create data directory: ' . $dataDir);
        }

        $this->writeTsv($dataDir . '/languages.tsv', $this->languagesQuery(), [
            'item',
            'code',
            'label_en',
            'label_fr',
            'label_de',
            'label_nl',
            'label_es',
            'description_en',
            'description_fr',
            'description_de',
            'description_nl',
            'description_es',
        ]);
        $output->writeln('<info>Updated data/languages.tsv</info>');

        $this->writeTsv($dataDir . '/affixes.tsv', $this->affixesQuery(), ['item', 'itemLabel', 'class', 'classLabel']);
        $output->writeln('<info>Updated data/affixes.tsv</info>');

        $this->writeTsv($dataDir . '/script-languages.tsv', $this->scriptLanguagesQuery(), [
            'script',
            'scriptLabel',
            'language',
            'code',
            'label_en',
        ]);
        $output->writeln('<info>Updated data/script-languages.tsv</info>');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $columns
     */
    private function writeTsv(string $path, string $sparql, array $columns): void
    {
        $rows = $this->sparql($sparql);
        $tmp = $path . '.tmp';
        $handle = fopen($tmp, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not write temporary file: ' . $tmp);
        }

        fwrite($handle, implode("\t", $columns) . "\n");
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->tsvValue($row[$column]['value'] ?? '');
            }
            fwrite($handle, implode("\t", $values) . "\n");
        }
        fclose($handle);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace file: ' . $path);
        }
    }

    /**
     * @return list<array<string, array{type: string, value: string}>>
     */
    private function sparql(string $query): array
    {
        $url = 'https://query.wikidata.org/sparql?query=' . urlencode($query);
        $context = stream_context_create([
            'http' => [
                'header' => implode("\r\n", [
                    'Accept: application/sparql-results+json',
                    'User-Agent: New-Name/0.1 data refresh (https://new-name.toolforge.org/)',
                ]) . "\r\n",
                'timeout' => 180,
            ],
        ]);

        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            throw new RuntimeException('SPARQL request failed.');
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('SPARQL returned invalid JSON.');
        }

        return $data['results']['bindings'] ?? [];
    }

    private function tsvValue(string $value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', $value);
    }

    private function languagesQuery(): string
    {
        return <<<'SPARQL'
SELECT DISTINCT ?item
       (COALESCE(?wikimediaCode, ?iso6391, ?iso6393, ?iso6392b, ?iso6392t) AS ?code)
       ?label_en
       ?label_fr
       ?label_de
       ?label_nl
       ?label_es
       ?description_en
       ?description_fr
       ?description_de
       ?description_nl
       ?description_es
WHERE {
  ?item (wdt:P218|wdt:P219|wdt:P220|wdt:P305|wdt:P424) ?languageCode.
  ?item rdfs:label ?label_en FILTER(LANG(?label_en) = "en")
  ?item wdt:P31/wdt:P279* wd:Q34770.
  FILTER(?item != wd:Q34228)
  FILTER(!STRSTARTS(LCASE(STR(?languageCode)), "sgn"))
  MINUS { ?item wdt:P31/wdt:P279* wd:Q34228. }
  MINUS { ?item wdt:P279* wd:Q34228. }

  OPTIONAL { ?item wdt:P424 ?wikimediaCode. }
  OPTIONAL { ?item wdt:P218 ?iso6391. }
  OPTIONAL { ?item wdt:P220 ?iso6393. }
  OPTIONAL { ?item wdt:P219 ?iso6392b. }
  OPTIONAL { ?item wdt:P305 ?iso6392t. }

  OPTIONAL { ?item rdfs:label ?label_fr FILTER(LANG(?label_fr) = "fr") }
  OPTIONAL { ?item rdfs:label ?label_de FILTER(LANG(?label_de) = "de") }
  OPTIONAL { ?item rdfs:label ?label_nl FILTER(LANG(?label_nl) = "nl") }
  OPTIONAL { ?item rdfs:label ?label_es FILTER(LANG(?label_es) = "es") }
  OPTIONAL { ?item schema:description ?description_en FILTER(LANG(?description_en) = "en") }
  OPTIONAL { ?item schema:description ?description_fr FILTER(LANG(?description_fr) = "fr") }
  OPTIONAL { ?item schema:description ?description_de FILTER(LANG(?description_de) = "de") }
  OPTIONAL { ?item schema:description ?description_nl FILTER(LANG(?description_nl) = "nl") }
  OPTIONAL { ?item schema:description ?description_es FILTER(LANG(?description_es) = "es") }
}
ORDER BY ?label_en
SPARQL;
    }

    private function affixesQuery(): string
    {
        return <<<'SPARQL'
SELECT ?item ?itemLabel ?class ?classLabel WHERE {
  VALUES ?class {
    wd:Q62155
    wd:Q23585486
    wd:Q2620828
  }

  ?item wdt:P31 ?class.

  SERVICE wikibase:label { bd:serviceParam wikibase:language "nl,en". }
}
SPARQL;
    }

    private function scriptLanguagesQuery(): string
    {
        return <<<'SPARQL'
SELECT DISTINCT ?script ?scriptLabel ?language
       (COALESCE(?wikimediaCode, ?iso6391, ?iso6393, ?iso6392b, ?iso6392t) AS ?code)
       ?label_en
WHERE {
  ?language (wdt:P218|wdt:P219|wdt:P220|wdt:P305|wdt:P424) ?languageCode.
  ?language rdfs:label ?label_en FILTER(LANG(?label_en) = "en")
  ?language wdt:P31/wdt:P279* wd:Q34770.
  ?language wdt:P282 ?script.
  FILTER(?language != wd:Q34228)
  FILTER(!STRSTARTS(LCASE(STR(?languageCode)), "sgn"))
  MINUS { ?language wdt:P31/wdt:P279* wd:Q34228. }
  MINUS { ?language wdt:P279* wd:Q34228. }

  OPTIONAL { ?language wdt:P424 ?wikimediaCode. }
  OPTIONAL { ?language wdt:P218 ?iso6391. }
  OPTIONAL { ?language wdt:P220 ?iso6393. }
  OPTIONAL { ?language wdt:P219 ?iso6392b. }
  OPTIONAL { ?language wdt:P305 ?iso6392t. }

  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
ORDER BY ?scriptLabel ?label_en
SPARQL;
    }
}
