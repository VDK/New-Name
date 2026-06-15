<?php

namespace App\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:refresh-data',
    description: 'Refresh local TSV and gadget caches from Wikidata.'
)]
final class RefreshDataCommand extends Command
{
    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('only-gadgets', null, InputOption::VALUE_NONE, 'Refresh only bundled Wikidata gadget sources.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dataDir = $this->kernel->getProjectDir() . '/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new RuntimeException('Could not create data directory: ' . $dataDir);
        }

        if (!$input->getOption('only-gadgets')) {
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
        }

        $this->writeRemoteFile(
            $dataDir . '/transliteration-gadget.js',
            'https://www.wikidata.org/w/index.php?title=MediaWiki:Gadget-SimpleTransliterate.js&action=raw',
            'window.transliterateTool'
        );
        $output->writeln('<info>Updated data/transliteration-gadget.js</info>');

        // Keep descriptions editor-friendly without breaking single-quoted JavaScript strings.
        $this->writeRemoteFile(
            $dataDir . '/autoedit-descriptions.js',
            'https://www.wikidata.org/w/index.php?title=MediaWiki:Gadget-autoEdit.js&action=raw',
            'window.desclist',
            static fn (string $source): string => str_replace("\u{2019}", "\\'", $source)
        );
        $output->writeln('<info>Updated data/autoedit-descriptions.js</info>');

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

    private function writeRemoteFile(string $path, string $url, string $mustContain, ?callable $normalize = null): void
    {
        $source = $this->httpGet($url);
        if (!str_contains($source, $mustContain)) {
            throw new RuntimeException('Remote gadget did not contain expected marker: ' . $mustContain);
        }
        if ($normalize !== null) {
            $source = $normalize($source);
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $source) === false) {
            throw new RuntimeException('Could not write temporary file: ' . $tmp);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace file: ' . $path);
        }
    }

    private function httpGet(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'Accept: text/javascript, text/plain, */*',
                    'User-Agent: New-Name/0.1 data refresh (https://new-name.toolforge.org/)',
                ]) . "\r\n",
                'ignore_errors' => true,
                'timeout' => 60,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $status = $this->httpStatus($http_response_header ?? []);
            throw new RuntimeException('HTTP request failed' . ($status !== '' ? ': ' . $status : '') . '.');
        }

        $status = $this->httpStatus($http_response_header ?? []);
        if ($status !== '' && !str_contains($status, ' 2')) {
            throw new RuntimeException('HTTP request failed: ' . $status . ' ' . substr(trim($body), 0, 500));
        }

        return $body;
    }

    /**
     * @return list<array<string, array{type: string, value: string}>>
     */
    private function sparql(string $query): array
    {
        $body = http_build_query([
            'query' => $query,
            'format' => 'json',
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Accept: application/sparql-results+json',
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: New-Name/0.1 data refresh (https://new-name.toolforge.org/)',
                ]) . "\r\n",
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 180,
            ],
        ]);

        $json = @file_get_contents('https://query.wikidata.org/sparql', false, $context);
        if ($json === false) {
            $status = $this->httpStatus($http_response_header ?? []);
            throw new RuntimeException('SPARQL request failed' . ($status !== '' ? ': ' . $status : '') . '.');
        }

        $status = $this->httpStatus($http_response_header ?? []);
        if ($status !== '' && !str_contains($status, ' 2')) {
            throw new RuntimeException('SPARQL request failed: ' . $status . ' ' . substr(trim($json), 0, 500));
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('SPARQL returned invalid JSON.');
        }

        return $data['results']['bindings'] ?? [];
    }

    /**
     * @param list<string> $headers
     */
    private function httpStatus(array $headers): string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, 'HTTP/')) {
                return $header;
            }
        }

        return '';
    }

    private function tsvValue(string $value): string
    {
        return str_replace(["\t", "\r", "\n"], ' ', $value);
    }

    private function languagesQuery(): string
    {
        return <<<'SPARQL'
SELECT DISTINCT ?item
       ?code
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
  ?item wdt:P424 ?code.
  ?item rdfs:label ?label_en FILTER(LANG(?label_en) = "en")
  FILTER(?item != wd:Q34228)
  FILTER(!STRSTARTS(LCASE(STR(?code)), "sgn"))

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
       ?code
       ?label_en
WHERE {
  ?language wdt:P424 ?code.
  ?language rdfs:label ?label_en FILTER(LANG(?label_en) = "en")
  ?language wdt:P282 ?script.
  FILTER(?language != wd:Q34228)
  FILTER(!STRSTARTS(LCASE(STR(?code)), "sgn"))

  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
ORDER BY ?scriptLabel ?label_en
SPARQL;
    }
}
